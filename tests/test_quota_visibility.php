<?php

/**
 * ทดสอบ "ภาพโควตาและการจองที่ไม่มารับ" — F-41 + F-42
 *
 * ==========================================================================
 * 🔴 ปัญหาเดิม
 * ==========================================================================
 * F-41 — admin/members.php แสดงคอลัมน์ "กำลังยืม" ที่นับเฉพาะ borrows
 *        ทั้งที่โควตานับ borrows + pending reservations
 *        "สมชาย ใจดี" ยืม 2 + จอง 1 = เต็ม 3/3 แต่หน้าจอแสดง "2 เล่ม"
 *        เจ้าหน้าที่สรุปว่ายืมได้อีก 1 แต่จริง ๆ ยืมไม่ได้แล้ว
 *        และข้อความปฏิเสธก็ไม่บอกว่าเล่มที่ 3 คือการจอง
 *
 * F-42 — การจองที่หมดอายุมองไม่เห็น เพราะ lazy expire เคลียร์ก่อนหน้าจอ render
 *        ไม่มีแท็บ "หมดอายุ" · ช่องจัดการเขียนว่า "ดำเนินการแล้ว" ทำอะไรต่อไม่ได้
 *
 * ==========================================================================
 * 🔴 สิ่งที่ทำให้ยากกว่าที่ FINDINGS เขียนไว้
 * ==========================================================================
 * ตอนเขียน FINDINGS ระบบมีการจองชนิดเดียว ตอนนี้มี 2 ชนิดที่นับคนละแบบ:
 *   pending (ของพร้อม รอมารับ) → **กินโควตายืม**
 *   waiting (ต่อคิวรอ)         → **ไม่กินโควตายืม** มีเพดานของตัวเองแยก
 * ถ้าเอา waiting ไปรวมในตัวเลขโควตา จะกลายเป็นตัวเลขผิดชนิดใหม่ —
 * คนที่ต่อคิว 3 เล่มจะขึ้นว่าเต็มโควตา ทั้งที่ยืมได้อีก 3 เล่ม
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. ตัวเลขโควตาในหน้าจัดการผู้ใช้ — รวม pending · **ไม่รวม waiting**
 * B. ข้อความปฏิเสธบอกที่มาของตัวเลข
 * C. แท็บ "ไม่มารับ" กรองได้จริง · ตัวเลขตรงกับ SQL · มีทางไปต่อ
 * D. ประสิทธิภาพ — หน้ารายชื่อสมาชิกต้องไม่ช้าจนสังเกตได้
 *
 * 🧹 ลบทุกอย่างที่สร้างขึ้น — อยู่ใน register_shutdown_function
 *
 * 📌 การใช้งาน: php tests/test_quota_visibility.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/BorrowService.php';
require_once __DIR__ . '/../app/Services/ReservationService.php';

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

$pdo       = getDB();
$borrowSvc = new App\Services\BorrowService($pdo);
$resSvc    = new App\Services\ReservationService($pdo);
$bookRepo  = new App\Repositories\BookRepository($pdo);
$resRepo   = new App\Repositories\ReservationRepository($pdo);
$COOKIE    = tempnam(sys_get_temp_dir(), 'bbquota');

const TAG = '[QUOTATEST]';

$created = ['books' => [], 'users' => []];
$cleanupDone = false;
$cleanup = function () use (&$created, &$cleanupDone, $pdo, $COOKIE) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    try {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ([['books', 'book_id'], ['users', 'user_id']] as [$k, $col]) {
            if (!$created[$k]) continue;
            $in = implode(',', array_map('intval', $created[$k]));
            $pdo->exec("DELETE FROM reservations WHERE {$col} IN ($in)");
            $pdo->exec("DELETE FROM payments WHERE borrow_id IN (SELECT id FROM borrows WHERE {$col} IN ($in))");
            $pdo->exec("DELETE FROM borrows WHERE {$col} IN ($in)");
        }
        if ($created['books']) $pdo->exec("DELETE FROM books WHERE id IN (" . implode(',', array_map('intval', $created['books'])) . ")");
        if ($created['users']) $pdo->exec("DELETE FROM users WHERE id IN (" . implode(',', array_map('intval', $created['users'])) . ")");
        echo '  ลบหนังสือ ' . count($created['books']) . ' เล่ม · สมาชิก ' . count($created['users']) . " คน\n";
    } catch (Throwable $e) {
        echo '  ⚠️ ล้างข้อมูลไม่ครบ: ' . $e->getMessage() . "\n";
    }
    @unlink($COOKIE);
};
register_shutdown_function($cleanup);

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  ภาพโควตา + การจองที่ไม่มารับ (F-41 + F-42)               ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$catId = (int) $pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn();

$mkBook = function (string $title, int $qty) use ($bookRepo, $catId, &$created): int {
    $id = $bookRepo->create(['title' => $title, 'author' => 'ผู้แต่งทดสอบ', 'category_id' => $catId, 'quantity' => $qty]);
    $created['books'][] = $id;
    return $id;
};
$mkUser = function (string $suffix) use ($pdo, &$created): int {
    $email = "quotatest_{$suffix}_" . time() . rand(100, 999) . "@test.com";
    $st = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'member')");
    $st->execute([TAG . " ผู้ใช้ {$suffix}", $email, password_hash('Test12345', PASSWORD_DEFAULT)]);
    $id = (int) $pdo->lastInsertId();
    $created['users'][] = $id;
    return $id;
};
$mkBorrow = function (int $userId, int $bookId) use ($pdo): int {
    $st = $pdo->prepare("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status)
        VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'borrowing')");
    $st->execute([$userId, $bookId]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?")->execute([$bookId]);
    return $id;
};

// 👤 คนที่ 1 — ยืม 2 + จอง 1 = เต็มโควตา (เคสของ F-41 เป๊ะ ๆ)
$uFull  = $mkUser('full');
$bkA    = $mkBook(TAG . ' เล่ม A', 3);
$bkB    = $mkBook(TAG . ' เล่ม B', 3);
$bkC    = $mkBook(TAG . ' เล่ม C', 3);
$mkBorrow($uFull, $bkA);
$mkBorrow($uFull, $bkB);
$resSvc->createReservation($uFull, $bkC);   // pending — กินโควตา

// 👤 คนที่ 2 — ต่อคิวรอ 3 เล่ม แต่ไม่ได้ยืมอะไรเลย (คิวไม่กินโควตา)
$uQueue  = $mkUser('queue');
$holder  = $mkUser('holder');
$queueBooks = [];
for ($i = 1; $i <= 3; $i++) {
    $b = $mkBook(TAG . " เล่มคิว {$i}", 1);
    $queueBooks[] = $b;
    $mkBorrow($holder, $b);       // ทำให้ถูกยืมหมด
    $resSvc->joinQueue($uQueue, $b);
}

echo "  📦 fixture: ผู้ใช้เต็มโควตา (ยืม 2 + จอง 1) · ผู้ใช้ต่อคิว 3 เล่ม\n\n";

// ============================================================
// A. ตัวเลขโควตา
// ============================================================
echo "── A. ตัวเลขโควตาในหน้าจัดการผู้ใช้ ──\n";

require_once __DIR__ . '/../app/Repositories/UserRepository.php';
$userRepo = new App\Repositories\UserRepository($pdo);

$rowFull  = null;
$rowQueue = null;
foreach ($userRepo->findMembers(['search' => TAG, 'limit' => 50]) as $r) {
    if ((int) $r['id'] === $uFull)  $rowFull  = $r;
    if ((int) $r['id'] === $uQueue) $rowQueue = $r;
}

check('QUOTA-A1',
    $rowFull !== null && isset($rowFull['pending_reservations'], $rowFull['waiting_reservations']),
    'query ของหน้าสมาชิกดึงทั้ง pending และ waiting มาให้',
    '🔴 ไม่มีคอลัมน์ที่ต้องใช้ — หน้าจอจะแสดงภาพโควตาไม่ครบ');

if ($rowFull) {
    $used = (int) $rowFull['active_borrows'] + (int) $rowFull['pending_reservations'];
    check('QUOTA-A2',
        (int) $rowFull['active_borrows'] === 2 && (int) $rowFull['pending_reservations'] === 1 && $used === MAX_BORROW_BOOKS,
        "คนที่ยืม 2 + จอง 1 → โควตาที่ใช้ {$used}/" . MAX_BORROW_BOOKS . ' (เต็ม)',
        '🔴 ตัวเลขผิด: ยืม ' . $rowFull['active_borrows'] . ' จอง ' . $rowFull['pending_reservations']);
}

// A3 — 🔴 คิวรอต้องไม่ถูกนับเข้าโควตา
if ($rowQueue) {
    $usedQ = (int) $rowQueue['active_borrows'] + (int) $rowQueue['pending_reservations'];
    check('QUOTA-A3',
        (int) $rowQueue['waiting_reservations'] === 3 && $usedQ === 0,
        "คนที่ต่อคิว 3 เล่ม → โควตาที่ใช้ {$usedQ}/" . MAX_BORROW_BOOKS . ' · คิว 3 (แยกกัน)',
        '🔴 คิวรอถูกนับเข้าโควตา (ใช้ ' . $usedQ . ') — คนนั้นยังยืมได้อีก ' . MAX_BORROW_BOOKS . ' เล่ม');
}

// ============================================================
// B. ข้อความปฏิเสธ
// ============================================================
echo "\n── B. ข้อความปฏิเสธบอกที่มาของตัวเลข ──\n";

$freeBook = $mkBook(TAG . ' เล่มว่าง', 5);

try {
    $borrowSvc->createBorrow($uFull, [$freeBook]);
    fail('QUOTA-B1', 'ยืมผ่านทั้งที่เต็มโควตา');
} catch (Exception $e) {
    $msg = $e->getMessage();
    check('QUOTA-B1',
        str_contains($msg, 'ยืมอยู่ 2') && str_contains($msg, 'จองรอรับอีก 1'),
        'ข้อความบอกที่มาครบ: ' . $msg,
        '🔴 ข้อความไม่บอกว่าโควตาถูกใช้ไปกับอะไร: ' . $msg);
}

// B2 — 🔴 คนที่ต่อคิวอยู่ต้องยืมได้ตามปกติ (คิวไม่กินโควตา)
try {
    $borrowSvc->createBorrow($uQueue, [$freeBook]);
    pass('QUOTA-B2', 'คนที่ต่อคิว 3 เล่มยังยืมได้ — คิวไม่กินโควตา');
} catch (Exception $e) {
    fail('QUOTA-B2', '🔴 คิวรอไปกินโควตายืม: ' . $e->getMessage());
}

// ============================================================
// C. การจองที่ไม่มารับ
// ============================================================
echo "\n── C. การจองที่ไม่มารับ ──\n";

// 🕐 สร้างการจองที่หมดอายุแล้ว
$expBook = $mkBook(TAG . ' เล่มหมดอายุ', 2);
$uExp    = $mkUser('expired');
$resSvc->createReservation($uExp, $expBook);
$expResId = (int) $pdo->query("SELECT id FROM reservations WHERE user_id = {$uExp} AND book_id = {$expBook}")->fetchColumn();
$pdo->exec("UPDATE reservations SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = {$expResId}");
(new App\Repositories\ReservationRepository($pdo))->markExpiredReservations();

check('QUOTA-C1',
    $pdo->query("SELECT status FROM reservations WHERE id = {$expResId}")->fetchColumn() === 'expired',
    'การจองที่เลยกำหนดถูกเปลี่ยนเป็น "หมดอายุ"',
    'lazy expire ไม่ทำงาน');

// C2 — ตัวนับต้องมาจาก query ที่ไม่มี LIMIT
$sqlExpired = (int) $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'expired'")->fetchColumn();
$repoExpired = $resRepo->countAll(['status' => 'expired']);
check('QUOTA-C2',
    $repoExpired === $sqlExpired,
    "ตัวนับ \"ไม่มารับ\" = {$repoExpired} ตรงกับ SQL",
    "🔴 ตัวนับไม่ตรง: repo={$repoExpired} SQL={$sqlExpired}");

// C3 — ตัวนับรายเดือน
$sqlMonth = (int) $pdo->query("
    SELECT COUNT(*) FROM reservations
    WHERE status = 'expired' AND MONTH(expires_at) = MONTH(CURDATE()) AND YEAR(expires_at) = YEAR(CURDATE())
")->fetchColumn();
check('QUOTA-C3',
    $resRepo->countExpiredThisMonth() === $sqlMonth,
    "\"ไม่มารับเดือนนี้\" = {$sqlMonth} ตรงกับ SQL",
    '🔴 ตัวนับรายเดือนไม่ตรง: ' . $resRepo->countExpiredThisMonth() . " vs {$sqlMonth}");

// ============================================================
// D–E. ผ่านหน้าเว็บจริง
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
    $time = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'code' => $code, 'time' => $time];
}

function csrfFrom(string $html): string
{
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

/**
 * ดึงแถวของสมาชิกคนหนึ่งจากตาราง
 *
 * 🧠 หน้าต่างต้องกว้างพอ — แถวหนึ่งยาวราว 3,000 ตัวอักษรเพราะมีปุ่มจัดการหลายปุ่ม
 *    เคยตั้งไว้ 2,500 แล้วหา </tr> ไม่เจอ เคสเลยแดงทั้งที่หน้าเว็บถูกต้อง
 */
function quotaCell(string $html, string $needle): string
{
    if (!preg_match('/' . preg_quote($needle, '/') . '.{0,8000}?<\/tr>/s', $html, $m)) return '';
    return $m[0];
}

$login = http('GET', "$BASE_URL/login.php");
$r = http('POST', "$BASE_URL/login.php", [
    'csrf_token' => csrfFrom($login['body']),
    'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD,
]);

if (!str_contains($r['body'], 'ออกจากระบบ') && !str_contains($r['body'], 'logout')) {
    fail('QUOTA-D1', 'ล็อกอินไม่สำเร็จ — ข้ามการทดสอบผ่านหน้าเว็บ');
} else {
    echo "\n── D. หน้าจัดการผู้ใช้ (HTTP) ──\n";

    $mPage = http('GET', "$BASE_URL/admin/members.php?search=" . rawurlencode(TAG));
    $cellFull  = quotaCell($mPage['body'], TAG . ' ผู้ใช้ full');
    $cellQueue = quotaCell($mPage['body'], TAG . ' ผู้ใช้ queue');

    check('QUOTA-D1',
        str_contains($cellFull, '3/3') && str_contains($cellFull, 'ยืม 2') && str_contains($cellFull, 'จอง 1'),
        'แถวคนเต็มโควตาแสดง "3/3" พร้อมที่มา "ยืม 2 · จอง 1"',
        '🔴 ไม่แสดงภาพโควตาครบ — เจ้าหน้าที่จะสรุปผิดว่ายืมได้อีก');

    // 🧠 ดึง "ตัวเลขโควตา" ออกมาเทียบตรง ๆ แทนการเขียนเป็นเงื่อนไขปฏิเสธ
    //    (เวอร์ชันแรกใช้ !preg_match ว่าไม่มี "3/3" ซึ่งเปราะและไม่แดงตอนทดสอบด้วยการทำพัง)
    $queueQuota = preg_match('/(\d+)\/' . MAX_BORROW_BOOKS . '/', strip_tags($cellQueue), $qm)
        ? (int) $qm[1]
        : 0;   // ไม่มี badge เลย = ใช้โควตา 0

    // 🧠 เทียบกับค่าจริงในฐานข้อมูล ณ ตอนนั้น ไม่ hardcode เป็น 0
    //    เพราะเคส B2 ด้านบนให้คนนี้ยืมไป 1 เล่มเพื่อพิสูจน์ว่าคิวไม่กินโควตา
    //    สิ่งที่ต้องยืนยันคือ "badge = ยืม + จอง" และ **ไม่บวกคิว** ต่างหาก
    $qRow = $pdo->query("
        SELECT (SELECT COUNT(*) FROM borrows WHERE user_id = {$uQueue} AND status = 'borrowing') AS b,
               (SELECT COUNT(*) FROM reservations WHERE user_id = {$uQueue} AND status = 'pending') AS p,
               (SELECT COUNT(*) FROM reservations WHERE user_id = {$uQueue} AND status = 'waiting') AS w
    ")->fetch();
    $expectQuota = (int) $qRow['b'] + (int) $qRow['p'];

    check('QUOTA-D2',
        $queueQuota === $expectQuota && (int) $qRow['w'] === 3 && str_contains($cellQueue, 'คิว 3'),
        "คนต่อคิว: badge = {$queueQuota}/" . MAX_BORROW_BOOKS
            . " ตรงกับ ยืม {$qRow['b']} + จอง {$qRow['p']} · คิว {$qRow['w']} แสดงแยก ไม่ถูกบวกเข้าโควตา",
        "🔴 badge = {$queueQuota} แต่ ยืม {$qRow['b']} + จอง {$qRow['p']} = {$expectQuota}"
            . " (คิว {$qRow['w']}) — คิวรอถูกนับเข้าโควตา ทั้งที่ไม่ควร");

    check('QUOTA-D3',
        str_contains($mPage['body'], 'โควตาที่ใช้'),
        'หัวคอลัมน์เปลี่ยนเป็น "โควตาที่ใช้" ตรงกับสิ่งที่แสดงจริง',
        'หัวคอลัมน์ยังเขียนว่า "กำลังยืม" ทั้งที่แสดงโควตารวม');

    echo "\n── E. หน้ารายการจอง (HTTP) ──\n";

    $rPage = http('GET', "$BASE_URL/admin/reservations.php?status=expired");
    check('QUOTA-E1',
        $rPage['code'] === 200 && str_contains($rPage['body'], 'ไม่มารับ'),
        'มีแท็บ "ไม่มารับ" และกรองได้',
        '🔴 ไม่มีแท็บสำหรับดูรายการที่จองแล้วไม่มารับ');

    // E2 — ตัวเลขบนแท็บต้องตรงกับความจริง
    preg_match('/ไม่มารับ\s*<span[^>]*>([0-9,]+)<\/span>/u', $rPage['body'], $badge);
    $badgeNum = isset($badge[1]) ? (int) str_replace(',', '', $badge[1]) : -1;
    check('QUOTA-E2',
        $badgeNum === $sqlExpired,
        "ตัวเลขบนแท็บ = {$badgeNum} ตรงกับ SQL ({$sqlExpired})",
        "🔴 ตัวเลขบนแท็บ {$badgeNum} ไม่ตรงกับ SQL {$sqlExpired}");

    // E3 — 🔴 แถวที่ไม่มารับต้องมีทางไปต่อ ไม่ใช่ "ดำเนินการแล้ว" เฉย ๆ
    check('QUOTA-E3',
        str_contains($rPage['body'], 'ให้ยืมเลย'),
        'แถวที่ไม่มารับมีปุ่ม "ให้ยืมเลย" — สมาชิกมาช้าแต่ยังอยากได้',
        '🔴 ยังเขียนว่า "ดำเนินการแล้ว" ทำอะไรต่อไม่ได้');

    // E4 — ปุ่มต้องพาไปฟอร์มที่เลือกสมาชิก+หนังสือไว้ให้แล้ว
    if (preg_match('/borrow_form\.php\?user_id=(\d+)(?:&amp;|&)book_id=(\d+)/', $rPage['body'], $lm)) {
        $formPage = http('GET', "$BASE_URL/admin/borrow_form.php?user_id={$lm[1]}&book_id={$lm[2]}");
        check('QUOTA-E4',
            preg_match('/<option value="' . $lm[1] . '"[^>]*selected/', $formPage['body']) === 1
                && preg_match('/<option value="' . $lm[2] . '" selected/', $formPage['body']) === 1,
            "ปุ่มพาไปฟอร์มที่เลือกสมาชิก #{$lm[1]} และหนังสือ #{$lm[2]} ไว้ให้แล้ว",
            '🔴 ปุ่มพาไปฟอร์มเปล่า — เจ้าหน้าที่ต้องค้นหาเองใหม่ทั้งคู่');
    } else {
        fail('QUOTA-E4', '🔴 ไม่พบลิงก์ที่พาสมาชิก+หนังสือไปด้วย');
    }

    // E5 — การ์ดสรุปบอกว่าเดือนนี้เกิดกี่ครั้ง
    $rDefault = http('GET', "$BASE_URL/admin/reservations.php");
    check('QUOTA-E5',
        $sqlMonth === 0 || str_contains($rDefault['body'], 'เดือนนี้มีการจองที่ไม่มารับ'),
        $sqlMonth === 0
            ? 'เดือนนี้ยังไม่มีรายการไม่มารับ — ไม่ต้องแสดงการ์ด (ถูกต้อง)'
            : 'หน้าแรกของรายการจองบอกว่าเดือนนี้มีคนไม่มารับกี่ครั้ง',
        '🔴 ไม่มีตัวเลขบอกว่าปัญหานี้เกิดบ่อยแค่ไหน');

    echo "\n── F. ประสิทธิภาพ ──\n";

    // F1 — หน้ารายชื่อสมาชิกต้องไม่ช้าจนสังเกตได้ (เพิ่ม subquery ไป 1 ตัว)
    $times = [];
    for ($i = 0; $i < 5; $i++) {
        $times[] = http('GET', "$BASE_URL/admin/members.php")['time'];
    }
    sort($times);
    $median = $times[2];
    check('QUOTA-F1', $median < 0.5,
        'หน้ารายชื่อสมาชิก (204 คน) โหลดใน ' . round($median * 1000) . ' ms',
        '🔴 ช้าเกินไป: ' . round($median * 1000) . ' ms — subquery ที่เพิ่มมาแพงเกินรับ');
}

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
