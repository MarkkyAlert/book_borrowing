<?php

/**
 * ทดสอบหน้า "ลืมรหัสผ่าน" — F-40
 *
 * ==========================================================================
 * 🔴 ปัญหาเดิม
 * ==========================================================================
 * 1. โน้ตของนักพัฒนาหลุดถึงผู้ใช้ปลายทาง
 *    "หากอีเมลนี้มีในระบบ คุณจะได้รับลิงก์รีเซ็ตรหัสผ่าน (ต้องพัฒนาต่อให้ส่งทางเมล)"
 * 2. ไม่บอกทางออกที่ใช้ได้จริง — ระบบไม่มีการส่งอีเมล แต่ผู้ดูแลตั้งรหัสผ่านใหม่ให้ได้
 * 3. 🔴 **ช่องที่อันตรายที่สุดและ FINDINGS ไม่ได้เห็น** — ตัวจำกัด 3 ครั้ง/ชั่วโมงต่ออีเมล
 *    ทำงานเฉพาะกับอีเมลที่มีจริง กลายเป็นเครื่องมือไล่หาว่าใครเป็นสมาชิก:
 *        อีเมลจริง  ยิงครั้งที่ 4 → "ขอรีเซ็ตบ่อยเกินไป"
 *        อีเมลปลอม  ยิงกี่ครั้งก็ success
 *    ความพยายามกัน enumeration ที่ Service เขียนไว้ ถูกเปิดโปงที่จุดนี้จุดเดียว
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. 🔴 ชั้น Service — อีเมลจริงกับปลอมต้องได้ผลลัพธ์เหมือนกันทุกประการ
 * B. ข้อความบนหน้าจอ — ไม่มีโน้ตนักพัฒนา และบอกทางออกจริง
 * C. 🔴 ผ่าน HTTP — หน้าที่ผู้ใช้เห็นต้องเหมือนกันเป๊ะ (เทียบทีละตำแหน่ง)
 * D. ตัวกันอื่นยังทำงาน — CSRF · รูปแบบอีเมล · rate limit ระดับ IP
 * E. โหมดพัฒนาต้องไม่ทำงานบนเครื่องจริง
 *
 * 🧹 ล้าง password_resets และ rate_limits ที่เทสต์สร้างขึ้น
 *
 * 📌 การใช้งาน: php tests/test_forgot_password.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/AuthService.php';

$BASE_URL = rtrim(APP_URL, '/');

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
$COOKIE = tempnam(sys_get_temp_dir(), 'bbforgot');

const FAKE_EMAIL = 'zzz_notexist_f40@nowhere.invalid';

$cleanupDone = false;
$cleanup = function () use (&$cleanupDone, $pdo, $COOKIE) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    try {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // 🧹 ล้างเฉพาะของที่เทสต์นี้สร้าง — token ของผู้ใช้จริงต้องไม่ถูกแตะ
        $n = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
        $n->execute([FAKE_EMAIL]);
        $rows = $n->rowCount();
        // 🧠 rate_limits เก็บ key เป็น key_name = "<action>_<ip>" (ดู checkRateLimit())
        //    ล้างเฉพาะของหน้านี้ ไม่แตะ key ของหน้าอื่น
        $pdo->exec("DELETE FROM rate_limits WHERE key_name LIKE 'forgot_password%'");
        echo "  ลบ token ทดสอบ {$rows} แถว + ล้าง rate limit ของหน้าลืมรหัสผ่าน\n";
    } catch (Throwable $e) {
        echo '  ⚠️ ล้างข้อมูลไม่ครบ: ' . $e->getMessage() . "\n";
    }
    @unlink($COOKIE);
};
register_shutdown_function($cleanup);

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  หน้าลืมรหัสผ่าน (F-40)                                   ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$realEmail = (string) $pdo->query("SELECT email FROM users WHERE role = 'member' ORDER BY id LIMIT 1")->fetchColumn();
if ($realEmail === '') {
    fail('FORGOT-SETUP', 'ไม่มีสมาชิกในระบบให้ทดสอบ');
    exit(1);
}
echo "  📧 อีเมลที่มีจริง: {$realEmail}\n";
echo '  📧 อีเมลที่ไม่มี:  ' . FAKE_EMAIL . "\n\n";

/** ล้าง state ให้ทุกการยิงเริ่มจากจุดเดียวกัน */
$resetState = function () use ($pdo, $realEmail) {
    $pdo->prepare("DELETE FROM password_resets WHERE email IN (?, ?)")->execute([$realEmail, FAKE_EMAIL]);
    $pdo->exec("DELETE FROM rate_limits WHERE key_name LIKE 'forgot_password%'");
};

// ============================================================
// A. ชั้น Service
// ============================================================
echo "── A. ชั้น Service — ต้องแยกไม่ออกว่าอีเมลมีจริงหรือไม่ ──\n";

$svc = new App\Services\AuthService($pdo);
$resetState();

/** ยิง N ครั้ง แล้วเก็บ "รูปร่างของคำตอบ" (ไม่เอาค่า token ที่สุ่มมา) */
$probeService = function (string $email, int $times) use ($svc): array {
    $shapes = [];
    for ($i = 0; $i < $times; $i++) {
        $r = $svc->requestPasswordReset($email);
        // 🧠 เทียบเฉพาะ success + error — สองตัวนี้คือสิ่งที่หน้าเว็บใช้ตัดสินใจว่าจะแสดงอะไร
        //    ⚠️ ไม่เทียบ token เพราะอีเมลจริงย่อมได้ token ส่วนปลอมไม่ได้ — นั่นถูกต้องแล้ว
        //       token ไม่เคยถึงผู้ใช้ในเครื่องจริง (โหมดพัฒนาเท่านั้นที่แสดงลิงก์)
        //       สิ่งที่ต้องรับประกันคือ **หน้าเว็บต้องไม่แตกต่างตาม token** ซึ่งคุมด้วย
        //       FORGOT-A5 (ตรวจที่โค้ด) และ FORGOT-C1 (ตรวจที่ HTML จริง)
        $shapes[] = json_encode([
            'success' => $r['success'] ?? null,
            'error'   => $r['error'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
    }
    return $shapes;
};

$realShapes = $probeService($realEmail, 5);
$resetState();
$fakeShapes = $probeService(FAKE_EMAIL, 5);

$diffAt = [];
foreach ($realShapes as $i => $shape) {
    if ($shape !== ($fakeShapes[$i] ?? null)) {
        $diffAt[] = 'ครั้งที่ ' . ($i + 1) . ": จริง={$shape} ปลอม=" . ($fakeShapes[$i] ?? 'ไม่มี');
    }
}
check('FORGOT-A1', $diffAt === [],
    'ยิง 5 ครั้งด้วยอีเมลจริงกับปลอม ได้คำตอบรูปร่างเดียวกันทุกครั้ง',
    "🔴 คำตอบต่างกัน — ใช้ไล่หาว่าใครเป็นสมาชิกได้:\n       " . implode("\n       ", $diffAt));

// A2 — 🔴 เจาะจงที่ครั้งที่ 4 ซึ่งเป็นจุดที่โควตาต่ออีเมลเคยเปิดโปง
check('FORGOT-A2',
    ($realShapes[3] ?? '') === ($fakeShapes[3] ?? ''),
    'ครั้งที่ 4 (จุดที่โควตาต่ออีเมลเคยทำงาน) ให้คำตอบเหมือนกัน',
    '🔴 ครั้งที่ 4 ต่างกัน — โควตาต่ออีเมลกลับมาเป็น oracle อีกแล้ว');

// A3 — โควตายังทำงานอยู่จริง (ไม่ใช่ถอดทิ้งเพื่อให้เหมือนกัน)
$resetState();
$svc->requestPasswordReset($realEmail);
$svc->requestPasswordReset($realEmail);
$svc->requestPasswordReset($realEmail);
$fourth = $svc->requestPasswordReset($realEmail);
$tokenCount = (int) $pdo->query("SELECT COUNT(*) FROM password_resets WHERE email = " . $pdo->quote($realEmail))->fetchColumn();
check('FORGOT-A3',
    empty($fourth['token']) && $tokenCount === 3,
    "โควตายังทำงาน — ยิง 4 ครั้งได้ token จริงแค่ 3 (นับในฐานข้อมูล {$tokenCount} แถว)",
    "🔴 โควตาถูกถอดทิ้ง — มี {$tokenCount} token · การทำให้คำตอบเหมือนกันต้องไม่แลกกับการเลิกจำกัดอัตรา");

// A4 — อีเมลไม่มีจริงต้องไม่ได้ token ที่ใช้ได้
$resetState();
$r = $svc->requestPasswordReset(FAKE_EMAIL);
check('FORGOT-A4',
    empty($r['token']),
    'อีเมลที่ไม่มีในระบบไม่ได้ token กลับไป',
    '🔴 อีเมลปลอมได้ token — ใช้ตั้งรหัสผ่านให้บัญชีที่ไม่มีตัวตนได้');

// A5 — 🔴 ทุกการใช้ token ต้องอยู่ในบล็อกโหมดพัฒนาเท่านั้น
//     token เป็นตัวเดียวที่ยังต่างกันระหว่างอีเมลจริงกับปลอม
//     ถ้าวันหนึ่งมีใครเอาไปใช้ตัดสินว่าจะแสดงอะไรนอกบล็อกนั้น จะกลายเป็น oracle ทันที
//
// 🧠 ตรวจด้วยการนับวงเล็บปีกกา ไม่ใช่ regex บรรทัดเดียว
//    เพราะบรรทัดที่ประกอบ URL มี "?token=" อยู่ในสตริง ซึ่ง regex จะจับผิดว่าเป็นเงื่อนไข
$pageSrc   = (string) file_get_contents(__DIR__ . '/../forgot_password.php');
$srcLines  = explode("\n", $pageSrc);

$debugRange = null;   // [บรรทัดเริ่ม, บรรทัดจบ] ของบล็อก if (... APP_DEBUG ...)
$depth = 0;
foreach ($srcLines as $i => $line) {
    if ($debugRange === null) {
        if (preg_match('/^\s*if\s*\(.*APP_DEBUG/', $line)) {
            $debugRange = [$i, null];
            $depth = substr_count($line, '{') - substr_count($line, '}');
        }
        continue;
    }
    if ($debugRange[1] !== null) continue;
    $depth += substr_count($line, '{') - substr_count($line, '}');
    if ($depth <= 0) $debugRange[1] = $i;
}

$outside = [];
foreach ($srcLines as $i => $line) {
    $t = trim($line);
    if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*')) continue;
    if (!str_contains($t, "\$result['token']")) continue;
    $inDebug = $debugRange !== null && $debugRange[1] !== null
        && $i >= $debugRange[0] && $i <= $debugRange[1];
    if (!$inDebug) {
        $outside[] = 'บรรทัด ' . ($i + 1) . ': ' . mb_substr($t, 0, 55);
    }
}

check('FORGOT-A5',
    $debugRange !== null && $debugRange[1] !== null && $outside === [],
    'ทุกการใช้ token อยู่ในบล็อก APP_DEBUG (บรรทัด ' . (($debugRange[0] ?? -1) + 1) . '–' . (($debugRange[1] ?? -1) + 1) . ') เท่านั้น',
    $debugRange === null || $debugRange[1] === null
        ? '🔴 หาบล็อก APP_DEBUG ไม่เจอ — โครงสร้างไฟล์เปลี่ยนไป ต้องตรวจด้วยมือ'
        : "🔴 มีการใช้ token นอกบล็อกโหมดพัฒนา — จะทำให้หน้าต่างกันตามว่าอีเมลมีจริงไหม:\n       "
            . implode("\n       ", $outside));

// A6 — 🔴 อีเมลที่ไม่มีจริงต้องไม่ทิ้งแถวไว้ในตาราง
//     เคยลองสร้างแถวหลอกเพื่อถ่วงเวลา แต่ระบบนี้ไม่มี cron จริง แถวขยะจึงไม่มีวันหาย
//     คนนอกยิงอีเมลมั่ว ๆ วันละพันครั้ง = ตารางโตวันละพันแถว
$resetState();
for ($i = 0; $i < 5; $i++) {
    $svc->requestPasswordReset(FAKE_EMAIL);
}
$junkRows = (int) $pdo->query("SELECT COUNT(*) FROM password_resets WHERE email = " . $pdo->quote(FAKE_EMAIL))->fetchColumn();
check('FORGOT-A6', $junkRows === 0,
    'ยิงอีเมลที่ไม่มีจริง 5 ครั้ง ไม่ทิ้งแถวไว้ในตารางเลย',
    "🔴 เหลือแถวขยะ {$junkRows} แถว — ตารางจะโตตามอีเมลมั่ว ๆ ที่คนนอกยิงเข้ามา");

// A7 — token ที่หมดอายุแล้วต้องถูกล้างเอง (ระบบไม่มี cron)
$resetState();
$pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)")
    ->execute([FAKE_EMAIL, hash('sha256', 'expired_probe_f40'), date('Y-m-d H:i:s', strtotime('-2 hours'))]);
$before = (int) $pdo->query("SELECT COUNT(*) FROM password_resets WHERE email = " . $pdo->quote(FAKE_EMAIL))->fetchColumn();
$svc->requestPasswordReset($realEmail);   // ขอครั้งใหม่ → ต้องล้างของหมดอายุไปด้วย
$after = (int) $pdo->query("SELECT COUNT(*) FROM password_resets WHERE email = " . $pdo->quote(FAKE_EMAIL))->fetchColumn();
check('FORGOT-A7', $before === 1 && $after === 0,
    'token ที่หมดอายุถูกล้างอัตโนมัติเมื่อมีคนขอครั้งใหม่ (lazy cleanup)',
    "🔴 token หมดอายุยังค้าง ({$before} → {$after}) — ระบบไม่มี cron ตารางจะโตไปเรื่อย ๆ");

$resetState();

// ============================================================
// B–E. ผ่านหน้าเว็บจริง
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

/** ข้อความที่ผู้ใช้เห็นจริง — ตัดคอมเมนต์และ script ออกก่อน */
function visibleText(string $html): string
{
    $html = preg_replace('/<!--.*?-->/s', '', $html);
    $html = preg_replace('/<script.*?<\/script>/s', '', $html);
    return $html;
}

echo "\n── B. ข้อความบนหน้าจอ ──\n";

$resetState();
$page = http('GET', "$BASE_URL/forgot_password.php");
$res  = http('POST', "$BASE_URL/forgot_password.php", [
    'csrf_token' => csrfFrom($page['body']),
    'email' => $realEmail,
]);
$shown = visibleText($res['body']);

check('FORGOT-B1',
    !str_contains($shown, 'ต้องพัฒนาต่อ'),
    'ไม่มีโน้ตของนักพัฒนาหลุดถึงผู้ใช้แล้ว',
    '🔴 ยังมี "(ต้องพัฒนาต่อให้ส่งทางเมล)" บนหน้าจอ');

check('FORGOT-B2',
    str_contains($shown, 'ติดต่อเคาน์เตอร์') || str_contains($shown, 'เจ้าหน้าที่ห้องสมุดจะตั้งรหัสผ่านใหม่'),
    'บอกทางออกที่ใช้ได้จริง — ให้ติดต่อเจ้าหน้าที่ตั้งรหัสผ่านใหม่ให้',
    '🔴 ไม่บอกว่าผู้ใช้ต้องทำอะไรต่อ ทั้งที่ระบบไม่มีการส่งอีเมล');

// B3 — ข้อความต้องเป็นประโยคเงื่อนไข ไม่ยืนยันว่าอีเมลมีจริง
check('FORGOT-B3',
    str_contains($shown, 'หากอีเมลนี้มีอยู่ในระบบ'),
    'ข้อความเป็นประโยคเงื่อนไข ไม่ยืนยันว่าอีเมลนั้นมีบัญชีอยู่',
    '🔴 ข้อความยืนยันว่าอีเมลมีในระบบ');

// B4 — หัวฟอร์มต้องไม่สัญญาว่าจะส่งอีเมล ทั้งที่ส่งไม่ได้
$formPage = visibleText(http('GET', "$BASE_URL/forgot_password.php")['body']);
check('FORGOT-B4',
    !str_contains($formPage, 'เพื่อรับลิงก์รีเซ็ตรหัสผ่าน'),
    'หัวฟอร์มไม่สัญญาว่าจะส่งลิงก์ทางอีเมล (ระบบยังส่งอีเมลไม่ได้)',
    '🔴 ยังบอกว่า "เพื่อรับลิงก์รีเซ็ตรหัสผ่าน" — สัญญาสิ่งที่ทำไม่ได้');

echo "\n── C. หน้าที่ผู้ใช้เห็นต้องเหมือนกันเป๊ะ ──\n";

/**
 * ยิง 1 ครั้ง แล้วคืนลายเซ็นของกล่องข้อความที่ผู้ใช้เห็น
 *
 * 🧠 ล้างเฉพาะ rate limit ระดับ **IP** ไม่ล้างประวัติต่ออีเมล
 *    เพราะโควตาต่ออีเมลคือจุดที่เคยเป็น oracle — ต้องปล่อยให้มันสะสมถึงครั้งที่ 4
 *    (เคยเขียนให้ล้างทุกอย่างก่อนยิง ผลคือไม่เคยไปถึงครั้งที่ 4 เลย
 *     ทดสอบด้วยการใส่ oracle กลับเข้าไปแล้วเคสนี้ยังเขียว = ไม่มีฟัน)
 */
$probeHttp = function (string $email) use ($BASE_URL, $pdo): string {
    $pdo->exec("DELETE FROM rate_limits WHERE key_name LIKE 'forgot_password%'");
    $page = http('GET', "$BASE_URL/forgot_password.php");
    $res  = http('POST', "$BASE_URL/forgot_password.php", [
        'csrf_token' => csrfFrom($page['body']),
        'email' => $email,
    ]);
    $html = visibleText($res['body']);
    // ตัด CSRF token ที่เปลี่ยนทุกครั้งออกก่อนเทียบ
    $html = preg_replace('/name="csrf_token"\s+value="[^"]*"/', '', $html);
    return preg_match('/ลืมรหัสผ่าน.*?กลับไปหน้าเข้าสู่ระบบ/s', $html, $m)
        ? md5($m[0])
        : md5($html);
};

// 🧹 เริ่มจากศูนย์ครั้งเดียว แล้วปล่อยให้ประวัติต่ออีเมลสะสม
$resetState();
$mismatch = [];
for ($i = 1; $i <= 5; $i++) {
    $a = $probeHttp($realEmail);
    $b = $probeHttp(FAKE_EMAIL);
    if ($a !== $b) $mismatch[] = "ครั้งที่ {$i}";
}
check('FORGOT-C1', $mismatch === [],
    'ยิง 5 รอบสลับอีเมลจริง/ปลอม (ปล่อยให้โควตาต่ออีเมลสะสมจนเกิน) หน้าที่ได้เหมือนกันทุกครั้ง',
    '🔴 หน้าต่างกันที่ ' . implode(', ', $mismatch) . ' — ใช้แยกได้ว่าอีเมลไหนมีบัญชี');

$resetState();

echo "\n── D. ตัวกันอื่นยังทำงาน ──\n";

$resetState();
$noCsrf = http('POST', "$BASE_URL/forgot_password.php", ['email' => $realEmail]);
check('FORGOT-D1',
    str_contains(visibleText($noCsrf['body']), 'คำขอไม่ถูกต้อง'),
    'ไม่มี CSRF token → ถูกปฏิเสธ',
    '🔴 ส่งได้โดยไม่มี CSRF token');

$resetState();
$page = http('GET', "$BASE_URL/forgot_password.php");
$badEmail = http('POST', "$BASE_URL/forgot_password.php", [
    'csrf_token' => csrfFrom($page['body']),
    'email' => 'ไม่ใช่อีเมลเลย',
]);
check('FORGOT-D2',
    str_contains(visibleText($badEmail['body']), 'รูปแบบอีเมลไม่ถูกต้อง'),
    'รูปแบบอีเมลผิด → ถูกปฏิเสธ',
    'ไม่ตรวจรูปแบบอีเมล');

// D3 — rate limit ระดับ IP ยังทำงาน (ยิงรัวจากเครื่องเดียว)
$resetState();
$hitLimit = false;
for ($i = 0; $i < 8; $i++) {
    $p = http('GET', "$BASE_URL/forgot_password.php");
    $r = http('POST', "$BASE_URL/forgot_password.php", [
        'csrf_token' => csrfFrom($p['body']),
        'email' => FAKE_EMAIL,
    ]);
    if (str_contains(visibleText($r['body']), 'ลองหลายครั้งเกินไป')) { $hitLimit = true; break; }
}
check('FORGOT-D3', $hitLimit,
    'ยิงรัวจาก IP เดิม → ถูกจำกัดอัตรา (ยังกัน spam ได้)',
    '🔴 ยิงรัวได้ไม่จำกัด — ตัวจำกัดอัตราระดับ IP หายไป');

echo "\n── E. โหมดพัฒนา ──\n";

// E1 — 🔴 ลิงก์ตั้งรหัสผ่านต้องไม่โผล่เมื่อ APP_DEBUG=false
$resetState();
$page = http('GET', "$BASE_URL/forgot_password.php");
$res  = http('POST', "$BASE_URL/forgot_password.php", [
    'csrf_token' => csrfFrom($page['body']),
    'email' => $realEmail,
]);
$hasLink = str_contains($res['body'], 'reset_password.php?token=');
check('FORGOT-E1',
    APP_DEBUG ? true : !$hasLink,
    APP_DEBUG
        ? 'APP_DEBUG=true — ข้ามการตรวจ (ตั้งเป็น false ก่อนส่งมอบ)'
        : 'APP_DEBUG=false → ไม่มีลิงก์ตั้งรหัสผ่านโผล่บนหน้าจอ',
    '🔴 ลิงก์ตั้งรหัสผ่านโผล่ทั้งที่ APP_DEBUG=false — ใครก็ยึดบัญชีได้');

// E2 — เงื่อนไขในโค้ดต้องมีทั้ง APP_DEBUG และ localhost
$src = (string) file_get_contents(__DIR__ . '/../forgot_password.php');
check('FORGOT-E2',
    str_contains($src, 'APP_DEBUG') && str_contains($src, '127.0.0.1'),
    'โหมดพัฒนาถูกล็อกด้วย APP_DEBUG **และ** localhost พร้อมกัน',
    '🔴 เงื่อนไขไม่ครบ — ลิงก์อาจโผล่บนเครื่องจริง');

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
