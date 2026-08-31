<?php

/**
 * ส่งลิงก์รีเซ็ตรหัสผ่านทางอีเมล
 *
 * ==========================================================================
 * 🔴 ที่มา: โครงสร้างรีเซ็ตรหัสผ่านสร้างเสร็จมานานแล้ว **ขาดอย่างเดียวคือการส่ง**
 * ==========================================================================
 * token · หมดอายุ 1 ชม. · ใช้ครั้งเดียว · `reset_password.php` — ทำไว้ครบ
 * แต่เอา link ไปแสดงบนจอเฉพาะ `APP_DEBUG=true` เท่านั้น
 *
 * 🧠 ทำไมทำเฉพาะ "รีเซ็ตรหัสผ่าน" ไม่ทำ "แจ้งเตือนใกล้ครบกำหนด"
 *    - รีเซ็ต: ผู้ใช้กดเอง · ส่งทีละฉบับ · **ล้มเหลวแล้วรู้ทันทีเพราะเขายืนรออยู่**
 *    - แจ้งเตือน: ต้องใช้ cron ที่ลูกค้าตั้งไม่เป็น · ส่งทีละร้อย · **ล้มเหลวเงียบ**
 *      ใช้ "ใบรายชื่อโทรตาม" กับ "กระดิ่ง" แทน — ดู docs/LIMITATIONS.md
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. ปิดเป็นค่าเริ่มต้น — ลูกค้าที่ไม่ตั้งค่าใช้ระบบได้ครบเหมือนเดิม
 * B. ส่งได้จริง — ยิงผ่าน **SMTP ปลอมบน 127.0.0.1** แล้วตรวจเมลที่ได้รับ
 * C. 🔴 ห้ามบอกว่าอีเมลไหนมีในระบบ — ข้อความต้องเหมือนกันทุกกรณี
 * D. 🔴 ส่งไม่ผ่านต้องไม่ค้างหน้าเว็บ และไม่โกหกว่าส่งแล้ว
 *
 * 🧹 คืนค่าการตั้งค่าอีเมลกลับเป็นเดิมเสมอ
 *
 * 📌 การใช้งาน: php tests/test_mail_reset.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

$BASE_URL = rtrim(APP_URL, '/');
$results  = ['passed' => 0, 'failed' => 0, 'total' => 0];

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

$pdo       = getDB();
$uniq      = substr((string) getmypid(), -4) . mt_rand(100, 999);
$MAIL_KEYS = ['mail_enabled', 'mail_host', 'mail_port', 'mail_secure',
              'mail_username', 'mail_password', 'mail_from_email', 'mail_from_name'];

// 💾 จำค่าเดิมไว้คืนตอนจบ — ห้ามทิ้งการตั้งค่าอีเมลของลูกค้าไว้ในสภาพที่เทสต์ตั้ง
$savedMail = [];
foreach ($MAIL_KEYS as $k) {
    $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $st->execute([$k]);
    $v = $st->fetchColumn();
    if ($v !== false) $savedMail[$k] = $v;
}

$madeUsers   = [];
$smtpPid     = null;
$cleanupDone = false;

$cleanup = function () use ($MAIL_KEYS, &$savedMail, &$madeUsers, &$smtpPid, &$cleanupDone, $pdo) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    foreach ($MAIL_KEYS as $k) {
        try {
            $pdo->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([$k]);
            if (isset($savedMail[$k])) {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")
                    ->execute([$k, $savedMail[$k]]);
            }
        } catch (Throwable $e) {}
    }
    echo '  คืนการตั้งค่าอีเมลเดิม (' . count($savedMail) . " ค่า)\n";

    foreach ($madeUsers as $id) {
        try { $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([(int) $id]); } catch (Throwable $e) {}
    }
    try {
        $n = $pdo->exec("DELETE FROM users WHERE name LIKE '%[MAILTEST]%'");
        if ($n > 0) echo "  🧹 กวาดบัญชี [MAILTEST] อีก {$n} คน\n";
        // 🧹 token ที่เทสต์สร้าง + rate limit ที่เกิดจากการยิงถี่
        $pdo->exec("DELETE FROM password_resets WHERE email LIKE '%mailtest%'");
        $pdo->exec("DELETE FROM rate_limits WHERE key_name LIKE 'forgot_password_%'");
    } catch (Throwable $e) {}
    if ($smtpPid) { @exec("kill {$smtpPid} 2>/dev/null"); }
    echo '  ลบบัญชี ' . count($madeUsers) . " คน\n";
};
register_shutdown_function($cleanup);

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  ส่งลิงก์รีเซ็ตรหัสผ่านทางอีเมล                             ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

function setMail(PDO $pdo, array $kv): void
{
    foreach ($kv as $k => $v) {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([$k, (string) $v]);
    }
}

function http(string $method, string $url, array $fields = []): array
{
    static $jar = null;
    $jar = $jar ?? tempnam(sys_get_temp_dir(), 'mailt');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30,
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

/** ข้อความในกล่องผลลัพธ์ — ใช้เทียบว่าเหมือนกันทุกกรณีไหม */
function resultText(string $html): string
{
    $body = str_contains($html, '<main') ? explode('<main', $html, 2)[1] : $html;
    $text = preg_replace('/\s+/', ' ', strip_tags($body));
    $i = mb_strpos($text, 'รับคำขอแล้ว');
    return $i === false ? '' : trim(mb_substr($text, $i, 200));
}

/** 🧹 ล้าง rate limit ก่อนทุกครั้ง — เทสต์ยิงถี่กว่ามนุษย์ จะโดนกันเองถ้าไม่ล้าง */
function clearRateLimit(PDO $pdo): void
{
    try { $pdo->exec("DELETE FROM rate_limits WHERE key_name LIKE 'forgot_password_%'"); }
    catch (Throwable $e) {}
}

// 👤 สมาชิกทดสอบ — ใช้อีเมลที่ไม่มีทางส่งถึงจริง
$memberEmail = "mailtest{$uniq}@nowhere.invalid";
$pdo->prepare("INSERT INTO users (name, email, password, phone, role, must_change_password)
               VALUES (?, ?, ?, ?, 'member', 0)")
    ->execute(["[MAILTEST] สมาชิก {$uniq}", $memberEmail, hashPassword('MailTest#2026'), '0800000000']);
$madeUsers[] = (int) $pdo->lastInsertId();

// ============================================================
// A. ปิดเป็นค่าเริ่มต้น
// ============================================================
echo "── A. ปิดไว้ = ระบบทำงานเหมือนเดิม ──\n";

foreach ($MAIL_KEYS as $k) {
    $pdo->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([$k]);
}

// 🧠 mailSettings() cache ระดับโปรเซส — ต้องอ่านในโปรเซสใหม่ถึงจะเห็นค่าที่เพิ่งเปลี่ยน
$readEnabled = function () {
    $root = escapeshellarg(dirname(__DIR__));
    $code = 'require ' . $root . '."/includes/config.php"; require ' . $root
          . '."/includes/db.php"; require ' . $root . '."/includes/functions.php"; require ' . $root
          . '."/includes/mailer.php"; echo mailEnabled() ? "1" : "0";';
    return trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code))) === '1';
};

check('MAIL-A1', !$readEnabled(),
    'ไม่ได้ตั้งค่า = ปิดอยู่ (ลูกค้าที่ไม่ตั้งค่าไม่มีอะไรเปลี่ยน)',
    '🔴 เปิดอยู่ทั้งที่ยังไม่ได้ตั้งค่า');

// 🔴 เปิดสวิตช์แต่กรอกไม่ครบ = ต้องถือว่าปิด ไม่ใช่พยายามส่งแล้วค้าง
setMail($pdo, ['mail_enabled' => '1', 'mail_host' => '', 'mail_from_email' => '']);
check('MAIL-A2', !$readEnabled(),
    'เปิดสวิตช์แต่กรอกไม่ครบ → ถือว่าปิด (ดีกว่าพยายามส่งแล้วค้าง)',
    '🔴 ถือว่าเปิดทั้งที่ยังกรอกไม่ครบ');

clearRateLimit($pdo);
[$page] = http('GET', "{$BASE_URL}/forgot_password.php");
check('MAIL-A3', !str_contains($page, 'ระบบจะส่งลิงก์') && str_contains($page, 'เคาน์เตอร์'),
    'ปิดอยู่ → หน้าจอบอกให้ไปที่เคาน์เตอร์ ไม่สัญญาว่าจะส่งเมล',
    '🔴 หน้าจอสัญญาว่าจะส่งเมลทั้งที่ปิดอยู่');

// ============================================================
// B. ส่งได้จริง — ยิงผ่าน SMTP ปลอม
// ============================================================
echo "\n── B. ส่งจริงผ่าน SMTP ปลอมบน 127.0.0.1 ──\n";

/**
 * 🧠 ทำไมต้องมี SMTP ปลอม
 *    ทดสอบการส่งเมลจริงทำไม่ได้ในชุดทดสอบ (ต้องมีบัญชีจริง + เน็ต + ทำให้เทสต์ช้าและไม่แน่นอน)
 *    จึงเปิดเซิร์ฟเวอร์ SMTP จิ๋วบน 127.0.0.1 ที่พูด SMTP ได้พอรับ 1 ฉบับ
 *    แล้ว **ตรวจเมลที่ได้รับจริง ๆ** ว่าผู้รับ หัวเรื่อง และลิงก์ token ถูกต้อง
 */
$port    = 20000 + (getmypid() % 5000);
$outFile = sys_get_temp_dir() . "/mailtest_{$uniq}.json";
$sinkPhp = <<<'SINK'
<?php
$port = (int) $argv[1]; $out = $argv[2];
$srv = @stream_socket_server("tcp://127.0.0.1:$port", $e, $s);
if (!$srv) { file_put_contents($out, json_encode(['error' => "listen: $s"])); exit(1); }
file_put_contents($out . '.ready', 'ok');
// 🔴 รับหลายฉบับ — ข้อ B ส่ง 1 ฉบับ ข้อ C ส่งอีก ถ้ารับได้ฉบับเดียว
//    ข้อ C จะทดสอบตอนที่ "ส่งไม่ได้อยู่แล้ว" แล้วผ่านแบบไม่ได้ตรวจอะไร
$all = [];
for ($round = 0; $round < 4; $round++) {
$c = @stream_socket_accept($srv, 25);
if (!$c) break;
stream_set_timeout($c, 10);
$log = []; $inData = false; $msg = ''; $authStep = 0;
fwrite($c, "220 fake ESMTP\r\n");
while (($line = fgets($c, 2048)) !== false) {
    if ($inData) {
        if (rtrim($line, "\r\n") === '.') { $inData = false; fwrite($c, "250 OK queued\r\n"); continue; }
        $msg .= $line; continue;
    }
    $log[] = rtrim($line, "\r\n");
    $u = strtoupper(trim($line));
    if (str_starts_with($u, 'EHLO') || str_starts_with($u, 'HELO')) fwrite($c, "250-fake\r\n250 AUTH LOGIN\r\n");
    elseif (str_starts_with($u, 'AUTH LOGIN')) { fwrite($c, "334 VXNlcm5hbWU6\r\n"); $authStep = 1; }
    elseif ($authStep === 1) { fwrite($c, "334 UGFzc3dvcmQ6\r\n"); $authStep = 2; }
    elseif ($authStep === 2) { fwrite($c, "235 authenticated\r\n"); $authStep = 3; }
    elseif (str_starts_with($u, 'MAIL FROM')) fwrite($c, "250 OK\r\n");
    elseif (str_starts_with($u, 'RCPT TO'))   fwrite($c, "250 OK\r\n");
    elseif (str_starts_with($u, 'DATA'))      { fwrite($c, "354 go\r\n"); $inData = true; }
    elseif (str_starts_with($u, 'QUIT'))      { fwrite($c, "221 bye\r\n"); break; }
    else fwrite($c, "250 OK\r\n");
}
if ($msg !== '') $all[] = ['log' => $log, 'message' => $msg];
fclose($c);
file_put_contents($out, json_encode(['count' => count($all), 'all' => $all,
    'log' => $all[0]['log'] ?? [], 'message' => $all[0]['message'] ?? ''], JSON_UNESCAPED_UNICODE));
}
fclose($srv);
SINK;
$sinkFile = sys_get_temp_dir() . "/mailsink_{$uniq}.php";
file_put_contents($sinkFile, $sinkPhp);
@unlink($outFile); @unlink($outFile . '.ready');
$smtpPid = (int) shell_exec(sprintf('%s %s %d %s > /dev/null 2>&1 & echo $!',
    escapeshellarg(PHP_BINARY), escapeshellarg($sinkFile), $port, escapeshellarg($outFile)));
for ($i = 0; $i < 40 && !file_exists($outFile . '.ready'); $i++) usleep(100000);

check('MAIL-B0', file_exists($outFile . '.ready'),
    "เปิด SMTP ปลอมบนพอร์ต {$port} ได้",
    "🔴 เปิด SMTP ปลอมไม่ได้ — เทสต์ข้อ B ต่อจากนี้เชื่อไม่ได้");

setMail($pdo, [
    'mail_enabled' => '1', 'mail_host' => '127.0.0.1', 'mail_port' => (string) $port,
    'mail_secure' => 'none', 'mail_username' => '', 'mail_password' => '',
    'mail_from_email' => 'library@test.local', 'mail_from_name' => 'ห้องสมุดทดสอบ',
]);

clearRateLimit($pdo);
[$form] = http('GET', "{$BASE_URL}/forgot_password.php");
[$sentPage] = http('POST', "{$BASE_URL}/forgot_password.php",
    ['csrf_token' => csrfFrom($form), 'email' => $memberEmail]);
usleep(800000);

$received = file_exists($outFile) ? json_decode((string) file_get_contents($outFile), true) : null;
$rawMsg   = $received['message'] ?? '';

check('MAIL-B1', $rawMsg !== '' && str_contains($rawMsg, $memberEmail),
    'เซิร์ฟเวอร์ได้รับเมลถึงสมาชิกคนที่ขอ',
    '🔴 ไม่ได้รับเมล หรือผู้รับผิด: ' . mb_substr($rawMsg, 0, 120));

// 📧 หัวเรื่องภาษาไทยต้องเข้ารหัส RFC 2047 ไม่งั้นอ่านไม่ออกในโปรแกรมเมล
$subjOk = preg_match('/Subject: =\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=/', $rawMsg, $sm)
       && str_contains(base64_decode($sm[1]), 'ตั้งรหัสผ่านใหม่');
check('MAIL-B2', $subjOk,
    'หัวเรื่องภาษาไทยเข้ารหัสถูกต้อง (RFC 2047) — อ่านออกในโปรแกรมเมล',
    '🔴 หัวเรื่องไม่ได้เข้ารหัสหรือถอดแล้วไม่ตรง');

// 🔗 ลิงก์ในเมลต้องใช้งานได้จริง
$body = '';
if (str_contains($rawMsg, "\r\n\r\n")) {
    $body = base64_decode(preg_replace('/\s+/', '', explode("\r\n\r\n", $rawMsg, 2)[1]));
}
$hasLink = preg_match('#(' . preg_quote($BASE_URL, '#') . '/reset_password\.php\?token=\w+)#', $body, $lm);
check('MAIL-B3', (bool) $hasLink,
    'เนื้อเมลมีลิงก์ตั้งรหัสผ่านใหม่',
    '🔴 ไม่มีลิงก์ในเมล: ' . mb_substr($body, 0, 120));

if ($hasLink) {
    [$resetPage, $info] = http('GET', $lm[1]);
    check('MAIL-B4', ($info['http_code'] ?? 0) === 200 && str_contains($resetPage, 'name="password"'),
        'เปิดลิงก์จากในเมลแล้วได้ฟอร์มตั้งรหัสผ่านใหม่จริง',
        '🔴 ลิงก์ในเมลใช้ไม่ได้');
} else {
    fail('MAIL-B4', '🔴 ข้ามเพราะไม่มีลิงก์ให้ทดสอบ');
}

// ============================================================
// C. ห้ามบอกว่าอีเมลไหนมีในระบบ
// ============================================================
echo "\n── C. ห้ามกลายเป็นเครื่องมือไล่เดาสมาชิก ──\n";

/**
 * 🔴 [F-40] ถ้าข้อความต่างกันระหว่าง "มีบัญชี" กับ "ไม่มีบัญชี"
 *    ใครก็ยิงอีเมลทีละอันเพื่อดูว่าใครเป็นสมาชิกห้องสมุดได้
 *
 * 🧠 นี่คือเหตุผลที่ **จงใจไม่แสดงว่าส่งสำเร็จหรือไม่** บนหน้าจอ
 *    ส่งไม่ผ่าน = มีบัญชีแน่ ๆ · ไม่ขึ้นอะไร = ไม่มีบัญชี → รั่วทันที
 *    สาเหตุที่ส่งไม่ผ่านเข้า error_log ให้ผู้ดูแล และมีปุ่มทดสอบส่งในหน้าตั้งค่า
 */
clearRateLimit($pdo);
[$f1] = http('GET', "{$BASE_URL}/forgot_password.php");
[$known] = http('POST', "{$BASE_URL}/forgot_password.php",
    ['csrf_token' => csrfFrom($f1), 'email' => $memberEmail]);

clearRateLimit($pdo);
[$f2] = http('GET', "{$BASE_URL}/forgot_password.php");
[$unknown] = http('POST', "{$BASE_URL}/forgot_password.php",
    ['csrf_token' => csrfFrom($f2), 'email' => "nobody{$uniq}@nowhere.invalid"]);

usleep(800000);
// 🔴 ยืนยันว่ารอบนี้ **ส่งสำเร็จจริง** ไม่งั้น C1 เทียบสองกรณีที่ส่งไม่ได้ทั้งคู่
//    = เหมือนกันโดยบังเอิญ ผ่านแบบไม่ได้ตรวจอะไรเลย
$sink2 = file_exists($outFile) ? json_decode((string) file_get_contents($outFile), true) : null;
check('MAIL-C0', ($sink2['count'] ?? 0) >= 2,
    'ส่งเมลสำเร็จจริงในรอบนี้ (' . ($sink2['count'] ?? 0) . ' ฉบับ) — C1 จึงเทียบของจริง',
    '🔴 รอบนี้ส่งไม่สำเร็จ C1 จะเทียบสองกรณีที่ล้มเหมือนกัน = ไม่ได้ตรวจอะไร');

$tKnown   = resultText($known);
$tUnknown = resultText($unknown);
check('MAIL-C1', $tKnown !== '' && $tKnown === $tUnknown,
    'อีเมลที่มีและไม่มีในระบบ ได้ข้อความเหมือนกันเป๊ะ',
    "🔴 ข้อความต่างกัน:\n       มีบัญชี:   " . mb_substr($tKnown, 0, 90)
        . "\n       ไม่มีบัญชี: " . mb_substr($tUnknown, 0, 90));

check('MAIL-C2', !str_contains($known, 'ได้ส่งลิงก์') && !str_contains($known, 'ส่งอีเมลไม่สำเร็จ'),
    'ไม่ประกาศผลการส่งบนหน้าจอ (ทั้ง "ส่งแล้ว" และ "ส่งไม่สำเร็จ")',
    '🔴 ประกาศผลการส่ง = บอกกลาย ๆ ว่าอีเมลนี้มีบัญชีหรือไม่');

// ============================================================
// D. ส่งไม่ผ่าน
// ============================================================
echo "\n── D. ส่งไม่ผ่านต้องไม่ค้างและไม่โกหก ──\n";

// 🔴 ชี้ไปพอร์ตที่ไม่มีใครฟัง — SMTP ที่ตั้งค่าผิดต้องไม่ทำให้หน้าเว็บค้าง
setMail($pdo, ['mail_port' => '20099']);
clearRateLimit($pdo);
[$f3] = http('GET', "{$BASE_URL}/forgot_password.php");
$t0 = microtime(true);
[$failPage] = http('POST', "{$BASE_URL}/forgot_password.php",
    ['csrf_token' => csrfFrom($f3), 'email' => $memberEmail]);
$elapsed = microtime(true) - $t0;

/**
 * 🧠 เชื่อมต่อพอร์ตที่ปิดอยู่บนเครื่องเดียวกันจะถูกปฏิเสธ **ทันที** ไม่ว่าตั้ง timeout เท่าไร
 *    เคสนี้จึงพิสูจน์ได้แค่ว่า "ไม่ค้าง" ไม่ได้พิสูจน์ว่าค่า timeout เหมาะสม
 *    การทดสอบ timeout จริงต้องยิงไปที่ IP ที่กลืนแพ็กเก็ต ซึ่งกินเวลาเท่าค่า timeout ทุกครั้งที่รันชุด
 *    → แยกเป็น 2 เคส: D1 ดูว่าหน้าไม่ค้าง · D1b ดูค่าที่ตั้งไว้ในซอร์ส
 */
check('MAIL-D1', $elapsed < 15,
    sprintf('ส่งไม่ผ่านแล้วหน้าตอบกลับใน %.1f วินาที (เกณฑ์ < 15)', $elapsed),
    sprintf('🔴 ใช้ %.1f วินาที — ผู้ใช้ยืนรอหน้าค้าง', $elapsed));

$mailerSrc = (string) file_get_contents(dirname(__DIR__) . '/includes/mailer.php');
$timeoutOk = preg_match('/\$timeout\s*=\s*(\d+)\s*;/', $mailerSrc, $tm) && (int) $tm[1] <= 15;
check('MAIL-D1b', $timeoutOk,
    'ค่า timeout ในตัวส่งเมลตั้งไว้ ' . ($tm[1] ?? '?') . ' วินาที (ต้องไม่เกิน 15)',
    '🔴 timeout = ' . ($tm[1] ?? 'หาไม่เจอ') . ' — SMTP ที่ตั้งค่าผิดจะทำให้หน้าเว็บค้างนานเกินรับได้');

check('MAIL-D2', !str_contains($failPage, 'ได้ส่งลิงก์ตั้งรหัสผ่านใหม่ไปให้แล้ว'),
    'ส่งไม่ผ่านแล้วไม่บอกว่า "ส่งแล้ว"',
    '🔴 บอกว่าส่งแล้วทั้งที่ส่งไม่ผ่าน');

check('MAIL-D3', str_contains($failPage, 'เคาน์เตอร์'),
    'บอกทางสำรอง (ติดต่อเคาน์เตอร์) ไว้เสมอ ผู้ใช้ที่ไม่ได้รับเมลจึงไม่ตัน',
    '🔴 ไม่มีทางสำรองบนหน้าจอ');

// 📌 ตัวส่งเมลต้องคืน error ไม่ throw — ผู้เรียกต้องรู้ผลเพื่อบอกผู้ใช้ตามจริง
$res = sendMail('someone@example.com', 'x', 'y',
    ['host' => '127.0.0.1', 'port' => 20099, 'secure' => 'none',
     'username' => '', 'password' => '', 'from_email' => 'a@b.c', 'from_name' => 'x']);
check('MAIL-D4', $res['success'] === false && $res['error'] !== '',
    'sendMail() คืนสาเหตุกลับมา ไม่ throw และไม่กลืน error',
    '🔴 ไม่ได้คืนสาเหตุ: ' . json_encode($res, JSON_UNESCAPED_UNICODE));

// ============================================================
echo "\n══════════════════════════════════════\n";
printf(" RESULTS: %d/%d passed (%.1f%%)%s\n",
    $results['passed'], $results['total'],
    $results['total'] ? $results['passed'] / $results['total'] * 100 : 0,
    $results['failed'] ? ' | ' . $results['failed'] . ' FAILED' : '');
echo "══════════════════════════════════════\n";

@unlink($sinkFile); @unlink($outFile); @unlink($outFile . '.ready');
exit($results['failed'] > 0 ? 1 : 0);
