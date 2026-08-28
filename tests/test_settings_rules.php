<?php

/**
 * ทดสอบ "กฎการยืม-คืนที่ลูกค้าแก้เองได้" (ROADMAP ข้อ 0)
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. ตรรกะ 3 ชั้น — settings → .env → default (เรียก resolveRuleValue() ตรง ๆ)
 * B. ทะเบียนกฎใน ruleDefinitions() ไม่ขัดกันเอง
 * C. ฟอร์มในหน้า "ตั้งค่าระบบ" ผ่าน HTTP จริง — บันทึกได้ ค่าผิดถูกปฏิเสธ
 *    และ **เปลี่ยนแล้วมีผลกับค่าปรับที่ระบบคิดจริง** (ไม่ใช่แค่บันทึกผ่าน)
 * D. กันการถอยหลังของกับดัก 2 อย่างที่เคยเกือบพลาด (ดู includes/rules.php)
 *
 * 🧹 คืนค่าที่ตั้งไว้เดิมทุกครั้งก่อนจบ — ไม่ทิ้งอะไรค้างในระบบ
 *
 * 📌 การใช้งาน: php tests/test_settings_rules.php [รหัสผ่าน admin]
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

$COOKIE = tempnam(sys_get_temp_dir(), 'bbrules');

function http(string $method, string $url, array $fields = []): array
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $COOKIE,
        CURLOPT_COOKIEFILE     => $COOKIE,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'code' => $code];
}

function csrf(string $html): string
{
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}

echo "\n╔════════════════════════════════════════════════════════════╗\n";
echo "║  กฎการยืม-คืนที่แก้จากหน้าเว็บได้ (ROADMAP ข้อ 0)          ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";

$pdo = getDB();

// 🧹 เก็บค่าเดิมไว้คืนตอนจบ
$originalRules = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'rule%'")
                     ->fetchAll(PDO::FETCH_KEY_PAIR);

// ============================================================
// A. ตรรกะ 3 ชั้น
// ============================================================
echo "\n── A. ลำดับค่า: settings → .env → default ──\n";

$rule = ['setting' => 'rule_test', 'env' => 'RULE_TEST_ENV', 'default' => 7, 'min' => 1, 'max' => 30];

// 📝 คุม env() ชั่วคราวโดยแก้ตัวแปร global ที่ env() อ่าน
$envBackup = $GLOBALS['env'] ?? [];
$GLOBALS['env']['RULE_TEST_ENV'] = '9';

check('RULE-A1', resolveRuleValue($rule, ['rule_test' => '15']) === 15,
    'ค่าในตาราง settings ชนะ .env (15)', 'ค่าในตารางไม่ถูกใช้');

check('RULE-A2', resolveRuleValue($rule, ['rule_test' => '-5']) === 9,
    'ค่าติดลบถูกทิ้ง → ตกไปใช้ .env (9)', 'ค่าติดลบไม่ถูกทิ้ง');

check('RULE-A3', resolveRuleValue($rule, ['rule_test' => 'เจ็ด']) === 9,
    'ค่าที่ไม่ใช่ตัวเลขถูกทิ้ง → .env (9)', 'ค่าตัวอักษรไม่ถูกทิ้ง');

check('RULE-A4', resolveRuleValue($rule, ['rule_test' => '9999']) === 9,
    'ค่าเกิน max ถูกทิ้ง → .env (9)', 'ค่าเกินช่วงไม่ถูกทิ้ง');

check('RULE-A5', resolveRuleValue($rule, ['rule_test' => '2.5']) === 9,
    'ค่าทศนิยมถูกทิ้ง → .env (9)', 'ค่าทศนิยมไม่ถูกทิ้ง');

check('RULE-A6', resolveRuleValue($rule, []) === 9,
    'ไม่มีค่าในตาราง → ใช้ .env (9)', 'ไม่ตกไปใช้ .env');

unset($GLOBALS['env']['RULE_TEST_ENV']);
check('RULE-A7', resolveRuleValue($rule, []) === 7,
    'ไม่มีทั้งตารางและ .env → ใช้ default (7)', 'ไม่ตกไปใช้ default');

// 🧠 ค่าปรับ 0 = "ห้องสมุดนี้ไม่คิดค่าปรับ" เป็นค่าที่ใช้ได้จริง ไม่ใช่ค่าว่าง
$zeroRule = ['setting' => 'rule_zero', 'env' => 'X', 'default' => 10, 'min' => 0, 'max' => 100];
check('RULE-A8', resolveRuleValue($zeroRule, ['rule_zero' => '0']) === 0,
    'ค่าปรับ 0 ถูกยอมรับ (ไม่คิดค่าปรับ)', 'ค่า 0 ถูกทิ้งทั้งที่อยู่ในช่วง');

$GLOBALS['env'] = $envBackup;

// ============================================================
// B. ทะเบียนกฎไม่ขัดกันเอง
// ============================================================
echo "\n── B. ทะเบียนกฎ (ruleDefinitions) ──\n";

$defs = ruleDefinitions();
check('RULE-B1', count($defs) > 0, count($defs) . ' กฎในทะเบียน', 'ทะเบียนว่าง');

$allDefined = true; $missing = [];
foreach ($defs as $constant => $r) {
    if (!defined($constant)) { $allDefined = false; $missing[] = $constant; }
}
check('RULE-B2', $allDefined, 'ทุกกฎในทะเบียนถูก define เป็น constant แล้ว',
    'ไม่ได้ define: ' . implode(', ', $missing));

$rangeOk = true; $bad = [];
foreach ($defs as $constant => $r) {
    if ($r['min'] > $r['default'] || $r['default'] > $r['max']) { $rangeOk = false; $bad[] = $constant; }
}
check('RULE-B3', $rangeOk, 'ค่า default ของทุกกฎอยู่ในช่วง min–max',
    'default อยู่นอกช่วง: ' . implode(', ', $bad));

// 🧠 loadRuleOverrides() กรองด้วย prefix "rule_" — ถ้าตั้ง key ผิด ค่าจะไม่ถูกอ่านแบบเงียบ ๆ
$prefixOk = true; $badKey = [];
foreach ($defs as $constant => $r) {
    if (!str_starts_with($r['setting'], 'rule_')) { $prefixOk = false; $badKey[] = $r['setting']; }
}
check('RULE-B4', $prefixOk, 'ทุก setting key ขึ้นต้นด้วย rule_ (ไม่งั้นจะไม่ถูกอ่าน)',
    'key ผิดรูปแบบ: ' . implode(', ', $badKey));

// ============================================================
// C. ฟอร์มในหน้าตั้งค่าระบบ (HTTP จริง)
// ============================================================
echo "\n── C. ฟอร์มในหน้าตั้งค่าระบบ ──\n";

$login = http('GET', "$BASE_URL/login.php");
$res = http('POST', "$BASE_URL/login.php", [
    'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD, 'csrf_token' => csrf($login['body']),
]);

$page = http('GET', "$BASE_URL/admin/settings.php");
if ($page['code'] !== 200 || !str_contains($page['body'], 'กฎการยืม-คืน')) {
    fail('RULE-C0', 'เปิดหน้าตั้งค่าระบบไม่ได้ (HTTP ' . $page['code'] . ') — ข้ามหมวด C');
} else {
    pass('RULE-C0', 'เปิดหน้าตั้งค่าระบบได้ และเห็นหมวด "กฎการยืม-คืน"');

    $fieldsOk = true; $missingField = [];
    foreach ($defs as $r) {
        if (!str_contains($page['body'], 'name="' . $r['setting'] . '"')) {
            $fieldsOk = false; $missingField[] = $r['setting'];
        }
    }
    check('RULE-C1', $fieldsOk, 'ฟอร์มมีช่องกรอกครบทุกกฎในทะเบียน',
        'ขาดช่อง: ' . implode(', ', $missingField));

    /** ส่งฟอร์มกฎ แล้วคืนหน้าที่ผู้ใช้เห็นหลังบันทึก */
    $submit = function (array $values) use ($BASE_URL): string {
        $form = http('GET', "$BASE_URL/admin/settings.php");
        $post = ['csrf_token' => csrf($form['body']), 'form' => 'rules'] + $values;
        // 🧠 หน้านี้ใช้ PRG — POST แล้ว redirect ทันที
        //    curl ตั้ง FOLLOWLOCATION ไว้ จึงตามไปถึงหน้าปลายทางแล้วในคำสั่งเดียว
        //    ⚠️ ห้าม GET ซ้ำอีกรอบเพื่อหา flash เพราะ flash ถูกใช้ไปตั้งแต่ที่ตามไปแล้ว
        return http('POST', "$BASE_URL/admin/settings.php", $post)['body'];
    };

    $valid = [];
    foreach ($defs as $constant => $r) $valid[$r['setting']] = (string) constant($constant);

    $body = $submit($valid);
    check('RULE-C2', str_contains($body, 'บันทึกกฎการยืม-คืนเรียบร้อยแล้ว'),
        'บันทึกค่าที่ถูกต้องได้', 'บันทึกค่าที่ถูกต้องไม่ผ่าน');

    // ค่าผิดแต่ละแบบ — ต้องถูกปฏิเสธ และต้องไม่เขียนลงฐานข้อมูล
    $firstKey = array_key_first($defs);
    $firstRule = $defs[$firstKey];
    $before = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = "
        . $pdo->quote($firstRule['setting']))->fetchColumn();

    foreach ([
        ['ค่าติดลบ',   '-1'],
        ['ตัวอักษร',   'abc'],
        ['ทศนิยม',     '1.5'],
        ['เกินช่วง',   (string) ($firstRule['max'] + 1)],
        ['เว้นว่าง',   ''],
    ] as $i => [$label, $badValue]) {
        $body = $submit(array_merge($valid, [$firstRule['setting'] => $badValue]));
        $rejected = !str_contains($body, 'บันทึกกฎการยืม-คืนเรียบร้อยแล้ว');
        $after = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = "
            . $pdo->quote($firstRule['setting']))->fetchColumn();
        check('RULE-C3.' . ($i + 1), $rejected && $after === $before,
            "$label ถูกปฏิเสธ และค่าเดิมไม่ถูกแก้", "$label ผ่านเข้าไปได้ หรือค่าถูกเขียนทับ");
    }

    // 🎯 เคสสำคัญที่สุด: เปลี่ยนค่าปรับแล้วต้องมีผลกับตัวเลขที่ระบบคิดจริง
    //    ไม่ใช่แค่ "บันทึกผ่าน" — เคยพลาดมาแล้วตรงที่ป้ายบนปุ่มยังฝังเลขเดิมไว้
    $overdueCount = (int) $pdo->query("
        SELECT COUNT(*) FROM borrows WHERE status = 'borrowing' AND due_date < CURDATE()")->fetchColumn();

    if ($overdueCount === 0) {
        echo "  \033[33m⏭ RULE-C4: ข้าม — ไม่มีรายการเกินกำหนดให้ตรวจ\033[0m\n";
    } else {
        // 🧠 ไม่เจาะจงว่าต้องเจอเลขไหน เพราะหน้ายืม-คืนแสดงแค่ 20 แถวแรก
        //    และเรียงตามเวลาบันทึก ไม่ใช่ตามจำนวนวันที่เกิน — เลขที่เห็นจึงเดาไม่ได้
        //    แต่คุณสมบัติที่ต้องจริงเสมอคือ "ค่าปรับ = วันเกิน × เรต"
        //    → ค่าปรับทุกตัวบนหน้าต้องหารด้วยเรตลงตัว ใช้เรตเฉพาะ (7, 13) จะได้ไม่บังเอิญ
        foreach ([7, 13] as $fine) {
            $submit(array_merge($valid, [$defs['FINE_PER_DAY']['setting'] => (string) $fine]));
            $list = http('GET', "$BASE_URL/admin/borrows.php?filter=overdue")['body'];

            preg_match_all('/([\d,]+)\s*บาท/u', $list, $m);
            $amounts = array_values(array_filter(
                array_map(fn($x) => (int) str_replace(',', '', $x), $m[1]),
                fn($v) => $v > 0
            ));

            $allDivisible = $amounts !== [] && !array_filter($amounts, fn($v) => $v % $fine !== 0);
            check('RULE-C4-' . $fine, $allDivisible,
                "ตั้งค่าปรับ $fine บาท/วัน → ค่าปรับที่แสดงทั้ง " . count($amounts) . " ตัวหารด้วย $fine ลงตัว",
                "ตั้ง $fine บาท/วันแล้ว แต่มีค่าปรับที่หารไม่ลงตัว: "
                    . implode(', ', array_slice(array_filter($amounts, fn($v) => $v % $fine !== 0), 0, 5)));
        }
    }
}

// ============================================================
// D. กันการถอยหลังของกับดักที่เคยเกือบพลาด
// ============================================================
echo "\n── D. กันการถอยหลัง ──\n";

$rulesSrc = (string) file_get_contents(__DIR__ . '/../includes/rules.php');

// 🧠 ต้องตัดคอมเมนต์ออกก่อนตรวจ — ไม่งั้นจะไปจับคอมเมนต์ที่อธิบายกฎข้อนี้เอง
//    (ในไฟล์เขียนไว้ว่า "ไฟล์นี้ต้องไม่ require db.php เอง" ซึ่งไม่ใช่โค้ดที่ทำงานจริง)
$rulesCode = '';
foreach (token_get_all($rulesSrc) as $token) {
    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
    $rulesCode .= is_array($token) ? $token[1] : $token;
}

// 🧠 install.php โหลดแค่ config + functions ตอนที่ยังไม่มีฐานข้อมูล
//    ถ้า rules.php ไป require db.php หรือเรียก getDB() โดยไม่เช็คก่อน → ติดตั้งไม่ได้เลย
check('RULE-D1',
    !preg_match('/require(_once)?\s+.*db\.php/', $rulesCode)
    && str_contains($rulesCode, "function_exists('getDB')"),
    'ไม่ require db.php และเช็ค function_exists(getDB) ก่อนเสมอ (install.php ต้องไม่พัง)',
    'rules.php บังคับต่อฐานข้อมูล → install.php จะพัง');

// 🧠 getDB() เมื่อต่อไม่ได้จะ render หน้า 503 แล้วจบ ไม่ได้ throw
//    ส่วนการ query ตารางที่ยังไม่มี (ตอน migrate ครั้งแรก) จะ throw → ต้องมี catch
check('RULE-D2',
    preg_match('/catch\s*\(\s*\\\\?Throwable/', $rulesCode) === 1,
    'ครอบ try/catch ตอนอ่านตาราง settings (ตารางยังไม่มีก็ต้องไม่พัง)',
    'ไม่มี catch → ระบบพังตอนตารางยังไม่ถูกสร้าง');

// ============================================================
// CLEANUP — คืนค่าที่ตั้งไว้เดิม
// ============================================================
echo "\n── CLEANUP ──\n";
$pdo->exec("DELETE FROM settings WHERE setting_key LIKE 'rule%'");
if ($originalRules) {
    $ins = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($originalRules as $k => $v) $ins->execute([$k, $v]);
}
@unlink($COOKIE);
echo "  คืนค่ากฎเดิม " . count($originalRules) . " รายการ, ลบ cookie ชั่วคราว\n";

// ============================================================
// SUMMARY
// ============================================================
$pct = $results['total'] > 0 ? round($results['passed'] / $results['total'] * 100, 1) : 0;
echo "\n══════════════════════════════════════\n";
echo " RESULTS: {$results['passed']}/{$results['total']} passed ($pct%)";
if ($results['failed'] > 0) echo " | {$results['failed']} FAILED";
echo "\n══════════════════════════════════════\n\n";

exit($results['failed'] > 0 ? 1 : 0);
