<?php

/**
 * โควตาต่างกันตามประเภทสมาชิก — งานประจำข้อ 8
 *
 * ==========================================================================
 * 🔴 ปัญหาเดิม
 * ==========================================================================
 * โควตาตายตัว 3 เล่ม/คน ใช้กับทุกคนเท่ากัน
 * ห้องสมุดทั่วไปมักให้เจ้าหน้าที่/สมาชิกพิเศษยืมได้มากกว่าสมาชิกทั่วไป
 * (ระบบมี 3 role อยู่แล้ว แต่ไม่มี borrow policy ผูกกับ role)
 *
 * ==========================================================================
 * 🔴 ความเสี่ยงหลักของงานนี้: แก้ไม่ครบทุกจุด
 * ==========================================================================
 * `MAX_BORROW_BOOKS` ถูกใช้ **9 จุดในโค้ดจริง ข้าม 5 ไฟล์**:
 *   ด่านยืม · ด่านจอง · ด่านต่อคิว · ด่านเลื่อนคิว ·
 *   ตัวกรอง "เต็มโควตา" (F-48) · badge หน้ารายชื่อสมาชิก · ฟอร์มบันทึกการยืม
 *
 * ถ้าเหลือจุดใดใช้ค่าเดิม จะเกิดสภาพ **หน้าจอบอกอย่าง ระบบทำอีกอย่าง**
 * เช่น หน้าจอให้เลือกได้ 3 เล่ม แต่ด่านหลังบ้านยอม 10
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. helper กลาง `quotaForRole()` — role ที่ไม่รู้จักต้องได้ค่าที่เข้มที่สุด
 * B. ด่านจริง — เจ้าหน้าที่ยืมได้เกินเพดานสมาชิก · สมาชิกยังถูกกันที่เพดานเดิม
 * C. 🔴 ทุกหน้าจอต้องแสดงเพดานของ role นั้น ไม่ใช่ค่าเดียวทั้งระบบ
 * D. 🔴 ไม่เหลือจุดไหนที่ยังตัดสินด้วยค่าตายตัว (ตรวจจากซอร์ส)
 * E. ค่าเริ่มต้นเท่ากัน → ลูกค้าเดิมที่อัปเกรดพฤติกรรมไม่เปลี่ยน
 *
 * 🧹 คืนค่าตั้งค่าเดิม + ลบ fixture ทั้งหมด
 *
 * 📌 การใช้งาน: php tests/test_role_quota.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/BorrowService.php';
require_once __DIR__ . '/../app/Services/BookService.php';

$ROOT           = dirname(__DIR__);
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

$pdo    = getDB();
$COOKIE = tempnam(sys_get_temp_dir(), 'bbrq');

// 🔴 จำค่าที่ลูกค้าตั้งไว้ก่อน แล้วคืนตอนจบ — ห้ามทิ้งค่าของเทสต์ไว้ในระบบจริง
$prevStaffSetting = $pdo->query("
    SELECT setting_value FROM settings WHERE setting_key = 'rule_max_books_staff'
")->fetchColumn();

$madeBooks = $madeUsers = $madeBorrows = [];
$cleanupDone = false;
$cleanup = function () use (
    &$madeBooks, &$madeUsers, &$madeBorrows, &$cleanupDone, $pdo, $COOKIE, $prevStaffSetting
) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    try {
        if ($pdo->inTransaction()) $pdo->rollBack();
    } catch (Throwable $e) {
        echo '  ⚠️ rollback ไม่สำเร็จ: ' . $e->getMessage() . "\n";
    }

    // ⚙️ คืนค่าตั้งค่าเดิมก่อนอย่างอื่น — ถ้าลืม ระบบจริงจะค้างเพดานของเทสต์ไว้
    try {
        if ($prevStaffSetting === false) {
            $pdo->prepare("DELETE FROM settings WHERE setting_key = 'rule_max_books_staff'")->execute();
            echo "  คืนค่าเพดานเจ้าหน้าที่กลับเป็น default (ไม่มีค่าที่ตั้งไว้เดิม)\n";
        } else {
            $pdo->prepare("
                INSERT INTO settings (setting_key, setting_value) VALUES ('rule_max_books_staff', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ")->execute([$prevStaffSetting]);
            echo "  คืนค่าเพดานเจ้าหน้าที่กลับเป็น {$prevStaffSetting}\n";
        }
    } catch (Throwable $e) {
        echo '  🔴 คืนค่าตั้งค่าไม่สำเร็จ ต้องแก้มือ: ' . $e->getMessage() . "\n";
    }

    $failed = [];
    foreach ($madeBorrows as $bw) {
        try {
            $pdo->prepare("DELETE FROM payments WHERE borrow_id = ?")->execute([$bw['id']]);
            $pdo->prepare("DELETE FROM borrows WHERE id = ?")->execute([$bw['id']]);
            if ($bw['held']) {
                $pdo->prepare("UPDATE books SET available = available + 1 WHERE id = ?")->execute([$bw['book_id']]);
            }
        } catch (Throwable $e) { $failed[] = "borrow#{$bw['id']}"; }
    }
    foreach ($madeUsers as $uid) {
        try {
            $pdo->prepare("DELETE FROM reservations WHERE user_id = ?")->execute([$uid]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
        } catch (Throwable $e) { $failed[] = "user#{$uid}"; }
    }
    foreach ($madeBooks as $bid) {
        try { $pdo->prepare("DELETE FROM books WHERE id = ?")->execute([$bid]); }
        catch (Throwable $e) { $failed[] = "book#{$bid}"; }
    }
    echo '  ลบหนังสือ ' . count($madeBooks) . ' · สมาชิก ' . count($madeUsers)
        . ' · การยืม ' . count($madeBorrows) . "\n";
    if ($failed) echo '  🔴 ลบไม่สำเร็จ: ' . implode(' · ', $failed) . "\n";

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
    } catch (Throwable $e) {
        echo '  ⚠️ ตรวจ invariant ไม่ได้: ' . $e->getMessage() . "\n";
    }
    @unlink($COOKIE);
};
register_shutdown_function($cleanup);

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  โควตาต่างกันตามประเภทสมาชิก                              ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

function http(string $method, string $url, array $fields = []): string
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $COOKIE,
        CURLOPT_COOKIEFILE     => $COOKIE,
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

// ============================================================
// E. ค่าเริ่มต้นต้องเท่ากัน (ตรวจก่อนไปยุ่งกับค่าตั้งค่า)
// ============================================================
echo "── E. ค่าเริ่มต้นสำหรับลูกค้าที่อัปเกรด ──\n";

$defs = ruleDefinitions();
check('RQ-E1',
    isset($defs['MAX_BORROW_BOOKS_STAFF'])
        && $defs['MAX_BORROW_BOOKS_STAFF']['default'] === $defs['MAX_BORROW_BOOKS']['default'],
    'ค่า default ของเจ้าหน้าที่เท่ากับสมาชิกทั่วไป — ลูกค้าเดิมที่อัปเกรดพฤติกรรมไม่เปลี่ยน',
    '🔴 ค่า default ต่างกัน — ลูกค้าที่อัปเกรดจะพบว่าเจ้าหน้าที่ยืมได้มากขึ้นเองโดยไม่ได้ตั้ง');

check('RQ-E2', str_contains((string) file_get_contents(__DIR__ . '/../includes/rules.php'), 'rule_max_books_staff'),
    'กฎใหม่อยู่ในทะเบียนเดียวกัน → หน้าตั้งค่าแสดงให้เองโดยไม่ต้องแก้หน้าจอ',
    '🔴 ไม่ได้อยู่ในทะเบียนกฎ');

// ============================================================
// A. helper กลาง
// ============================================================
echo "\n── A. ตัวตัดสินเพดานกลาง ──\n";

// ⚙️ ตั้งเพดานเจ้าหน้าที่ให้ต่างจากสมาชิก แล้วโหลดค่าผ่าน HTTP (คนละ process จึงเห็นค่าใหม่)
$STAFF_QUOTA = 8;
$pdo->prepare("
    INSERT INTO settings (setting_key, setting_value) VALUES ('rule_max_books_staff', ?)
    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
")->execute([(string) $STAFF_QUOTA]);
echo "  ⚙️  ตั้งเพดาน: สมาชิกทั่วไป " . MAX_BORROW_BOOKS . " · เจ้าหน้าที่ {$STAFF_QUOTA}\n";

// 🔴 process นี้โหลด constant ไปแล้ว จึงต้องอ่านค่าที่ Service ใช้จริงผ่าน process ใหม่
$probe = escapeshellarg($ROOT . '/tests/_role_quota_probe.php');
file_put_contents($ROOT . '/tests/_role_quota_probe.php', <<<'PROBE'
<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
echo json_encode([
    'member'  => quotaForRole('member'),
    'staff'   => quotaForRole('staff'),
    'admin'   => quotaForRole('admin'),
    'null'    => quotaForRole(null),
    'unknown' => quotaForRole('ครูพิเศษ'),
]);
PROBE);
$probeOut = json_decode((string) shell_exec(PHP_BINARY . ' ' . $probe), true) ?: [];
@unlink($ROOT . '/tests/_role_quota_probe.php');

check('RQ-A1',
    ($probeOut['staff'] ?? 0) === $STAFF_QUOTA && ($probeOut['admin'] ?? 0) === $STAFF_QUOTA,
    "เจ้าหน้าที่และผู้ดูแลได้เพดานของตัวเอง ({$STAFF_QUOTA} เล่ม)",
    '🔴 ' . json_encode($probeOut, JSON_UNESCAPED_UNICODE));

check('RQ-A2', ($probeOut['member'] ?? 0) === MAX_BORROW_BOOKS,
    'สมาชิกทั่วไปยังได้เพดานเดิม (' . MAX_BORROW_BOOKS . ' เล่ม)',
    '🔴 เพดานของสมาชิกทั่วไปเปลี่ยนไปด้วย');

// A3 — 🔴 role ที่ไม่รู้จักต้องได้ค่าที่ "เข้มที่สุด"
//      เดาว่าเป็นเจ้าหน้าที่แล้วให้ยืมเกินเป็นความเสี่ยง ไม่ใช่ความสะดวก
check('RQ-A3',
    ($probeOut['null'] ?? 0) === MAX_BORROW_BOOKS && ($probeOut['unknown'] ?? 0) === MAX_BORROW_BOOKS,
    '🔴 role ที่ไม่รู้จัก / ไม่มีค่า → ใช้เพดานที่เข้มที่สุด ไม่ใช่ของเจ้าหน้าที่',
    '🔴 role ที่ไม่รู้จักได้เพดานสูง — ถ้ามีข้อมูล role เพี้ยน จะยืมเกินได้');

// ============================================================
// B. ด่านจริง
// ============================================================
echo "\n── B. ด่านยืมจริง ──\n";

$bookService = new \App\Services\BookService($pdo);
$catId = (int) $pdo->query("SELECT id FROM categories LIMIT 1")->fetchColumn();
$uniq  = substr((string) getmypid(), -4) . mt_rand(100, 999);

$mkBook = function (int $i) use ($bookService, $catId, $uniq, &$madeBooks): int {
    $id = (int) $bookService->createBook([
        'title' => "[RQTEST] เล่ม {$i} {$uniq}", 'author' => 'ผู้แต่ง',
        'category_id' => $catId, 'quantity' => 1, 'isbn' => null,
    ]);
    $madeBooks[] = $id;
    return $id;
};
$mkUser = function (string $role, string $tag) use ($pdo, $uniq, &$madeUsers): int {
    static $n = 0;
    $n++;
    $st = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
    $st->execute(["[RQTEST] {$tag} {$uniq}", "rq_{$n}_{$uniq}@test.com", password_hash('x', PASSWORD_DEFAULT), $role]);
    $id = (int) $pdo->lastInsertId();
    $madeUsers[] = $id;
    return $id;
};

$staffUser  = $mkUser('staff', 'เจ้าหน้าที่');
$memberUser = $mkUser('member', 'สมาชิกทั่วไป');
// 👥 เจ้าหน้าที่อีกคนที่ยืม "เท่ากับ" สมาชิก — ใช้พิสูจน์ว่าตัวกรองแยกเพดานได้จริง
$staffSame  = $mkUser('staff', 'เจ้าหน้าที่ยืมเท่าสมาชิก');

// 📚 [บทเรียน] แต่ละคนต้องมีหนังสือ **ชุดของตัวเอง**
//    ฉบับแรกใช้ชุดเดียวกันทั้งสามคน — พอเจ้าหน้าที่ยืมหมดแล้ว
//    สมาชิกยืมไม่ได้เลยเพราะ "ไม่มีเล่มว่าง" ไม่ใช่เพราะโควตา
//    เทสต์เลยแดงด้วยเหตุผลที่ไม่เกี่ยวกับสิ่งที่ต้องการวัด
$bookIdx = 0;
$mkBookSet = function (int $count) use ($mkBook, &$bookIdx): array {
    $set = [];
    for ($i = 0; $i < $count; $i++) $set[] = $mkBook($bookIdx++);
    return $set;
};
$staffBooks  = $mkBookSet($STAFF_QUOTA + 1);
$memberBooks = $mkBookSet(MAX_BORROW_BOOKS + 1);
$sameBooks   = $mkBookSet(MAX_BORROW_BOOKS);

// 🔴 ยืมผ่าน BorrowService ใน process ใหม่ เพราะ constant ถูกโหลดไปแล้วใน process นี้
$borrowProbe = $ROOT . '/tests/_role_quota_borrow.php';
file_put_contents($borrowProbe, <<<'PROBE'
<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../app/Services/BorrowService.php';
$userId = (int) $argv[1];
$bookIds = array_map('intval', explode(',', $argv[2]));
$svc = new \App\Services\BorrowService(getDB());
$ok = 0; $err = '';
foreach ($bookIds as $bid) {
    try { $svc->createBorrow($userId, [$bid]); $ok++; }
    catch (Throwable $e) { $err = $e->getMessage(); break; }
}
echo json_encode(['borrowed' => $ok, 'error' => $err], JSON_UNESCAPED_UNICODE);
PROBE);

$runBorrow = function (int $userId, array $bookIds) use ($borrowProbe, $pdo, &$madeBorrows): array {
    $out = json_decode((string) shell_exec(
        PHP_BINARY . ' ' . escapeshellarg($borrowProbe) . ' ' . $userId . ' ' . escapeshellarg(implode(',', $bookIds))
    ), true) ?: ['borrowed' => 0, 'error' => 'probe ล้มเหลว'];
    // จำการยืมที่เกิดขึ้นจริงไว้ล้างทีหลัง
    $st = $pdo->prepare("SELECT id, book_id FROM borrows WHERE user_id = ? AND status = 'borrowing'");
    $st->execute([$userId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $madeBorrows[] = ['id' => (int) $row['id'], 'book_id' => (int) $row['book_id'], 'held' => true];
    }
    return $out;
};

$staffResult = $runBorrow($staffUser, $staffBooks);
check('RQ-B1', $staffResult['borrowed'] === $STAFF_QUOTA,
    "เจ้าหน้าที่ยืมได้ {$staffResult['borrowed']} เล่ม (เพดาน {$STAFF_QUOTA}) แล้วถูกกัน — เกินเพดานสมาชิกทั่วไป ("
        . MAX_BORROW_BOOKS . ')',
    '🔴 ยืมได้ ' . $staffResult['borrowed'] . ' เล่ม ควรได้ ' . $STAFF_QUOTA
        . ' · error: ' . $staffResult['error']);

check('RQ-B2', str_contains($staffResult['error'], (string) $STAFF_QUOTA),
    'ข้อความตอนเต็มโควตาบอกเพดานของ role นั้น — "' . mb_substr($staffResult['error'], 0, 70) . '"',
    '🔴 ข้อความบอกเพดานผิด (ไม่มีเลข ' . $STAFF_QUOTA . '): ' . $staffResult['error']);

$memberResult = $runBorrow($memberUser, $memberBooks);
check('RQ-B3', $memberResult['borrowed'] === MAX_BORROW_BOOKS,
    'สมาชิกทั่วไปยังถูกกันที่เพดานเดิม (' . MAX_BORROW_BOOKS . ' เล่ม) — ไม่ได้เพดานเจ้าหน้าที่ไปด้วย',
    '🔴 ยืมได้ ' . $memberResult['borrowed'] . ' เล่ม ควรได้ ' . MAX_BORROW_BOOKS);

// 👥 เจ้าหน้าที่คนที่สองยืมเท่ากับเพดานของสมาชิกพอดี — ยังไม่เต็มสำหรับ role ตัวเอง
$sameResult = $runBorrow($staffSame, $sameBooks);
check('RQ-B4', $sameResult['borrowed'] === MAX_BORROW_BOOKS,
    'เจ้าหน้าที่ยืม ' . MAX_BORROW_BOOKS . ' เล่มได้โดยยังไม่เต็ม (เพดาน ' . $STAFF_QUOTA . ')',
    '🔴 ยืมได้ ' . $sameResult['borrowed'] . ' · error: ' . $sameResult['error']);

@unlink($borrowProbe);

// ============================================================
// C. หน้าจอต้องตรงกับด่าน
// ============================================================
echo "\n── C. หน้าจอต้องบอกเพดานของ role นั้น ──\n";

$login = http('GET', "$BASE_URL/login.php");
$in = http('POST', "$BASE_URL/login.php", [
    'csrf_token' => csrfFrom($login), 'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD,
]);
if (!str_contains($in, 'ออกจากระบบ') && !str_contains($in, 'logout')) {
    fail('RQ-C0', 'ล็อกอินไม่สำเร็จ — ข้ามหมวด C');
} else {
    // C1 — badge บนหน้ารายชื่อสมาชิก
    $membersHtml = http('GET', "$BASE_URL/admin/members.php?search=" . urlencode('RQTEST'));
    $staffBadge  = preg_match('/(\d+)\/' . $STAFF_QUOTA . '/', $membersHtml) === 1;
    $memberBadge = preg_match('/(\d+)\/' . MAX_BORROW_BOOKS . '/', $membersHtml) === 1;
    check('RQ-C1', $staffBadge && $memberBadge,
        "badge แสดงเพดานคนละค่าในตารางเดียวกัน (/{$STAFF_QUOTA} สำหรับเจ้าหน้าที่ · /"
            . MAX_BORROW_BOOKS . ' สำหรับสมาชิก)',
        '🔴 badge ใช้เพดานเดียวกันหมด — เจ้าหน้าที่จะขึ้นว่า "เต็ม" ตั้งแต่ยังไม่เต็ม '
            . '(staff=' . var_export($staffBadge, true) . ' member=' . var_export($memberBadge, true) . ')');

    // C2 — 🔴 ฟอร์มบันทึกการยืมต้องรู้เพดานของแต่ละคน
    //      ถ้าใช้ค่าเดียว หน้าจอจะห้ามเจ้าหน้าที่เลือกเกิน 3 ทั้งที่ระบบยอม 8
    $formHtml = http('GET', "$BASE_URL/admin/borrow_form.php");
    preg_match_all('/data-quota="(\d+)"/', $formHtml, $qm);
    $quotaValues = array_unique($qm[1] ?? []);
    sort($quotaValues);
    check('RQ-C2',
        in_array((string) $STAFF_QUOTA, $quotaValues, true)
            && in_array((string) MAX_BORROW_BOOKS, $quotaValues, true),
        'ดรอปดาวน์ผู้ยืมพกเพดานของแต่ละคนมาด้วย (' . implode(', ', $quotaValues) . ')',
        '🔴 ค่าที่พบ: ' . implode(', ', $quotaValues) . ' — หน้าจอจะจำกัดผิดคน');

    // C3 — 🔴 ต้องมี JS ที่เอา data-quota ไปปรับเพดานจริง ไม่ใช่ใส่ attribute ทิ้งไว้เฉย ๆ
    check('RQ-C3',
        str_contains($formHtml, 'applyQuotaForSelectedMember')
            && str_contains($formHtml, 'maximumSelectionLength'),
        'มีตัวปรับเพดานของช่องเลือกหนังสือตามผู้ยืมที่เลือก',
        '🔴 ใส่ data-quota ไว้แต่ไม่มีอะไรเอาไปใช้ — หน้าจอยังจำกัดด้วยค่าเดียว');

    // C4 — ตัวกรอง "เต็มโควตา" (F-48) ต้องใช้เพดานตาม role ไม่งั้นเจ้าหน้าที่ติดผิด
    $filterHtml = http('GET', "$BASE_URL/admin/members.php?status=quota_full&search=" . urlencode('RQTEST'));
    preg_match_all('/member_form\.php\?[^"]*\bid=(\d+)/', $filterHtml, $fm);
    $flagged = array_unique($fm[1] ?? []);
    // 🔴 [บทเรียน] ต้องเทียบคนที่ยืม **เท่ากัน** ถึงจะพิสูจน์เรื่องเพดานได้
    //    ฉบับแรกเทียบกับเจ้าหน้าที่ที่ยืมจนเต็ม 8/8 ซึ่ง "ติดตัวกรอง" อย่างถูกต้องอยู่แล้ว
    //    เคสจึงแดงทั้งที่โค้ดทำงานถูก
    check('RQ-C4',
        in_array((string) $memberUser, $flagged, true) && !in_array((string) $staffSame, $flagged, true),
        'ตัวกรอง "เต็มโควตา" ใช้เพดานตาม role — ทั้งคู่ยืม ' . MAX_BORROW_BOOKS . ' เล่มเท่ากัน '
            . 'สมาชิกติด (' . MAX_BORROW_BOOKS . '/' . MAX_BORROW_BOOKS . ') แต่เจ้าหน้าที่ไม่ติด ('
            . MAX_BORROW_BOOKS . '/' . $STAFF_QUOTA . ')',
        '🔴 ผลกรองผิด — เจอ: ' . implode(',', $flagged)
            . ' (ต้องมีสมาชิก ' . $memberUser . ' · ต้องไม่มีเจ้าหน้าที่ ' . $staffSame . ')');
}

// ============================================================
// D. 🔴 ไม่เหลือจุดไหนที่ตัดสินด้วยค่าตายตัว
// ============================================================
echo "\n── D. ไม่เหลือจุดที่ใช้ค่าเดียวตัดสิน ──\n";

// 🧠 ตรวจจากซอร์ส: บรรทัดที่ **เปรียบเทียบ** กับ MAX_BORROW_BOOKS ตรง ๆ
//    (การใช้เป็นค่าเริ่มต้น/ข้อความไม่นับ — จุดที่อันตรายคือจุดที่ตัดสิน)
$decisionSpots = [];
foreach (['app/Services/BorrowService.php', 'app/Services/ReservationService.php',
          'app/Repositories/UserRepository.php', 'admin/members.php'] as $rel) {
    $src = (string) file_get_contents($ROOT . '/' . $rel);
    foreach (explode("\n", $src) as $no => $line) {
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) continue;
        if (!str_contains($line, 'MAX_BORROW_BOOKS')) continue;
        // เปรียบเทียบ = ตัดสิน · ต้องไม่มี
        if (preg_match('/(>=|<=|<|>|===|==)\s*MAX_BORROW_BOOKS\b|MAX_BORROW_BOOKS\s*(>=|<=|<|>|===|==)/', $line)) {
            $decisionSpots[] = $rel . ':' . ($no + 1);
        }
    }
}
check('RQ-D1', $decisionSpots === [],
    'ไม่มีด่านไหนเปรียบเทียบกับค่าตายตัวแล้ว — ทุกด่านผ่าน quotaForRole()',
    '🔴 ยังมีด่านที่ใช้ค่าเดียวตัดสิน: ' . implode(' · ', $decisionSpots));

// D2 — ตรวจว่าด่านหลักเรียก quotaForRole() จริง
$mustUse = [
    'app/Services/BorrowService.php'      => 'ด่านยืม',
    'app/Services/ReservationService.php' => 'ด่านจอง/ต่อคิว/เลื่อนคิว',
    'admin/members.php'                   => 'badge หน้ารายชื่อสมาชิก',
    'admin/borrow_form.php'               => 'ฟอร์มบันทึกการยืม',
];
$notUsing = [];
foreach ($mustUse as $rel => $label) {
    if (!str_contains((string) file_get_contents($ROOT . '/' . $rel), 'quotaForRole')) {
        $notUsing[] = "{$label} ({$rel})";
    }
}
check('RQ-D2', $notUsing === [],
    'ทุกจุดที่ต้องรู้เพดานเรียกตัวกลางเดียวกัน (' . count($mustUse) . ' ไฟล์)',
    '🔴 ยังไม่ได้ใช้ตัวกลาง: ' . implode(' · ', $notUsing));

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
