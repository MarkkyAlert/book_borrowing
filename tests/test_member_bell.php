<?php

/**
 * กระดิ่งแจ้งเตือนฝั่งสมาชิก
 *
 * ==========================================================================
 * 🔴 ที่มา: ระบบไม่ส่งอีเมล (ตั้งใจ) สมาชิกจึงไม่มีทางรู้ว่าหนังสือใกล้ครบกำหนด
 * ==========================================================================
 * เดิมต้องเปิดหน้า "รายการยืมของฉัน" เองถึงจะเห็น · ฝั่งสมาชิก**ไม่มีกระดิ่งเลย**
 * (ฝั่งเจ้าหน้าที่มีแล้ว แต่เป็นคนละชุดตัวเลข คนละความหมาย)
 *
 * ⚠️ ข้อจำกัดที่ยอมรับ: กระดิ่ง **รอให้คนเข้ามาดู** ไม่ได้ไปหาเขาแบบอีเมล
 *    สมาชิกที่ไม่เข้าเว็บเลยจะไม่เห็น — กลุ่มนั้นใช้ "ใบรายชื่อโทรตาม" แทน
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. ตัวเลขตรงกับ query ที่ scope ด้วยสมาชิกคนนั้น
 * B. 🔴 **ห้ามเห็นตัวเลขของคนอื่น** — สมาชิก ก. กับ ข. ต้องได้คนละค่า
 * C. ยังไม่ล็อกอิน = ไม่มีกระดิ่ง และไม่ยิง query
 * D. ลิงก์เปิดได้ · จุดแดงขึ้นเฉพาะตอนมีของจริง
 *
 * 🧹 ลบสมาชิก หนังสือ และการยืมที่สร้างขึ้น
 *
 * 📌 การใช้งาน: php tests/test_member_bell.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/BookService.php';
require_once __DIR__ . '/../app/Services/BorrowService.php';
require_once __DIR__ . '/../app/Services/MemberService.php';

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

$pdo         = getDB();
$uniq        = substr((string) getmypid(), -4) . mt_rand(100, 999);
$madeUsers   = [];
$madeBooks   = [];
$madeBorrows = [];
$jars        = [];
$cleanupDone = false;

$cleanup = function () use (&$madeUsers, &$madeBooks, &$madeBorrows, &$jars, &$cleanupDone, $pdo) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    // 🔴 ลบ borrows ก่อน — FK เป็น RESTRICT ลบสลับลำดับจะลบไม่ออก
    try {
        $pdo->exec("DELETE bo FROM borrows bo JOIN users u ON bo.user_id = u.id
                    WHERE u.name LIKE '%[MBELLTEST]%'");
        $pdo->exec("DELETE bo FROM borrows bo JOIN books b ON bo.book_id = b.id
                    WHERE b.title LIKE '%[MBELLTEST]%'");
    } catch (Throwable $e) { echo "  ⚠️ กวาดการยืมไม่สำเร็จ\n"; }
    foreach ($madeBooks as $id) {
        try { $pdo->prepare("DELETE FROM books WHERE id = ?")->execute([(int) $id]); } catch (Throwable $e) {}
    }
    foreach ($madeUsers as $id) {
        try { $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([(int) $id]); } catch (Throwable $e) {}
    }
    try {
        $n = $pdo->exec("DELETE FROM books WHERE title LIKE '%[MBELLTEST]%'");
        $n += $pdo->exec("DELETE FROM users WHERE name LIKE '%[MBELLTEST]%'");
        if ($n > 0) echo "  🧹 กวาดตามป้าย [MBELLTEST] อีก {$n} แถว\n";
    } catch (Throwable $e) {}
    foreach ($jars as $j) @unlink($j);
    echo '  ลบ ' . count($madeBorrows) . ' การยืม · ' . count($madeBooks)
       . ' หนังสือ · ' . count($madeUsers) . " สมาชิก\n";
    try {
        $bad = $pdo->query("
            SELECT COUNT(*) FROM (
                SELECT b.id, b.quantity, b.available FROM books b
                HAVING b.available <> b.quantity
                    - (SELECT COUNT(*) FROM borrows bo WHERE bo.book_id = b.id AND bo.status = 'borrowing')
                    - (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.id AND r.status = 'pending')
            ) t
        ")->fetchColumn();
        echo ((int) $bad === 0) ? "  ✅ invariant สต็อกยังตรง\n" : "  🔴 invariant เพี้ยน {$bad} เล่ม\n";
    } catch (Throwable $e) {}
};
register_shutdown_function($cleanup);

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  กระดิ่งแจ้งเตือนฝั่งสมาชิก                                 ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

function httpAs(string $jar, string $method, string $url, array $fields = []): string
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
    curl_close($ch);
    return $body;
}

function csrfFrom(string $html): string
{
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

/** อ่านตัวเลขบนป้ายกระดิ่งของสมาชิกจาก HTML */
function memberBadge(string $html): ?string
{
    return preg_match('/aria-label="การแจ้งเตือนของฉัน".{0,400}?bg-red-500[^>]*>\s*([\d+]+)/s', $html, $m)
        ? $m[1] : null;
}

$bookSvc   = new \App\Services\BookService($pdo);
$borrowSvc = new \App\Services\BorrowService($pdo);
$memberSvc = new \App\Services\MemberService($pdo);
$catId     = (int) $pdo->query("SELECT id FROM categories LIMIT 1")->fetchColumn();
$PASSWORD  = 'MemberBell#2026';

/**
 * 🧠 สร้างสมาชิก 2 คนที่มีสถานการณ์ **ต่างกันชัดเจน** — หัวใจของข้อ B
 *    ถ้าทั้งคู่มีตัวเลขเท่ากัน เคส "ไม่รั่วข้ามคน" จะผ่านแบบไม่ได้ตรวจอะไร
 */
$mkMember = function (string $tag) use ($memberSvc, $uniq, $PASSWORD, &$madeUsers): array {
    $email = strtolower($tag) . $uniq . '@test.local';
    $r = $memberSvc->createMember([
        'name' => "[MBELLTEST] {$tag} {$uniq}", 'email' => $email,
        'phone' => '0800000000', 'password' => $PASSWORD,
    ]);
    $madeUsers[] = (int) $r['id'];
    return ['id' => (int) $r['id'], 'email' => $email];
};

$mkBook = function (string $tag) use ($bookSvc, $catId, $uniq, &$madeBooks): int {
    $id = (int) $bookSvc->createBook([
        'title' => "[MBELLTEST] {$tag} {$uniq}", 'author' => 'ผู้แต่งทดสอบ',
        'category_id' => $catId, 'quantity' => 1, 'isbn' => null,
    ]);
    $madeBooks[] = $id;
    return $id;
};

$mkBorrow = function (int $userId, int $bookId, int $dayOffset) use ($borrowSvc, $pdo, &$madeBorrows): int {
    $r = $borrowSvc->createBorrow($userId, [$bookId]);
    if (empty($r['success'])) {
        throw new RuntimeException('สร้างการยืมไม่สำเร็จ: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    }
    $st = $pdo->prepare("SELECT id FROM borrows WHERE user_id = ? AND book_id = ?
                         AND status = 'borrowing' ORDER BY id DESC LIMIT 1");
    $st->execute([$userId, $bookId]);
    $bid = (int) $st->fetchColumn();
    $pdo->prepare("UPDATE borrows SET due_date = DATE_ADD(CURDATE(), INTERVAL ? DAY) WHERE id = ?")
        ->execute([$dayOffset, $bid]);
    $madeBorrows[] = $bid;
    return $bid;
};

// 👤 ก. — เกินกำหนด 1 เล่ม
$alice = $mkMember('Alice');
$mkBorrow($alice['id'], $mkBook('เล่มของ Alice'), -5);

// 👤 ข. — ใกล้ครบกำหนด 2 เล่ม (คนละสถานการณ์กับ ก. โดยตั้งใจ)
$bob = $mkMember('Bob');
$mkBorrow($bob['id'], $mkBook('เล่ม Bob 1'), 0);
$mkBorrow($bob['id'], $mkBook('เล่ม Bob 2'), 1);

$repo = new \App\Repositories\BorrowRepository($pdo);
$countOf = fn(int $uid) => $repo->getMemberAlertCounts($uid, (int) DUE_SOON_DAYS);

// ============================================================
// A. ตัวเลขตรงกับของสมาชิกคนนั้น
// ============================================================
echo "── A. ตัวเลขตรงกับ query ที่ scope ด้วย user ──\n";

$aCounts = $countOf($alice['id']);
$bCounts = $countOf($bob['id']);

$rawOverdue = (int) $pdo->query("SELECT COUNT(*) FROM borrows
    WHERE user_id = {$alice['id']} AND status = 'borrowing' AND due_date < CURDATE()")->fetchColumn();
check('MBELL-A1', $aCounts['overdue'] === $rawOverdue && $rawOverdue === 1,
    "ก. เกินกำหนด {$aCounts['overdue']} เล่ม ตรงกับ query",
    "🔴 กระดิ่ง {$aCounts['overdue']} · query {$rawOverdue}");

$rawDueSoon = (int) $pdo->query("SELECT COUNT(*) FROM borrows
    WHERE user_id = {$bob['id']} AND status = 'borrowing' AND due_date >= CURDATE()
      AND due_date <= DATE_ADD(CURDATE(), INTERVAL " . (int) DUE_SOON_DAYS . " DAY)")->fetchColumn();
check('MBELL-A2', $bCounts['due_soon'] === $rawDueSoon && $rawDueSoon === 2,
    "ข. ใกล้ครบกำหนด {$bCounts['due_soon']} เล่ม ตรงกับ query",
    "🔴 กระดิ่ง {$bCounts['due_soon']} · query {$rawDueSoon}");

check('MBELL-A3', $aCounts['total'] === array_sum(array_slice($aCounts, 0, 4)),
    'ยอดรวมบนป้าย = ผลบวกของทุกรายการ',
    '🔴 ยอดรวมไม่ตรงกับผลบวก');

// ============================================================
// B. ห้ามเห็นตัวเลขของคนอื่น
// ============================================================
echo "\n── B. ข้อมูลต้องไม่ข้ามคน ──\n";

/**
 * 🔴 เคสสำคัญที่สุดของชุดนี้ — กระดิ่งอยู่ใน header ที่ทุกหน้าฝั่งผู้ใช้ include
 *    ถ้า scope พลาด สมาชิกจะเห็นสถานะของคนอื่นทุกหน้าที่เปิด
 *    `$userId` ต้องมาจาก session เท่านั้น ห้ามรับจาก URL (IDOR)
 */
$jarA = tempnam(sys_get_temp_dir(), 'mba'); $jars[] = $jarA;
$jarB = tempnam(sys_get_temp_dir(), 'mbb'); $jars[] = $jarB;

httpAs($jarA, 'POST', "{$BASE_URL}/login.php", [
    'csrf_token' => csrfFrom(httpAs($jarA, 'GET', "{$BASE_URL}/login.php")),
    'email' => $alice['email'], 'password' => $PASSWORD]);
httpAs($jarB, 'POST', "{$BASE_URL}/login.php", [
    'csrf_token' => csrfFrom(httpAs($jarB, 'GET', "{$BASE_URL}/login.php")),
    'email' => $bob['email'], 'password' => $PASSWORD]);

$pageA = httpAs($jarA, 'GET', "{$BASE_URL}/index.php");
$pageB = httpAs($jarB, 'GET', "{$BASE_URL}/index.php");
$badgeA = memberBadge($pageA);
$badgeB = memberBadge($pageB);

check('MBELL-B1', $badgeA === (string) $aCounts['total'] && $badgeB === (string) $bCounts['total'],
    "ก. เห็น {$badgeA} · ข. เห็น {$badgeB} — ตรงกับของตัวเองทั้งคู่",
    "🔴 ก. เห็น " . var_export($badgeA, true) . " (ควรเป็น {$aCounts['total']}) · "
        . "ข. เห็น " . var_export($badgeB, true) . " (ควรเป็น {$bCounts['total']})");

check('MBELL-B2', $aCounts['total'] !== $bCounts['total'],
    "สองคนมีตัวเลขต่างกัน ({$aCounts['total']} vs {$bCounts['total']}) — เคส B1 จึงแยกความต่างได้จริง",
    '🔴 สองคนมีตัวเลขเท่ากัน เคส B1 ผ่านแบบไม่ได้ตรวจอะไร ต้องแก้ fixture');

/**
 * 🛡️ B3 — ตรวจ scope แบบไขว้ทีละช่อง ไม่ใช่แค่ยอดรวม
 *
 * 🔴 เคยเขียนผิด: ตรวจว่าชื่อหนังสือของอีกฝ่ายโผล่ในหน้าของเราไหม
 *    ซึ่ง**ผิดตั้งแต่ต้น** เพราะ `index.php` เป็น **แคตตาล็อกสาธารณะ**
 *    หนังสือของทุกคนอยู่ที่นั่นอยู่แล้วโดยตั้งใจ ไม่ใช่การรั่ว
 *
 * 🧠 ของที่ต้องไม่ข้ามคนคือ **ตัวเลขสถานะ** ต่างหาก
 *    ก. มีเฉพาะเกินกำหนด · ข. มีเฉพาะใกล้ครบกำหนด
 *    ถ้า WHERE user_id หลุดไปช่องใดช่องหนึ่ง ตัวเลขจะปนกันทันที
 */
$crossLeak = [];
if ($aCounts['due_soon'] !== 0)     $crossLeak[] = "ก. เห็นใกล้ครบกำหนด {$aCounts['due_soon']} (ควรเป็น 0 — เป็นของ ข.)";
if ($bCounts['overdue'] !== 0)      $crossLeak[] = "ข. เห็นเกินกำหนด {$bCounts['overdue']} (ควรเป็น 0 — เป็นของ ก.)";
if ($aCounts['ready_pickup'] !== 0) $crossLeak[] = "ก. เห็นจองรอรับ {$aCounts['ready_pickup']} ทั้งที่ไม่ได้จอง";
if ($bCounts['ready_pickup'] !== 0) $crossLeak[] = "ข. เห็นจองรอรับ {$bCounts['ready_pickup']} ทั้งที่ไม่ได้จอง";

check('MBELL-B3', !$crossLeak,
    'ตรวจไขว้ทีละช่อง — ก. ไม่เห็นของ ข. และกลับกัน',
    "🔴 ตัวเลขปนกันข้ามคน:\n       " . implode("\n       ", $crossLeak));

// ============================================================
// C. ยังไม่ล็อกอิน
// ============================================================
echo "\n── C. ยังไม่ล็อกอิน ──\n";

/**
 * 🔴 `includes/header.php` ใช้กับหน้า index/login/register ที่คนทั่วไปเปิด
 *    ยิง query ตรงนั้น = จ่ายฟรีทุกครั้งที่มีคนเข้าหน้าแรก
 */
$jarAnon = tempnam(sys_get_temp_dir(), 'mb0'); $jars[] = $jarAnon;
$anon = httpAs($jarAnon, 'GET', "{$BASE_URL}/index.php");
check('MBELL-C1', !str_contains($anon, 'การแจ้งเตือนของฉัน') && memberBadge($anon) === null,
    'คนที่ยังไม่ล็อกอินไม่เห็นกระดิ่งเลย',
    '🔴 กระดิ่งโผล่ให้คนที่ยังไม่ล็อกอิน');

$headerSrc = (string) file_get_contents(dirname(__DIR__) . '/includes/header.php');
check('MBELL-C2', preg_match('/if\s*\(\s*isLoggedIn\(\)\s*\)\s*\{/', $headerSrc) === 1
        && str_contains($headerSrc, 'getMemberAlertCounts'),
    'header ยิง query เฉพาะตอนล็อกอิน (ครอบด้วย isLoggedIn())',
    '🔴 ไม่มีด่าน isLoggedIn() ครอบ — หน้าแรกจะยิง query ทุกครั้งที่มีคนเปิด');

// 🔴 ห้ามรับ user id จากภายนอก
check('MBELL-C3', !preg_match('/getMemberAlertCounts\(\s*\(int\)\s*\$_(GET|POST|REQUEST)/', $headerSrc),
    'user id มาจาก session เท่านั้น ไม่รับจาก URL',
    '🔴 รับ user id จากพารามิเตอร์ภายนอก — สมาชิกจะสอดส่องตัวเลขคนอื่นได้');

// ============================================================
// D. ลิงก์และจุดแดง
// ============================================================
echo "\n── D. ลิงก์และจุดแดง ──\n";

preg_match_all('/href="([^"]+)"[^>]*class="flex items-center justify-between/', $pageA, $lm);
$links = array_values(array_unique($lm[1] ?? []));
$broken = [];
foreach ($links as $u) {
    $body = httpAs($jarA, 'GET', $u);
    if (str_contains($body, 'เข้าสู่ระบบ</h2>') || $body === '') $broken[] = $u;
}
check('MBELL-D1', $links && !$broken,
    'ลิงก์ในกระดิ่งเปิดได้ทุกอัน (' . count($links) . ' รายการ)',
    $links ? "🔴 เปิดไม่ได้: " . implode(', ', $broken) : '🔴 ไม่มีลิงก์ในกระดิ่งเลย');

// 👤 สมาชิกที่ไม่มีอะไรค้าง → ต้องไม่มีจุดแดง
$clean = $mkMember('Clean');
$jarC = tempnam(sys_get_temp_dir(), 'mbc'); $jars[] = $jarC;
httpAs($jarC, 'POST', "{$BASE_URL}/login.php", [
    'csrf_token' => csrfFrom(httpAs($jarC, 'GET', "{$BASE_URL}/login.php")),
    'email' => $clean['email'], 'password' => $PASSWORD]);
$pageC = httpAs($jarC, 'GET', "{$BASE_URL}/index.php");
check('MBELL-D2', memberBadge($pageC) === null && str_contains($pageC, 'ไม่มีอะไรต้องจัดการ'),
    'สมาชิกที่ไม่มีอะไรค้าง → ไม่มีจุดแดง และขึ้นข้อความว่าไม่มีอะไรต้องจัดการ',
    '🔴 ป้ายแดงขึ้นทั้งที่ไม่มีอะไรค้าง — จุดแดงไม่ได้ผูกกับข้อมูลจริง');

// ============================================================
echo "\n══════════════════════════════════════\n";
printf(" RESULTS: %d/%d passed (%.1f%%)%s\n",
    $results['passed'], $results['total'],
    $results['total'] ? $results['passed'] / $results['total'] * 100 : 0,
    $results['failed'] ? ' | ' . $results['failed'] . ' FAILED' : '');
echo "══════════════════════════════════════\n";

exit($results['failed'] > 0 ? 1 : 0);
