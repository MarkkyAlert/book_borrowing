<?php

/**
 * บังคับเปลี่ยนรหัสผ่านครั้งแรก — F-53
 *
 * ==========================================================================
 * 🔴 ปัญหาเดิม
 * ==========================================================================
 * `MemberService::importMember()` ตั้งรหัสผ่านเริ่มต้น '123456' เหมือนกันทุกคน
 * และระบบไม่เคยบังคับให้เปลี่ยน → รหัสนั้นเป็นกุญแจร่วมที่ใช้ได้ตลอดกาล
 *
 * rate limit ที่มีอยู่กันไม่ได้: `login.php` นับ attempt แยกรายอีเมล
 * ซึ่งกันได้แค่ "เดารหัสของบัญชีเดียวรัว ๆ" แต่เคสนี้ไม่มีการเดาเลย
 * — รู้รหัสอยู่แล้ว ลองอีเมลละครั้งเดียว ไม่แตะเพดานสักอีเมล
 * และอีเมลห้องสมุดโรงเรียนมักเดาได้เป็นชุด (std0001@, std0002@, …)
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. ธงถูกตั้งถูกที่ — นำเข้า/admin สร้างให้ = ตั้ง · สมัครเอง = ไม่ตั้ง
 * B. ด่านฝั่งหน้าเว็บ — เด้งไปหน้าเปลี่ยนรหัส และหน้านั้นต้องไม่เด้งหาตัวเอง
 * C. 🔴 ด่านฝั่ง API — จุดที่ลืมง่ายที่สุด เพราะ api/ ไม่ได้เรียก requireLogin()
 * D. เปลี่ยนรหัสแล้วธงหาย · ตั้งกลับเป็นรหัสเริ่มต้นไม่ได้
 * E. migration backfill — ติดธงเฉพาะคนที่ยังใช้รหัสเริ่มต้น + รันซ้ำได้
 *
 * ==========================================================================
 * 🔴 ข้อควรระวังของไฟล์นี้เอง
 * ==========================================================================
 * ห้ามรัน closure ของ migration ใส่ฐานข้อมูลจริง!
 * `seed_school_library.php` ตั้งรหัสสมาชิกทุกคนเป็น '123456' (S_PASSWORD)
 * ถ้ารัน backfill ทับของจริง สมาชิก fixture ทั้ง 204 คนจะโดนติดธงทันที
 * แล้วเทสต์ชุดอื่นที่ล็อกอินเป็นสมาชิกจะพังยกแผง
 * → หมวด E จึงสร้างฐานข้อมูลชั่วคราวแยกต่างหาก
 *
 * 🧹 ลบสมาชิกทดสอบและฐานข้อมูลชั่วคราวที่สร้างขึ้นทั้งหมด
 *
 * 📌 การใช้งาน: php tests/test_must_change_password.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Repositories/UserRepository.php';
require_once __DIR__ . '/../app/Services/MemberService.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/../app/Services/ReservationService.php';

$BASE_URL   = rtrim(APP_URL, '/');
$SCRATCH_DB = 'bb_mustchg_chk';

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

$pdo = getDB();

$madeUsers = [];
$cookieJars = [];
$cleanupDone = false;
$cleanup = function () use (&$madeUsers, &$cookieJars, &$cleanupDone, $pdo, $SCRATCH_DB) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    // 🔴 ต้อง rollBack ก่อน ไม่งั้น DELETE จะถูกย้อนไปด้วยถ้ามี transaction ค้าง
    try {
        if ($pdo->inTransaction()) $pdo->rollBack();
    } catch (Throwable $e) {
        echo '  ⚠️ rollback ไม่สำเร็จ: ' . $e->getMessage() . "\n";
    }

    // 🔴 [บทเรียนจากเทสต์นี้เอง] ตอนพิสูจน์ว่าเคส MCP-C1 มีฟันจริง (ถอดด่าน API ออก)
    //    การจองถูกสร้างขึ้นจริงและ FK บล็อกการลบ user
    //    เดิมเขียน DELETE ... WHERE id IN (...) เป็นคำสั่งเดียว → พังทั้งคำสั่ง
    //    ทิ้งสมาชิกค้างไว้ทั้ง 4 คน (บั๊กแบบเดียวกับ F-52 เป๊ะ)
    //    → ตอนนี้: คืนสต็อกก่อน แล้วลบ **ทีละคน** คนที่ลบไม่ได้ต้องไม่ลากคนอื่นไปด้วย
    $failed = [];
    foreach ($madeUsers as $uid) {
        try {
            // 🔓 ยกเลิกการจองผ่าน Service ก่อน — ห้าม DELETE ตรง ๆ
            //    การจองสถานะ pending กัน available ไว้แล้ว ลบดิบ ๆ = สต็อกหายไปเฉย ๆ
            $held = $pdo->prepare("SELECT id FROM reservations WHERE user_id = ? AND status IN ('pending','waiting')");
            $held->execute([$uid]);
            foreach ($held->fetchAll(PDO::FETCH_COLUMN) as $resId) {
                try {
                    $svc = new \App\Services\ReservationService($pdo);
                    $svc->cancelReservation((int) $resId, (int) $uid);
                } catch (Throwable $e) {
                    echo "  ⚠️ ยกเลิกการจอง #{$resId} ไม่สำเร็จ: " . $e->getMessage() . "\n";
                }
            }
            // ตอนนี้เหลือแต่แถวสถานะ cancelled/expired ที่ไม่กันสต็อกแล้ว — ลบได้ปลอดภัย
            $pdo->prepare("DELETE FROM reservations WHERE user_id = ?")->execute([$uid]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
        } catch (Throwable $e) {
            $failed[] = "#{$uid} (" . $e->getMessage() . ')';
        }
    }
    echo '  ลบสมาชิกทดสอบ ' . (count($madeUsers) - count($failed)) . '/' . count($madeUsers) . " คน\n";
    if ($failed) {
        echo "  🔴 ลบไม่สำเร็จ ต้องลบมือ: " . implode(' · ', $failed) . "\n";
    }
    try {
        $root = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS);
        $root->exec("DROP DATABASE IF EXISTS `{$SCRATCH_DB}`");
        echo "  ลบฐานข้อมูลชั่วคราว {$SCRATCH_DB}\n";
    } catch (Throwable $e) {
        echo '  ⚠️ ลบฐานข้อมูลชั่วคราวไม่สำเร็จ: ' . $e->getMessage() . "\n";
    }
    foreach ($cookieJars as $jar) @unlink($jar);
};
register_shutdown_function($cleanup);

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  บังคับเปลี่ยนรหัสผ่านครั้งแรก (F-53)                     ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// ── helper HTTP ─────────────────────────────────────────────
function newJar(): string
{
    global $cookieJars;
    $jar = tempnam(sys_get_temp_dir(), 'bbmcp');
    $cookieJars[] = $jar;
    return $jar;
}

/** @return array{body:string,code:int,url:string} */
function http(string $jar, string $method, string $url, array $fields = [], bool $follow = true): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $last = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return ['body' => $body, 'code' => $code, 'url' => $last];
}

function csrfFrom(string $html): string
{
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function flagOf(int $id): ?int
{
    global $pdo;
    $st = $pdo->prepare("SELECT must_change_password FROM users WHERE id = ?");
    $st->execute([$id]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int) $v;
}

$memberService = new \App\Services\MemberService($pdo);
$authService   = new \App\Services\AuthService($pdo);
$uniq          = substr((string) getmypid(), -4) . mt_rand(1000, 9999);

// ============================================================
// A. ธงถูกตั้งถูกที่
// ============================================================
echo "── A. ใครควรถูกบังคับ ใครไม่ควร ──\n";

// A1 — นำเข้าจากไฟล์ = ทุกคนได้รหัสเดียวกัน → ต้องบังคับ
$importedEmail = "mcp_import_{$uniq}@test.com";
$r = $memberService->importMember(['name' => '[MCPTEST] นำเข้าจากไฟล์', 'email' => $importedEmail, 'phone' => '0810000001']);
$importedId = (int) $r['id'];
$madeUsers[] = $importedId;

check('MCP-A1', $r['action'] === 'created' && flagOf($importedId) === 1,
    'สมาชิกที่ถูกนำเข้า → ถูกบังคับเปลี่ยนรหัส',
    '🔴 นำเข้าแล้วไม่ถูกบังคับ (ธง=' . var_export(flagOf($importedId), true) . ') — รหัสร่วมยังใช้ได้ตลอดกาล');

// A2 — 🔴 นำเข้าไฟล์เดิมซ้ำ (upsert) ต้องไม่ไปรีเซ็ตคนที่เปลี่ยนรหัสไปแล้ว
//      ห้องสมุดนำเข้าทะเบียนนักเรียนใหม่ทุกเทอม — ถ้า upsert ติดธงด้วย
//      สมาชิกที่ใช้งานอยู่ดี ๆ จะถูกเด้งไปหน้าเปลี่ยนรหัสทุกต้นเทอม
$pdo->prepare("UPDATE users SET must_change_password = 0 WHERE id = ?")->execute([$importedId]);
$r2 = $memberService->importMember(['name' => '[MCPTEST] นำเข้าซ้ำ', 'email' => $importedEmail, 'phone' => '0810000002']);
check('MCP-A2', $r2['action'] === 'updated' && flagOf($importedId) === 0,
    'นำเข้าไฟล์เดิมซ้ำ (upsert) ไม่ไปบังคับคนที่เปลี่ยนรหัสไปแล้ว',
    '🔴 upsert ติดธงให้คนที่เปลี่ยนรหัสไปแล้ว — นำเข้าทะเบียนทุกเทอมแล้วสมาชิกเก่าโดนเด้งหมด');

// A3 — สมัครเอง = ตั้งรหัสของตัวเอง ไม่มีใครรู้ → ไม่ต้องบังคับ
$selfEmail = "mcp_self_{$uniq}@test.com";
$r3 = $memberService->createMember([
    'name' => '[MCPTEST] สมัครเอง', 'email' => $selfEmail,
    'phone' => '0810000003', 'password' => 'MyOwnSecret99',
]);
$selfId = (int) $r3['id'];
$madeUsers[] = $selfId;
check('MCP-A3', flagOf($selfId) === 0,
    'คนที่สมัครเองและตั้งรหัสเอง → ไม่ถูกบังคับ (ไม่กวนผู้ใช้โดยไม่จำเป็น)',
    '🔴 คนที่ตั้งรหัสเองก็โดนบังคับด้วย — กวนโดยไม่มีเหตุผล');

// A4 — admin สร้างให้ = admin รู้รหัสนั้น → ต้องบังคับ
$staffMadeEmail = "mcp_bystaff_{$uniq}@test.com";
$r4 = $memberService->createMember([
    'name' => '[MCPTEST] เจ้าหน้าที่สร้างให้', 'email' => $staffMadeEmail, 'phone' => '0810000004',
], true);
$staffMadeId = (int) $r4['id'];
$madeUsers[] = $staffMadeId;
check('MCP-A4', flagOf($staffMadeId) === 1,
    'สมาชิกที่เจ้าหน้าที่สร้างให้ → ถูกบังคับ (มีคนอื่นรู้รหัสเสมอ)',
    '🔴 เจ้าหน้าที่สร้างให้แล้วไม่บังคับ — รหัสที่เจ้าหน้าที่รู้ใช้ได้ตลอดไป');

// ============================================================
// B. ด่านฝั่งหน้าเว็บ
// ============================================================
echo "\n── B. ด่านฝั่งหน้าเว็บ ──\n";

// 👤 สมาชิกที่ยังใช้รหัสเริ่มต้น — ใช้เป็นตัวแสดงตลอดหมวด B/C/D
$victimEmail = "mcp_victim_{$uniq}@test.com";
$rv = $memberService->importMember(['name' => '[MCPTEST] ยังไม่เปลี่ยนรหัส', 'email' => $victimEmail, 'phone' => '0810000005']);
$victimId = (int) $rv['id'];
$madeUsers[] = $victimId;

$jar = newJar();
$loginPage = http($jar, 'GET', "$BASE_URL/login.php");
$after = http($jar, 'POST', "$BASE_URL/login.php", [
    'csrf_token' => csrfFrom($loginPage['body']),
    'email' => $victimEmail, 'password' => IMPORT_DEFAULT_PASSWORD,
]);

// B1 — ล็อกอินแล้วต้องไปโผล่ที่หน้าเปลี่ยนรหัส ไม่ใช่หน้าแรก
check('MCP-B1', str_contains($after['url'], 'change_password.php'),
    'ล็อกอินด้วยรหัสเริ่มต้น → ไปโผล่ที่หน้าตั้งรหัสใหม่',
    '🔴 ล็อกอินแล้วใช้งานได้เลย ไปจบที่ ' . $after['url'] . ' — การบังคับไม่ทำงาน');

// B2 — 🔴 บัญชี "เจ้าหน้าที่" ก็ต้องถูกกันเหมือนกัน
//      เส้นทางต่างจากสมาชิก: admin/* เรียก requireAdmin()/requireStaff() ซึ่ง chain ไป requireLogin()
//      ถ้าด่านไปแขวนผิดที่ (เช่นใส่ใน requireLogin แต่ requireAdmin ไม่ได้เรียกต่อ)
//      เจ้าหน้าที่ที่ยังใช้รหัสร่วมจะเข้าหลังบ้านได้ทั้งระบบ ซึ่งร้ายแรงกว่าสมาชิกมาก
$staffEmail = "mcp_staffuser_{$uniq}@test.com";
$rs = $memberService->createMember([
    'name' => '[MCPTEST] เจ้าหน้าที่ยังไม่เปลี่ยนรหัส', 'email' => $staffEmail,
    'phone' => '0810000006', 'password' => IMPORT_DEFAULT_PASSWORD,
], true);
$staffUserId = (int) $rs['id'];
$madeUsers[] = $staffUserId;
$pdo->prepare("UPDATE users SET role = 'staff' WHERE id = ?")->execute([$staffUserId]);

$staffJar  = newJar();
$staffPage = http($staffJar, 'GET', "$BASE_URL/login.php");
http($staffJar, 'POST', "$BASE_URL/login.php", [
    'csrf_token' => csrfFrom($staffPage['body']),
    'email' => $staffEmail, 'password' => IMPORT_DEFAULT_PASSWORD,
]);
$adminHome = http($staffJar, 'GET', "$BASE_URL/admin/index.php");

check('MCP-B2', str_contains($adminHome['url'], 'change_password.php'),
    'บัญชีเจ้าหน้าที่ที่ยังใช้รหัสร่วม เข้าหลังบ้านไม่ได้',
    '🔴 เจ้าหน้าที่เข้าหลังบ้านได้ทั้งที่ยังใช้รหัสร่วม (ไปจบที่ ' . $adminHome['url'] . ') — '
        . 'ด่านไม่ครอบเส้นทาง requireAdmin/requireStaff');

// B3 — หน้าที่ต้องล็อกอินต้องเด้งกลับมาที่หน้าเปลี่ยนรหัส
$blocked = [];
foreach (['profile.php', 'my_borrows.php', 'my_reservations.php'] as $page) {
    $res = http($jar, 'GET', "$BASE_URL/$page");
    if (!str_contains($res['url'], 'change_password.php')) $blocked[] = $page;
}
check('MCP-B3', $blocked === [],
    'ทุกหน้าที่ต้องล็อกอินเด้งกลับมาที่หน้าตั้งรหัสใหม่ (3 หน้า)',
    '🔴 เข้าถึงได้ทั้งที่ยังไม่เปลี่ยนรหัส: ' . implode(', ', $blocked));

// B4 — 🔴 หน้าเปลี่ยนรหัสเองต้องไม่เด้งหาตัวเอง (ถ้าเรียก requireLogin() เฉย ๆ จะวนไม่จบ)
$cp = http($jar, 'GET', "$BASE_URL/change_password.php");
check('MCP-B4', $cp['code'] === 200 && str_contains($cp['body'], 'ตั้งรหัสผ่านใหม่'),
    'หน้าตั้งรหัสใหม่เปิดได้ ไม่เด้งหาตัวเองวนไม่จบ (HTTP ' . $cp['code'] . ')',
    '🔴 หน้าตั้งรหัสใหม่ไม่แสดงฟอร์ม (HTTP ' . $cp['code'] . ') — น่าจะ redirect วน');

// ============================================================
// C. ด่านฝั่ง API — จุดที่ลืมง่ายที่สุด
// ============================================================
echo "\n── C. ด่านฝั่ง API ──\n";

// 🧠 endpoint ใน api/ **ไม่ได้เรียก requireLogin()** — เช็ค isLoggedIn() เองบ้าง
//    เรียก requireStaffApi() บ้าง ถ้ากันแค่หน้าเว็บ คนที่ยึดบัญชีด้วยรหัสร่วม
//    จะยังยิง API ตรง ๆ ได้ ทั้งที่หน้าเว็บเด้งเขาออกไปแล้ว
$bookId = (int) $pdo->query("SELECT id FROM books WHERE is_reference = 0 LIMIT 1")->fetchColumn();
$reserve = http($jar, 'POST', "$BASE_URL/api/reserve_book.php", [
    'csrf_token' => csrfFrom($cp['body']),
    'book_id' => $bookId,
], false);
$json = json_decode($reserve['body'], true);

check('MCP-C1', $reserve['code'] === 403 && ($json['success'] ?? true) === false,
    'api/reserve_book.php ปฏิเสธ (HTTP ' . $reserve['code'] . ') — ยิง API ตรงก็ทำแทนเจ้าของบัญชีไม่ได้',
    '🔴 จองหนังสือผ่าน API ได้ทั้งที่ยังไม่เปลี่ยนรหัส (HTTP ' . $reserve['code'] . ') — '
        . 'ด่านกันแค่หน้าเว็บ ไม่ได้กัน API');

// C2 — endpoint ที่ redirect (ไม่ใช่ JSON) ก็ต้องถูกกันเหมือนกัน
//
// 🔴 [บทเรียน] เคสนี้ฉบับแรกวัดแค่ "ไปจบที่ change_password.php ไหม" แล้ว**ผ่านทั้งที่ถอดด่านออกแล้ว**
//    เพราะพอไม่มีด่าน โค้ดจะทำงานต่อ แล้ว redirect ไป my_reservations.php
//    ซึ่ง requireLogin() ของ *หน้านั้น* เด้งกลับมาที่ change_password.php อยู่ดี
//    → ปลายทางเหมือนกันเป๊ะทั้งที่พฤติกรรมต่างกันสิ้นเชิง
//    ตอนนี้จึงวัด **ผลลัพธ์จริง**: การจองต้องยังอยู่ ไม่ถูกยกเลิก
$reservationService = new \App\Services\ReservationService($pdo);
$freeBook = (int) $pdo->query("
    SELECT id FROM books WHERE is_reference = 0 AND available > 0 ORDER BY id LIMIT 1
")->fetchColumn();
$made = $reservationService->createReservation($victimId, $freeBook);
$victimResId = (int) ($made['reservation_id'] ?? $made['id'] ?? 0);

$cancel = http($jar, 'POST', "$BASE_URL/api/cancel_reservation.php", [
    'csrf_token' => csrfFrom($cp['body']),
    'reservation_id' => $victimResId,
]);

$stmt = $pdo->prepare("SELECT status FROM reservations WHERE id = ?");
$stmt->execute([$victimResId]);
$statusAfter = (string) $stmt->fetchColumn();

check('MCP-C2', $victimResId > 0 && $statusAfter === 'pending',
    'api/cancel_reservation.php ทำงานจริงไม่ได้ — การจองยังอยู่ (สถานะ ' . $statusAfter . ')',
    '🔴 ยกเลิกการจองสำเร็จทั้งที่ยังไม่เปลี่ยนรหัส (สถานะกลายเป็น "' . $statusAfter . '") — '
        . 'คนที่ยึดบัญชีด้วยรหัสร่วมยิง endpoint นี้ตรง ๆ ทำแทนเจ้าของได้');

// ============================================================
// D. เปลี่ยนรหัส
// ============================================================
echo "\n── D. การเปลี่ยนรหัส ──\n";

// D1 — 🔴 ตั้งกลับเป็นรหัสเริ่มต้นไม่ได้
//      ถ้าปล่อยให้ตั้งได้ การบังคับเปลี่ยนก็เป็นแค่พิธีกรรม
//      (Service มีเช็ค "ห้ามซ้ำรหัสปัจจุบัน" อยู่แล้ว แต่ตอนนี้รหัสปัจจุบัน
//       *คือ* รหัสเริ่มต้น จึงต้องทดสอบจากคนที่เปลี่ยนไปแล้วถึงจะมีความหมาย)
$authService->changePassword($selfId, 'MyOwnSecret99', 'SomethingElse123');
$backToDefault = $authService->changePassword($selfId, 'SomethingElse123', IMPORT_DEFAULT_PASSWORD);
check('MCP-D1', ($backToDefault['success'] ?? true) === false,
    'ตั้งรหัสกลับเป็นรหัสเริ่มต้นของระบบไม่ได้ — "' . ($backToDefault['error'] ?? '') . '"',
    '🔴 ตั้งกลับเป็นรหัสเริ่มต้นได้ — การบังคับเปลี่ยนกลายเป็นพิธีกรรม');

// D2 — เปลี่ยนสำเร็จผ่านหน้าเว็บ → ธงหายใน DB + ใช้งานหน้าอื่นได้
$cpForm = http($jar, 'GET', "$BASE_URL/change_password.php");
$submitted = http($jar, 'POST', "$BASE_URL/change_password.php", [
    'csrf_token'       => csrfFrom($cpForm['body']),
    'current_password' => IMPORT_DEFAULT_PASSWORD,
    'new_password'     => 'BrandNewPass77',
    'confirm_password' => 'BrandNewPass77',
]);
check('MCP-D2', flagOf($victimId) === 0,
    'เปลี่ยนรหัสสำเร็จ → ธงถูกเคลียร์ใน DB',
    '🔴 เปลี่ยนรหัสแล้วธงยังอยู่ (ธง=' . var_export(flagOf($victimId), true) . ') — ผู้ใช้จะติดวนอยู่ในหน้านั้นตลอด');

// D3 — และใช้งานหน้าปกติได้แล้วจริง ๆ (ไม่ใช่แค่ค่าใน DB เปลี่ยน)
$profile = http($jar, 'GET', "$BASE_URL/profile.php");
check('MCP-D3', !str_contains($profile['url'], 'change_password.php') && $profile['code'] === 200,
    'เปลี่ยนแล้วเข้าหน้าโปรไฟล์ได้ตามปกติ',
    '🔴 ยังถูกเด้งกลับหน้าเปลี่ยนรหัส (ไปจบที่ ' . $profile['url'] . ') — session ยังถือธงเก่า');

// D4 — 🔴 ทางลืมรหัสผ่านก็ต้องเคลียร์ธง
//      ถ้าเคลียร์เฉพาะใน changePassword() คนที่ใช้ลิงก์รีเซ็ตจะติดวนตลอดกาล
//      (เขาไม่รู้ "รหัสปัจจุบัน" จึงผ่านหน้าบังคับเปลี่ยนไม่ได้เลย = ล็อกตัวเองออกถาวร)
$pdo->prepare("UPDATE users SET must_change_password = 1 WHERE id = ?")->execute([$staffMadeId]);
$staffMadeUser = (new \App\Repositories\UserRepository($pdo))->findById($staffMadeId);
$req = $authService->requestPasswordReset($staffMadeUser['email']);
$resetOk = false;
if (!empty($req['token'])) {
    $resetRes = $authService->resetPassword($req['token'], 'AfterResetPass88');
    $resetOk = ($resetRes['success'] ?? false) === true;
}
check('MCP-D4', $resetOk && flagOf($staffMadeId) === 0,
    'ตั้งรหัสใหม่ผ่านลิงก์ "ลืมรหัสผ่าน" → ธงถูกเคลียร์ด้วย',
    '🔴 รีเซ็ตรหัสแล้วธงยังอยู่ — คนที่ลืมรหัสจะติดในหน้าบังคับเปลี่ยนถาวร '
        . 'เพราะกรอก "รหัสปัจจุบัน" ไม่ได้ (reset สำเร็จ=' . var_export($resetOk, true)
        . ' ธง=' . var_export(flagOf($staffMadeId), true) . ')');

// ============================================================
// E. migration backfill — บนฐานข้อมูลชั่วคราวเท่านั้น
// ============================================================
echo "\n── E. migration backfill (ฐานข้อมูลชั่วคราว) ──\n";

// 🔴 ห้ามรันกับ DB จริง — seeder ตั้งรหัสสมาชิกทุกคนเป็น '123456'
//    รัน backfill ทับของจริงแล้วสมาชิก fixture ทุกคนจะโดนติดธง
//    เทสต์ชุดอื่นที่ล็อกอินเป็นสมาชิกจะพังยกแผง
$root = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$root->exec("DROP DATABASE IF EXISTS `{$SCRATCH_DB}`");
$root->exec("CREATE DATABASE `{$SCRATCH_DB}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$scratch = new PDO("mysql:host=" . DB_HOST . ";dbname={$SCRATCH_DB};charset=utf8mb4", DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// 📝 สร้างตาราง users ตาม schema.sql แล้ว "ถอดคอลัมน์ออก" ให้เหลือสภาพก่อน migration
//    ทำแบบนี้แทนการเขียน CREATE TABLE เองในเทสต์ เพื่อให้ทดสอบกับรูปร่างจริงของตาราง
$schemaSql = (string) file_get_contents(__DIR__ . '/../database/schema.sql');
preg_match('/CREATE TABLE IF NOT EXISTS `users`.*?ENGINE=InnoDB[^;]*;/s', $schemaSql, $um);
$scratch->exec($um[0]);
$scratch->exec("ALTER TABLE `users` DROP COLUMN `must_change_password`");

$mkUser = function (string $email, string $password, string $role = 'member') use ($scratch): int {
    $st = $scratch->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
    $st->execute([$email, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
    return (int) $scratch->lastInsertId();
};

$stillDefault = $mkUser('still_default@x.test', IMPORT_DEFAULT_PASSWORD);
$changedOwn   = $mkUser('changed_own@x.test', 'TheyPickedThis123');
$staffAccount = $mkUser('staff@x.test', IMPORT_DEFAULT_PASSWORD, 'staff');

$migration = require __DIR__ . '/../database/migrations/2026_09_01_000001_add_must_change_password.php';
$firstRun = $migration($scratch);

$flagIn = function (int $id) use ($scratch): int {
    $st = $scratch->prepare("SELECT must_change_password FROM users WHERE id = ?");
    $st->execute([$id]);
    return (int) $st->fetchColumn();
};

// E1 — ติดธงเฉพาะคนที่ยังใช้รหัสเริ่มต้น
check('MCP-E1', $flagIn($stillDefault) === 1,
    'ลูกค้าเดิม: คนที่ยังใช้รหัสเริ่มต้น → ถูกติดธงย้อนหลัง',
    '🔴 ไม่ติดธงย้อนหลัง — ลูกค้าที่ติดตั้งไปแล้วไม่ได้อะไรจากการแก้ครั้งนี้เลย');

// E2 — 🔴 คนที่ตั้งรหัสเองไปแล้วต้องไม่ถูกรบกวน
check('MCP-E2', $flagIn($changedOwn) === 0,
    'คนที่ตั้งรหัสของตัวเองไปแล้ว → ไม่ถูกรบกวน',
    '🔴 ติดธงให้คนที่เปลี่ยนรหัสไปแล้วด้วย — สมาชิกที่ใช้งานปกติจะโดนเด้งทั้งห้องสมุด');

// E3 — 🔴 บัญชีเจ้าหน้าที่ที่ยังใช้รหัสเริ่มต้นก็ต้องถูกติดธงด้วย
//      เกณฑ์คือ "รหัสของบัญชีนี้คือรหัสเริ่มต้นที่เอกสารประกาศไว้หรือเปล่า" — role ไม่เกี่ยว
//      ถ้าเกี่ยวก็เกี่ยวในทางกลับกัน: staff/admin ที่ใช้รหัสร่วมอันตรายกว่าสมาชิกมาก
//      เพราะเข้าหลังบ้านได้ทั้งระบบ ปล่อยไว้ = แก้ไม่ตรงจุดที่เสี่ยงที่สุด
check('MCP-E3', $flagIn($staffAccount) === 1,
    'บัญชีเจ้าหน้าที่ที่ยังใช้รหัสเริ่มต้น → ถูกติดธงด้วย (role ไม่ใช่ข้อยกเว้น)',
    '🔴 บัญชีเจ้าหน้าที่ที่ใช้รหัสร่วมรอดไปได้ — เหลือช่องไว้ที่บัญชีที่มีอำนาจมากที่สุด');

// E3b — 🔴 แต่ผู้ดูแลที่ตั้งรหัสของตัวเองตอนติดตั้ง ต้องไม่ถูกแตะ
//       นี่คือเหตุผลที่ backfill ปลอดภัย: มันติดธงตาม "รหัสที่ใช้จริง" ไม่ใช่ตาม role
//       ผู้ดูแลที่ตั้งรหัสเองจะไม่มีวันถูกบังคับ = ไม่มีความเสี่ยงล็อกตัวเองออก
$adminOwnPass = $mkUser('admin_strong@x.test', 'AdminChoseThis!2024', 'admin');
$migration($scratch);
check('MCP-E3b', $flagIn($adminOwnPass) === 0,
    'ผู้ดูแลที่ตั้งรหัสของตัวเอง → ไม่ถูกแตะ (ไม่มีความเสี่ยงล็อกตัวเองออก)',
    '🔴 ผู้ดูแลที่ตั้งรหัสเองก็โดนบังคับ — เสี่ยงกวนคนที่ไม่ได้มีปัญหาอะไร');

// E4 — รันซ้ำได้ ไม่พังและไม่เปลี่ยนผลลัพธ์
$secondRunOk = true;
$secondRunErr = '';
try {
    $migration($scratch);
} catch (Throwable $e) {
    $secondRunOk = false;
    $secondRunErr = $e->getMessage();
}
check('MCP-E4', $secondRunOk && $flagIn($changedOwn) === 0 && $flagIn($stillDefault) === 1,
    'รัน migration ซ้ำได้ ผลลัพธ์ไม่เปลี่ยน',
    '🔴 รันซ้ำแล้วมีปัญหา: ' . ($secondRunErr ?: 'ผลลัพธ์เปลี่ยนไป'));

// E5 — 🔴 backfill ต้องไม่ถูกข้ามเมื่อคอลัมน์มีอยู่แล้ว
//      เคสจริง: รันครั้งแรกแล้วพังกลางทาง คอลัมน์ถูกสร้างแล้วแต่ backfill ไม่ครบ
//      ถ้าเขียนเป็น "คอลัมน์มีแล้ว → return ทันที" คนที่เหลือจะไม่ถูกติดธงตลอดกาล
$lateComer = $mkUser('late_comer@x.test', IMPORT_DEFAULT_PASSWORD);
$migration($scratch);
check('MCP-E5', $flagIn($lateComer) === 1,
    'คอลัมน์มีอยู่แล้วแต่ backfill ยังทำงาน — กันเคสรันครึ่งทางแล้วพัง',
    '🔴 backfill ถูกข้ามเพราะคอลัมน์มีอยู่แล้ว — สภาพครึ่ง ๆ จะแก้ไม่ได้ตลอดกาล');

// E6 — รายงานผลบอกจำนวนจริง ไม่ใช่ข้อความลอย ๆ
check('MCP-E6', (bool) preg_match('/ติดธงบังคับเปลี่ยนรหัส\s*\d+\s*รายการ/u', $firstRun),
    'migration รายงานจำนวนบัญชีที่ถูกติดธง — ผู้ติดตั้งเห็นว่าเกิดอะไรขึ้น',
    '🔴 ไม่รายงานจำนวน: "' . $firstRun . '"');

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
