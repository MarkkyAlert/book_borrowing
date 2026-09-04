<?php

/**
 * อีเมลไม่บังคับ + สถานะ "เลิกใช้งาน" ของสมาชิก
 *
 * ==========================================================================
 * 🔴 ที่มา: UAT รอบ 2 ข้อ ฒ.2 และ ฒ.5-6
 * ==========================================================================
 * 1. อีเมลบังคับกรอก → สมาชิกที่ไม่มีอีเมลจริง (ผู้สูงอายุ เด็กเล็ก) สมัครไม่ได้เลย
 *    บรรณารักษ์ต้องกรอกอีเมลปลอมให้ ซึ่งพังตอนคนนั้นลืมรหัสผ่าน
 * 2. สมาชิกที่เคยยืมแม้ครั้งเดียว ลบไม่ได้ตลอดกาล (ประวัติต้องอยู่ — ถูกแล้ว)
 *    แต่ไม่มีทางทำเครื่องหมายว่า "ไม่มาแล้ว" → รายชื่อโตทางเดียว
 *
 * 🧠 หัวใจของหมวด B: ปิดใช้งานต้องปิด "เฉพาะการเข้าระบบและการยืมใหม่"
 *    ห้ามปิดการรับคืน ไม่งั้นหนังสือจะถูกขังอยู่กับคนที่ติดต่อไม่ได้
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../app/Services/MemberService.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/../app/Services/BorrowService.php';
require_once __DIR__ . '/../app/Repositories/UserRepository.php';

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  อีเมลไม่บังคับ + สถานะเลิกใช้งาน                          ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";

$results = ['passed' => 0, 'failed' => 0, 'total' => 0];

function pass(string $id, string $msg): void
{
    global $results;
    $results['passed']++; $results['total']++;
    echo "  \033[32m✅ {$id}\033[0m: {$msg}\n";
}
function fail(string $id, string $msg): void
{
    global $results;
    $results['failed']++; $results['total']++;
    echo "  \033[31m❌ {$id}\033[0m: {$msg}\n";
}
function check(string $id, bool $ok, string $okMsg, string $failMsg): void
{
    $ok ? pass($id, $okMsg) : fail($id, $failMsg);
}

$pdo      = getDB();
$svc      = new \App\Services\MemberService($pdo);
$auth     = new \App\Services\AuthService($pdo);
$borrowSv = new \App\Services\BorrowService($pdo);
$userRepo = new \App\Repositories\UserRepository($pdo);

$TAG = 'MEMST' . getmypid();

// 🧹 เก็บกวาดแบบรับประกัน — ทำงานแม้เทสต์ตายกลางคัน
register_shutdown_function(function () use ($pdo, $TAG): void {
    $ids = $pdo->query("SELECT id FROM users WHERE name LIKE '[{$TAG}]%'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return;
    $in = implode(',', array_map('intval', $ids));
    $pdo->exec("DELETE FROM payments WHERE borrow_id IN (SELECT id FROM borrows WHERE user_id IN ($in))");
    $pdo->exec("DELETE FROM borrows WHERE user_id IN ($in)");
    $pdo->exec("DELETE FROM reservations WHERE user_id IN ($in)");
    $pdo->exec("DELETE FROM users WHERE id IN ($in)");
});

echo "\n── A. อีเมลไม่บังคับ ──\n";

// A1: ไม่กรอกอีเมล → ระบบสร้างให้ และต้องผ่าน isValidEmail
//    ⚠️ ต้องจับ exception เอง ไม่งั้นถ้ามีใครคืนกฎ "บังคับกรอกอีเมล" กลับมา
//       เทสต์จะตายกลางคันทั้งไฟล์แทนที่จะแดงเฉพาะข้อนี้ (บทเรียนจาก BK-RT3)
$noMail = null;
try {
    $noMail = $svc->createMember(['name' => "[{$TAG}] ไม่มีอีเมล", 'phone' => '0800000041', 'password' => '123456']);
} catch (Exception $e) {
    fail('MEM-A1', '🔴 สมัครโดยไม่กรอกอีเมลไม่ได้: ' . $e->getMessage());
}
if ($noMail !== null) {
    $expected = 'm' . str_pad((string) $noMail['id'], 6, '0', STR_PAD_LEFT) . '@' . \App\Services\MemberService::INTERNAL_EMAIL_DOMAIN;
    check('MEM-A1', $noMail['email'] === $expected && isValidEmail($noMail['email']),
        "ไม่กรอกอีเมลก็สมัครได้ ระบบตั้งให้เป็น {$noMail['email']}",
        "🔴 ได้ {$noMail['email']} (คาดว่า {$expected}) · ผ่าน isValidEmail: " . (isValidEmail($noMail['email']) ? 'ใช่' : 'ไม่'));
}

// 🔴 ข้อนี้สำคัญ: ถ้าใช้โดเมนที่ไม่มีจุด (@local) จะไม่ผ่าน isValidEmail
//    แล้วบรรณารักษ์กดแก้ไขสมาชิกคนนั้นทีหลังจะติด "รูปแบบอีเมลไม่ถูกต้อง" ทั้งที่ไม่ได้แตะช่องอีเมล
check('MEM-A2', $noMail !== null && $svc->updateMember($noMail['id'], [
        'name' => "[{$TAG}] ไม่มีอีเมล แก้แล้ว", 'email' => $noMail['email'], 'phone' => '0800000041',
    ]),
    'แก้ไขสมาชิกที่ไม่มีอีเมลได้ อีเมลภายในไม่ทำให้ validation ตก',
    '🔴 แก้ไขไม่ได้ — อีเมลภายในไม่ผ่าน validation');

// A3: อีเมลจริงยังทำงานเหมือนเดิม + ซ้ำยังเตือน
$real = $svc->createMember(['name' => "[{$TAG}] มีอีเมล", 'email' => "{$TAG}@example.com", 'password' => '123456']);
$dupBlocked = false;
try { $svc->createMember(['name' => "[{$TAG}] ซ้ำ", 'email' => "{$TAG}@example.com", 'password' => '123456']); }
catch (Exception $e) { $dupBlocked = str_contains($e->getMessage(), 'ถูกใช้งานแล้ว'); }
check('MEM-A3', $real['email'] === "{$TAG}@example.com" && $dupBlocked,
    'อีเมลจริงยังเก็บตามที่กรอก และอีเมลซ้ำยังถูกปฏิเสธ',
    '🔴 ' . ($real['email'] !== "{$TAG}@example.com" ? 'อีเมลจริงถูกเปลี่ยน ' : '') . (!$dupBlocked ? 'อีเมลซ้ำหลุดเข้าไปได้' : ''));

// A4: นำเข้า CSV ที่เว้นช่อง Email → ได้อีเมลภายในเหมือนกัน (ใช้กติกาเดียวกัน)
//    ⚠️ จับ exception เหมือน A1 — ถ้าเส้นทางนำเข้ากลับไปบังคับอีเมล ต้องแดงเฉพาะข้อนี้ ไม่ใช่ตายทั้งไฟล์
$impEmail = '';
try {
    $imp = $svc->importMember(['name' => "[{$TAG}] นำเข้าไม่มีอีเมล", 'email' => '', 'phone' => '0800000042']);
    $impEmail = (string) $pdo->query("SELECT email FROM users WHERE id = {$imp['id']}")->fetchColumn();
} catch (Exception $e) {
    $impEmail = '(นำเข้าไม่สำเร็จ: ' . $e->getMessage() . ')';
}
check('MEM-A4', \App\Services\MemberService::isInternalEmail($impEmail),
    "นำเข้าแถวที่เว้นช่อง Email ก็ได้อีเมลภายในเหมือนกัน ({$impEmail})",
    "🔴 นำเข้าแล้วได้อีเมล {$impEmail} — เส้นทางนำเข้าใช้กติกาคนละแบบกับหน้าเพิ่มสมาชิก");

// A5: ห้ามออก token รีเซ็ตรหัสให้อีเมลภายใน (ส่งไปก็ไม่มีวันถึง)
//     แต่อีเมลจริงต้องยังออก token ได้ — ไม่งั้นเท่ากับปิดฟีเจอร์รีเซ็ตทั้งระบบ
$tokInternal = $noMail !== null ? ($auth->requestPasswordReset($noMail['email'])['token'] ?? null) : null;
$tokReal     = $auth->requestPasswordReset("{$TAG}@example.com")['token'] ?? null;
check('MEM-A5', $tokInternal === null && $tokReal !== null,
    'ไม่ออก token รีเซ็ตให้อีเมลภายใน แต่อีเมลจริงยังใช้ได้ตามปกติ',
    '🔴 ' . ($tokInternal !== null ? 'ออก token ให้ที่อยู่ที่ส่งไม่ถึง ' : '') . ($tokReal === null ? 'อีเมลจริงก็ออก token ไม่ได้ — ปิดฟีเจอร์ไปทั้งระบบ' : ''));

echo "\n── B. สถานะเลิกใช้งาน ──\n";

$mid = $real['id'];
$inDropdown = fn(): bool => (bool) array_filter($userRepo->findAllMembers(), fn($u) => (int) $u['id'] === (int) $mid);

// ให้ยืมหนังสือค้างไว้ 1 เล่ม ก่อนปิดใช้งาน
$bookId = (int) $pdo->query("SELECT id FROM books WHERE available > 2 AND is_reference = 0 LIMIT 1")->fetchColumn();
$borrowSv->createBorrow($mid, [$bookId]);

check('MEM-B0', $inDropdown() && $auth->login("{$TAG}@example.com", '123456') !== null,
    'ก่อนปิดใช้งาน: อยู่ในรายชื่อตอนบันทึกการยืม และเข้าระบบได้',
    '🔴 สมาชิกปกติกลับใช้งานไม่ได้ตั้งแต่ต้น');

$svc->updateMember($mid, ['name' => "[{$TAG}] มีอีเมล", 'email' => "{$TAG}@example.com", 'is_active' => 0]);

check('MEM-B1', $auth->login("{$TAG}@example.com", '123456') === null,
    'ปิดใช้งานแล้วเข้าระบบไม่ได้',
    '🔴 ปิดใช้งานแล้วยังล็อกอินได้');

check('MEM-B2', !$inDropdown(),
    'ปิดใช้งานแล้วหายจากรายชื่อตอนบันทึกการยืม',
    '🔴 ยังโผล่ในดรอปดาวน์ผู้ยืม — รายชื่อจะไม่มีวันสั้นลง');

// 🔴 ต้องยังอยู่ในหน้ารายชื่อสมาชิก ไม่ใช่หายไปเลย — ไม่งั้นเปิดกลับไม่ได้
$stillListed = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE id = {$mid}")->fetchColumn() === 1;
$historyKept = (int) $pdo->query("SELECT COUNT(*) FROM borrows WHERE user_id = {$mid}")->fetchColumn() > 0;
check('MEM-B3', $stillListed && $historyKept,
    'ยังอยู่ในหน้ารายชื่อสมาชิก และประวัติการยืมยังอยู่ครบ',
    '🔴 ' . (!$stillListed ? 'หายไปจากรายชื่อ ' : '') . (!$historyKept ? 'ประวัติการยืมหาย' : ''));

// 🔴 ข้อสำคัญที่สุดของหมวดนี้ — ปิดใช้งานต้องไม่ขังหนังสือ
$brwId = (int) $pdo->query("SELECT id FROM borrows WHERE user_id = {$mid} AND status = 'borrowing' LIMIT 1")->fetchColumn();
$returned = false;
try { $returned = !empty($borrowSv->returnBook($brwId)['success']); }
catch (Exception $e) { $returned = false; }
check('MEM-B4', $returned,
    'ยังรับคืนหนังสือของคนที่ปิดใช้งานได้ — หนังสือไม่ถูกขัง',
    '🔴 รับคืนไม่ได้ หนังสือจะค้างอยู่กับคนที่ติดต่อไม่ได้ตลอดไป');

$svc->updateMember($mid, ['name' => "[{$TAG}] มีอีเมล", 'email' => "{$TAG}@example.com", 'is_active' => 1]);
check('MEM-B5', $inDropdown() && $auth->login("{$TAG}@example.com", '123456') !== null,
    'เปิดกลับมาใช้งานได้ตามเดิม',
    '🔴 เปิดกลับแล้วยังใช้ไม่ได้');

// ── B7: 🛡️ ยามกันบั๊กตระกูล ก.6 ──
//    POST ที่ "ไม่มีสวิตช์สถานะ" ต้องไม่ไปปิดสมาชิกเงียบ ๆ
//
//    🔴 เกิดขึ้นจริงตอนพัฒนา: เขียน is_active = isset($_POST['is_active']) ? 1 : 0
//       ทุกครั้งที่มี POST เข้ามา ผลคือชุดทดสอบ HTTP ที่แก้ข้อมูลสมาชิกโดยไม่ส่งช่องนี้
//       ไปปิดสมาชิกจริงในระบบ 2 คนโดยไม่มีใครสั่ง — checkbox ที่ไม่ติ๊กกับ
//       "ฟอร์มไม่มีช่องนี้" หน้าตาเหมือนกันเป๊ะใน $_POST แยกไม่ออก
//       จึงต้องมีช่องซ่อน is_active_present เป็นตัวบอกว่าฟอร์มมีสวิตช์จริง
$b7Jar = tempnam(sys_get_temp_dir(), 'memst');
$b7Http = function (string $method, string $url, array $fields = []) use ($b7Jar): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIEJAR => $b7Jar, CURLOPT_COOKIEFILE => $b7Jar,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = (string) curl_exec($ch);
    curl_close($ch);
    return $body;
};
$b7Csrf = fn(string $html): string => preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';

$BASE = rtrim(APP_URL, '/');
$b7Http('POST', "$BASE/login.php", [
    'email' => 'admin@library.com', 'password' => '123456',
    'csrf_token' => $b7Csrf($b7Http('GET', "$BASE/login.php")),
]);
$formHtml = $b7Http('GET', "$BASE/admin/member_form.php?id={$mid}");
// จำลอง POST แบบเก่าที่ไม่รู้จักสวิตช์สถานะ — ไม่ส่งทั้ง is_active และ is_active_present
$b7Http('POST', "$BASE/admin/member_form.php?id={$mid}", [
    'csrf_token' => $b7Csrf($formHtml),
    'id'    => $mid,
    'name'  => "[{$TAG}] มีอีเมล",
    'email' => "{$TAG}@example.com",
    'phone' => '0800000031',
]);
$stillActive = (int) $pdo->query("SELECT is_active FROM users WHERE id = {$mid}")->fetchColumn() === 1;
@unlink($b7Jar);
check('MEM-B7', $stillActive,
    'POST ที่ไม่มีสวิตช์สถานะ ไม่ไปปิดสมาชิกเงียบ ๆ (มีช่องซ่อน is_active_present กันไว้)',
    '🔴 สมาชิกถูกปิดใช้งานทั้งที่ไม่มีใครสั่ง — checkbox ที่ไม่ติ๊กแยกไม่ออกจากฟอร์มที่ไม่มีช่องนี้');

// B6: สมาชิกเดิมทุกคนต้องเป็น "ใช้งานอยู่" — migration ตั้ง DEFAULT 1 ถูกไหม
$inactiveExisting = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE is_active = 0 AND name NOT LIKE '[{$TAG}]%'"
)->fetchColumn();
check('MEM-B6', $inactiveExisting === 0,
    'สมาชิกที่มีอยู่เดิมทุกคนยังใช้งานได้ — ไม่มีใครถูกปิดโดยไม่ตั้งใจตอนอัปเกรด',
    "🔴 มีสมาชิกเดิม {$inactiveExisting} คนถูกปิดใช้งานโดยไม่มีใครสั่ง (migration ตั้ง DEFAULT ผิด?)");

// ============================================================
echo "\n── CLEANUP ──\n";
echo "  ลบสมาชิกทดสอบและรายการที่เกี่ยวข้องทั้งหมด\n";

$pct = $results['total'] ? round($results['passed'] / $results['total'] * 100, 1) : 0;
echo "\n══════════════════════════════════════\n";
echo " RESULTS: {$results['passed']}/{$results['total']} passed ({$pct}%)";
if ($results['failed'] > 0) echo " | {$results['failed']} FAILED";
echo "\n══════════════════════════════════════\n\n";

exit($results['failed'] > 0 ? 1 : 0);
