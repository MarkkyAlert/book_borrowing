<?php

/**
 * เตือนก่อนสาย + ปิดรูรหัสผ่านที่ผู้ดูแลตั้งให้
 *
 * ==========================================================================
 * 🔴 ที่มา: ตัดสินใจ "ไม่ทำระบบอีเมล"
 * ==========================================================================
 * ระบบไม่ส่งอีเมล และตั้งใจไม่ทำ (เหตุผลเต็มอยู่ใน docs/LIMITATIONS.md หัวข้อ 6
 * — เมลจากเครื่องลูกค้าที่ไม่มีโดเมนจะเข้าถังสแปม กลายเป็นฟีเจอร์ที่โกหก)
 * แต่ปัญหาที่อีเมลควรแก้ยังอยู่ จึงต้องแก้ด้วยทางอื่น 2 ทาง:
 *
 * A. **ลืมรหัสผ่าน** → ผู้ดูแลตั้งรหัสให้ที่เคาน์เตอร์
 *    🔴 ทางนี้เคยมีรูเปิดอยู่: `UserRepository::updatePassword()` เขียน
 *       `must_change_password = 0` ติดไปในคำสั่งเดียวกัน**เสมอ**
 *       = ไม่ใช่แค่ "ไม่ตั้งธง" แต่ **ล้างธงทิ้ง**
 *       ผลคือสมาชิกใช้รหัสที่ผู้ดูแลรู้ต่อไปได้ตลอด ไม่มีอะไรบังคับให้เปลี่ยน
 *       (รูเดียวกับที่ F-53 ปิดตอน "สร้างบัญชีใหม่" แต่ยังเปิดบนเส้นทาง "รีเซ็ต")
 *
 * B. **เตือนใกล้ครบกำหนด** → รายชื่อให้บรรณารักษ์โทร/LINE เอง
 *    เดิมหน้าภาพรวมขึ้นแค่ "เกินกำหนด" ซึ่ง**สายไปแล้ว** ทำได้แค่ตามทวง
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. ธง "ต้องเปลี่ยนรหัส" — ผู้ดูแลตั้งให้ต้องติด / เจ้าตัวตั้งเองต้องหลุด
 * B. กฎ DUE_SOON_DAYS — ตั้งได้ในหน้าเว็บ อ่าน 3 ชั้น ค่านอกช่วงถูกปฏิเสธ
 * C. การนับ — ต้องตรงกับ query ตรง ๆ · ห้ามทับกับ "เกินกำหนด"
 * D. ใบรายชื่อโทรตาม — หน้าเว็บ / CSV / หน้าพิมพ์ ต้องได้จำนวนเท่ากัน
 * E. หน้าภาพรวม — ต้องมีเบอร์โทรให้กดโทร ไม่ใช่แค่ตัวเลข
 *
 * 🧹 ลบสมาชิก หนังสือ และการยืมที่สร้างขึ้นทั้งหมด
 *
 * 📌 การใช้งาน: php tests/test_due_soon_and_reset.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report_helper.php';
require_once __DIR__ . '/../app/Services/BookService.php';
require_once __DIR__ . '/../app/Services/BorrowService.php';
require_once __DIR__ . '/../app/Services/MemberService.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
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

$pdo    = getDB();
$COOKIE = tempnam(sys_get_temp_dir(), 'bbds');

$madeBooks   = [];
$madeUsers   = [];
$madeBorrows = [];
$cleanupDone = false;

$cleanup = function () use (&$madeBooks, &$madeUsers, &$madeBorrows, &$cleanupDone, $pdo, $COOKIE) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    try {
        if ($pdo->inTransaction()) $pdo->rollBack();
    } catch (Throwable $e) {
        echo '  ⚠️ rollback ไม่สำเร็จ: ' . $e->getMessage() . "\n";
    }

    // 🔴 ลำดับสำคัญ: borrows ก่อน แล้วค่อย books/users
    //    FK เป็น ON DELETE RESTRICT (ดู I-04) ลบสลับลำดับจะลบไม่ออก
    $failed = [];
    // 🔴 กวาดการยืมตามป้ายก่อนลบทีละตัว — ถ้าเทสต์ตายกลางคัน $madeBorrows จะว่าง
    //    แล้ว FK RESTRICT จะทำให้ลบหนังสือ/สมาชิกไม่ออกทั้งหมด
    //    กวาดจาก **ความสัมพันธ์** ไม่ใช่แค่ notes เพราะการยืมที่สร้างผ่านฟอร์ม
    //    หรือที่ยังไม่ทันติดป้ายจะหลุดตาข่าย แล้วบล็อกการลบหนังสือ/สมาชิกทั้งหมด
    try {
        $pdo->exec("DELETE bo FROM borrows bo JOIN books b ON bo.book_id = b.id
                    WHERE b.title LIKE '%[DSTEST]%'");
        $pdo->exec("DELETE bo FROM borrows bo JOIN users u ON bo.user_id = u.id
                    WHERE u.name LIKE '%[DSTEST]%'");
    } catch (Throwable $e) { $failed[] = 'กวาดการยืม [DSTEST]'; }
    foreach ($madeBorrows as $id) {
        try { $pdo->prepare("DELETE FROM borrows WHERE id = ?")->execute([(int) $id]); }
        catch (Throwable $e) { $failed[] = "borrow#{$id}"; }
    }
    foreach ($madeBooks as $id) {
        try { $pdo->prepare("DELETE FROM books WHERE id = ?")->execute([(int) $id]); }
        catch (Throwable $e) { $failed[] = "book#{$id}"; }
    }
    foreach ($madeUsers as $id) {
        try { $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([(int) $id]); }
        catch (Throwable $e) { $failed[] = "user#{$id}"; }
    }

    // 🧹 กวาดตามป้าย — แถวที่เกิดผ่าน HTTP ไม่ได้ถูกจำไว้ทีละตัว
    //    (บทเรียนจาก test_closed_days: แถวที่สร้างผ่านฟอร์มค้างในฐานข้อมูล)
    try {
        $n = $pdo->exec("DELETE FROM borrows WHERE notes LIKE '%[DSTEST]%'");
        if ($n > 0) echo "  🧹 กวาดการยืมที่ติดป้าย [DSTEST] อีก {$n} รายการ\n";
        $n = $pdo->exec("DELETE FROM books WHERE title LIKE '%[DSTEST]%'");
        if ($n > 0) echo "  🧹 กวาดหนังสือที่ติดป้าย [DSTEST] อีก {$n} เล่ม\n";
        $n = $pdo->exec("DELETE FROM users WHERE name LIKE '%[DSTEST]%'");
        if ($n > 0) echo "  🧹 กวาดสมาชิกที่ติดป้าย [DSTEST] อีก {$n} คน\n";
    } catch (Throwable $e) { $failed[] = 'กวาดป้าย [DSTEST]'; }

    // 🔴 ต้องลบแถวกฎที่เทสต์เขียนลง settings ไม่งั้นค่าที่ตั้งไว้จะค้างกับระบบจริง
    try {
        $pdo->exec("DELETE FROM settings WHERE setting_key = 'rule_due_soon_days'");
    } catch (Throwable $e) { $failed[] = 'ลบ rule_due_soon_days'; }

    echo '  ลบ ' . count($madeBorrows) . ' การยืม · ' . count($madeBooks)
       . ' หนังสือ · ' . count($madeUsers) . " สมาชิก\n";
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
echo "║  เตือนก่อนสาย + รหัสผ่านที่ผู้ดูแลตั้งให้                  ║\n";
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

$bookService  = new \App\Services\BookService($pdo);
$borrowSvc    = new \App\Services\BorrowService($pdo);
$memberSvc    = new \App\Services\MemberService($pdo);
$authSvc      = new \App\Services\AuthService($pdo);
$dashboard    = new \App\Services\DashboardService($pdo);
$catId        = (int) $pdo->query("SELECT id FROM categories LIMIT 1")->fetchColumn();
$uniq         = substr((string) getmypid(), -4) . mt_rand(100, 999);
$flagOf       = fn(int $id) => (int) $pdo->query("SELECT must_change_password FROM users WHERE id = {$id}")->fetchColumn();

// ============================================================
// A. ธง "ต้องเปลี่ยนรหัสผ่าน"
// ============================================================
echo "── A. รหัสผ่านที่คนอื่นตั้งให้ ต้องบังคับเปลี่ยน ──\n";

$memberEmail = "dstest{$uniq}@test.local";
$made = $memberSvc->createMember([
    'name'     => "[DSTEST] สมาชิกทดสอบ {$uniq}",
    'email'    => $memberEmail,
    'phone'    => '0800000001',
    'password' => 'FirstPass#2026',
], true);
$memberId = (int) $made['id'];
$madeUsers[] = $memberId;

check('DS-A1', $flagOf($memberId) === 1,
    'ผู้ดูแลสร้างบัญชีให้ → ติดธงต้องเปลี่ยนรหัส (F-53 เดิม)',
    '🔴 ธง = ' . $flagOf($memberId));

// 📝 จำลองว่าสมาชิกเปลี่ยนรหัสไปแล้วรอบหนึ่ง — ธงหลุด ใช้งานปกติ
$pdo->prepare("UPDATE users SET must_change_password = 0 WHERE id = ?")->execute([$memberId]);

// 🔴 A2 คือรูที่เจอจริง: สมาชิกลืมรหัส เดินมาที่เคาน์เตอร์ ผู้ดูแลตั้งให้ใหม่
//     ผู้ดูแล **รู้รหัสนั้น** ถ้าไม่บังคับเปลี่ยน = รหัสที่คนอื่นรู้ถูกใช้ตลอดไป
$memberSvc->updatePassword($memberId, 'CounterSet#2026');
check('DS-A2', $flagOf($memberId) === 1,
    'ผู้ดูแลตั้งรหัสให้สมาชิกที่ลืมรหัส → ติดธง',
    '🔴 ธง = ' . $flagOf($memberId) . ' — สมาชิกจะใช้รหัสที่ผู้ดูแลรู้ต่อไปได้ตลอด');

// 🔴 A3 ด้านตรงข้าม: ถ้าเผลอติดธงตรงนี้ ผู้ใช้จะวนอยู่ในหน้าบังคับเปลี่ยนรหัสไม่จบ
$res = $authSvc->changePassword($memberId, 'CounterSet#2026', 'OwnSecret#2026');
check('DS-A3', ($res['success'] ?? false) && $flagOf($memberId) === 0,
    'เจ้าตัวเปลี่ยนรหัสเอง → ธงหลุด ใช้งานต่อได้',
    '🔴 ธง = ' . $flagOf($memberId) . ' · ผล: ' . json_encode($res, JSON_UNESCAPED_UNICODE));

// A4 — พารามิเตอร์ต้องไม่มีค่า default
//      🧠 ถ้ามี default จุดเรียกใหม่ที่ลืมคิดจะได้พฤติกรรมของอีกแบบเงียบ ๆ
//         ซึ่งเป็นสาเหตุของรูนี้ตั้งแต่แรก (เดิมไม่มีพารามิเตอร์เลย ฝัง 0 ตายตัว)
$refl  = new ReflectionMethod(\App\Repositories\UserRepository::class, 'updatePassword');
$params = $refl->getParameters();
$third  = $params[2] ?? null;
check('DS-A4', $third !== null && !$third->isDefaultValueAvailable(),
    'updatePassword() บังคับให้ทุกจุดเรียกระบุเองว่าจะติดธงไหม (ไม่มี default)',
    '🔴 พารามิเตอร์ที่ 3 ' . ($third === null ? 'ไม่มี' : 'มีค่า default') . ' — จุดเรียกใหม่จะพลาดได้เงียบ ๆ');

// A5 — ทดสอบผ่าน HTTP จริง: ผู้ดูแลกดตั้งรหัสในฟอร์ม แล้วสมาชิกต้องถูกบังคับเปลี่ยน
$login = http('GET', "{$BASE_URL}/login.php");
http('POST', "{$BASE_URL}/login.php", [
    'csrf_token' => csrfFrom($login),
    'email'      => $ADMIN_EMAIL,
    'password'   => $ADMIN_PASSWORD,
]);
$form = http('GET', "{$BASE_URL}/admin/member_form.php?id={$memberId}");
$sent = http('POST', "{$BASE_URL}/admin/member_form.php?id={$memberId}", [
    'csrf_token' => csrfFrom($form),
    // 🔴 ฟอร์มอ่าน id จาก **POST body** ไม่ใช่ query string
    //    ถ้าไม่ส่งมา จะกลายเป็น "สร้างสมาชิกใหม่" แล้วติด error อีเมลซ้ำ
    'id'         => $memberId,
    'name'       => "[DSTEST] สมาชิกทดสอบ {$uniq}",
    'email'      => $memberEmail,
    'phone'      => '0800000001',
    'role'       => 'member',
    'password'   => 'FormSet#2026',
]);
check('DS-A5', $flagOf($memberId) === 1,
    'ตั้งรหัสผ่านฟอร์มผู้ดูแลจริง → ติดธง (ไม่ใช่แค่ที่ชั้น Service)',
    '🔴 ธง = ' . $flagOf($memberId) . ' · ฟอร์มตอบกลับ ' . strlen($sent) . ' ไบต์');

// ============================================================
// B. กฎ "เตือนล่วงหน้ากี่วัน"
// ============================================================
echo "\n── B. กฎ DUE_SOON_DAYS ตั้งได้ในหน้าเว็บ ──\n";

$settings = http('GET', "{$BASE_URL}/admin/settings.php");
check('DS-B1', str_contains($settings, 'rule_due_soon_days'),
    'หน้าตั้งค่าขึ้นช่องกรอกให้เอง (ทะเบียนกฎเป็นแหล่งเดียว)',
    '🔴 ไม่มีช่อง rule_due_soon_days — ผู้ดูแลตั้งเองไม่ได้');

$defs = ruleDefinitions();
check('DS-B2', isset($defs['DUE_SOON_DAYS']['min'], $defs['DUE_SOON_DAYS']['max'])
        && $defs['DUE_SOON_DAYS']['min'] >= 1,
    'กฎมีขอบเขต min/max — กันค่า 0 หรือค่ามหาศาลที่ทำให้รายชื่อไร้ความหมาย',
    '🔴 ขอบเขตกฎไม่ถูกต้อง');

// 🧠 อ่านค่าในโปรเซสแยก เพราะ constant ถูกนิยามตอนโหลด config ครั้งเดียว
$readDays = function () {
    $php = PHP_BINARY;
    $root = escapeshellarg(dirname(__DIR__));
    $code = 'require ' . $root . '."/includes/config.php"; require ' . $root
          . '."/includes/db.php"; require ' . $root . '."/includes/functions.php"; echo DUE_SOON_DAYS;';
    return (int) shell_exec(escapeshellarg($php) . ' -r ' . escapeshellarg($code));
};
$pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('rule_due_soon_days', '7')
               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute();
check('DS-B3', $readDays() === 7,
    'ตั้ง 7 ในตาราง settings → ระบบใช้ 7 (ชั้น settings ชนะ .env)',
    '🔴 อ่านได้ ' . $readDays());

// 🛡️ ค่านอกช่วงต้องถูกทิ้งแล้วตกไปใช้ค่าถัดไป ไม่ใช่เอามาใช้ดื้อ ๆ
$pdo->prepare("UPDATE settings SET setting_value = '999' WHERE setting_key = 'rule_due_soon_days'")->execute();
check('DS-B4', $readDays() === (int) $defs['DUE_SOON_DAYS']['default'],
    'ค่า 999 (เกิน max) ถูกทิ้ง ตกไปใช้ค่าเริ่มต้น',
    '🔴 อ่านได้ ' . $readDays() . ' — ค่าที่ใครไปแก้ใน DB ตรง ๆ หลุดเข้าระบบ');

$pdo->exec("DELETE FROM settings WHERE setting_key = 'rule_due_soon_days'");

// ============================================================
// C. การนับ
// ============================================================
echo "\n── C. นับให้ตรง และไม่ทับกับ \"เกินกำหนด\" ──\n";

// 📚 สร้างหนังสือ 4 เล่ม + ยืมทั้ง 4 แล้วดันวันครบกำหนดไปคนละจุด
//    ใช้ BorrowService สร้างเพื่อให้สต็อกถูกหักตามระบบจริง แล้วค่อยแก้ due_date
$mkBook = function (string $tag) use ($bookService, $catId, $uniq, &$madeBooks): int {
    $id = (int) $bookService->createBook([
        'title' => "[DSTEST] {$tag} {$uniq}", 'author' => 'ผู้แต่งทดสอบ',
        'category_id' => $catId, 'quantity' => 1, 'isbn' => null,
    ]);
    $madeBooks[] = $id;
    return $id;
};
// 🔴 โควตายืมสูงสุดคือ MAX_BORROW_BOOKS (ค่าเริ่มต้น 3) — เทสต์นี้ต้องการ 4 การยืม
//    ถ้าใช้สมาชิกคนเดียวจะติดโควตาที่รายการที่ 4 แล้วล้มด้วยเหตุผลที่ไม่เกี่ยวกับสิ่งที่ทดสอบ
//    (บทเรียนเดิมจาก test_reservation_queue: fixture ที่ใช้ร่วมกันทำให้เคสแดงผิดสาเหตุ)
$borrowerNo = 0;
$mkBorrower = function () use ($memberSvc, $uniq, &$borrowerNo, &$madeUsers): int {
    $borrowerNo++;
    $r = $memberSvc->createMember([
        'name'     => "[DSTEST] ผู้ยืม{$borrowerNo} {$uniq}",
        'email'    => "dsborrow{$borrowerNo}{$uniq}@test.local",
        'phone'    => '08000000' . str_pad((string) $borrowerNo, 2, '0', STR_PAD_LEFT),
        'password' => 'Borrower#2026',
    ], true);
    $madeUsers[] = (int) $r['id'];
    return (int) $r['id'];
};
$mkBorrow = function (int $bookId, int $dayOffset) use ($borrowSvc, $pdo, $mkBorrower, &$madeBorrows): int {
    $borrowerId = $mkBorrower();
    $r = $borrowSvc->createBorrow($borrowerId, [$bookId]);
    if (empty($r['success'])) {
        throw new RuntimeException('สร้างการยืมไม่สำเร็จ: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    }
    // 📝 createBorrow() คืนชื่อหนังสือที่ยืมสำเร็จ ไม่ได้คืน id — หาแถวล่าสุดของคู่ user+book
    $st = $pdo->prepare("SELECT id FROM borrows WHERE user_id = ? AND book_id = ?
                         AND status = 'borrowing' ORDER BY id DESC LIMIT 1");
    $st->execute([$borrowerId, $bookId]);
    $bid = (int) $st->fetchColumn();
    if (!$bid) {
        throw new RuntimeException('หาแถว borrows ที่เพิ่งสร้างไม่เจอ');
    }
    $madeBorrows[] = $bid;
    $pdo->prepare("UPDATE borrows SET due_date = DATE_ADD(CURDATE(), INTERVAL ? DAY), notes = '[DSTEST]' WHERE id = ?")
        ->execute([$dayOffset, $bid]);
    return $bid;
};

$before = $dashboard->getCardStats()['due_soon_borrows'];

$bToday = $mkBorrow($mkBook('ครบวันนี้'), 0);      // ครบกำหนดวันนี้ → ต้องนับ
$bSoon  = $mkBorrow($mkBook('อีก2วัน'), 2);        // อยู่ในช่วง → ต้องนับ
$bFar   = $mkBorrow($mkBook('อีก20วัน'), 20);      // ไกลเกินช่วง → ต้องไม่นับ
$bLate  = $mkBorrow($mkBook('สายแล้ว'), -5);       // เกินกำหนดแล้ว → ต้องไม่นับ

$after = $dashboard->getCardStats()['due_soon_borrows'];
check('DS-C1', $after - $before === 2,
    "เพิ่ม 4 รายการ (วันนี้ / อีก2วัน / อีก20วัน / สายแล้ว) → นับเพิ่มแค่ 2",
    '🔴 นับเพิ่ม ' . ($after - $before) . ' — ควรเป็น 2');

$raw = (int) $pdo->query("
    SELECT COUNT(*) FROM borrows
    WHERE status = 'borrowing' AND due_date >= CURDATE()
      AND due_date <= DATE_ADD(CURDATE(), INTERVAL " . DUE_SOON_DAYS . " DAY)
")->fetchColumn();
check('DS-C2', $after === $raw,
    "ตัวเลขบนการ์ด ({$after}) ตรงกับ query ตรง ๆ ({$raw})",
    "🔴 การ์ด {$after} · query {$raw} — บรรณารักษ์จะไม่เชื่อตัวเลขไหนเลย");

// 🔴 ถ้าสองชุดทับกัน รายการเดียวจะโผล่ทั้ง "ใกล้ครบกำหนด" และ "เกินกำหนด"
$overlap = (int) $pdo->query("
    SELECT COUNT(*) FROM borrows
    WHERE status = 'borrowing' AND due_date < CURDATE()
      AND due_date >= CURDATE()
")->fetchColumn();
$soonIds = array_column($dashboard->getDueSoonList(200), 'id');
$lateIds = array_column($dashboard->getOverdueList(200), 'id');
check('DS-C3', $overlap === 0 && !array_intersect($soonIds, $lateIds),
    'ช่วง "ใกล้ครบกำหนด" กับ "เกินกำหนด" ไม่ทับกันเลย',
    '🔴 ทับกัน ' . count(array_intersect($soonIds, $lateIds)) . ' รายการ — ยอดรวมจะเพี้ยน');

check('DS-C4', in_array($bToday, $soonIds, true) && in_array($bSoon, $soonIds, true)
        && !in_array($bFar, $soonIds, true) && !in_array($bLate, $soonIds, true),
    'เล่มที่ครบกำหนดวันนี้ถูกนับ · เล่มที่ยังอีกไกลและเล่มที่สายแล้วไม่ถูกนับ',
    '🔴 รายการที่ได้ไม่ตรงกับที่ควรเป็น');

// 📕 คืนเล่มที่ครบกำหนดวันนี้ → ต้องหลุดจากรายชื่อทันที
$borrowSvc->returnBook($bToday);
$afterReturn = $dashboard->getCardStats()['due_soon_borrows'];
check('DS-C5', $afterReturn === $after - 1,
    'คืนแล้วหลุดจากรายชื่อโทรตามทันที (ไม่โทรตามคนที่คืนไปแล้ว)',
    "🔴 ก่อนคืน {$after} · หลังคืน {$afterReturn}");

// ============================================================
// D. ใบรายชื่อโทรตาม
// ============================================================
echo "\n── D. ใบรายชื่อ — หน้าเว็บ / CSV / หน้าพิมพ์ ──\n";

$expected = $dashboard->getCardStats()['due_soon_borrows'];

$web = http('GET', "{$BASE_URL}/admin/reports.php?report=due_soon");
check('DS-D1', str_contains($web, 'เหลืออีก (วัน)') && str_contains($web, 'เบอร์โทร'),
    'หน้ารายงานมีคอลัมน์ "เบอร์โทร" และ "เหลืออีก (วัน)"',
    '🔴 คอลัมน์ไม่ครบ — ใบนี้มีไว้โทร ถ้าไม่มีเบอร์ก็ใช้ไม่ได้');

$csv = http('GET', "{$BASE_URL}/admin/reports.php?report=due_soon&export=csv");
$csvRows = max(0, count(array_filter(explode("\n", trim($csv)))) - 1);
check('DS-D2', $csvRows === $expected,
    "CSV มี {$csvRows} แถว เท่ากับตัวเลขบนการ์ด",
    "🔴 CSV {$csvRows} แถว · การ์ด {$expected}");

check('DS-D3', str_starts_with($csv, "\xEF\xBB\xBF") && (str_contains($csv, ",'0") || $expected === 0),
    'CSV มี BOM (Excel อ่านไทยไม่เพี้ยน) และเบอร์โทรมี \' นำหน้ากัน 0 หาย',
    '🔴 BOM: ' . (str_starts_with($csv, "\xEF\xBB\xBF") ? 'มี' : 'ไม่มี')
        . ' · เบอร์โทร: ' . (str_contains($csv, ",'0") ? 'ถูก' : 'โดน Excel กิน 0 นำหน้า'));

$print = http('GET', "{$BASE_URL}/admin/export_pdf.php?report=due_soon");
check('DS-D4', str_contains($print, 'ใบรายชื่อโทรตาม'),
    'หน้าพิมพ์มีหัวเรื่องบอกว่านี่คือใบอะไร',
    '🔴 หน้าพิมพ์ไม่มีหัวเรื่อง');

// 🔢 "เหลืออีกกี่วัน" รวมยอดไม่ได้ — บวกกันแล้วไม่มีความหมาย
check('DS-D5', in_array('days_left', REPORT_NO_TOTAL_COLUMNS, true),
    'คอลัมน์ "เหลืออีก (วัน)" ไม่ถูกรวมยอดท้ายตาราง',
    '🔴 จะมีแถวรวมที่บวกจำนวนวันของคนละคนเข้าด้วยกัน');

// ============================================================
// E. หน้าภาพรวม
// ============================================================
echo "\n── E. หน้าภาพรวม — ต้องกดโทรได้ ──\n";

$dash = http('GET', "{$BASE_URL}/admin/");
check('DS-E1', str_contains($dash, 'ใกล้ครบกำหนด — โทรเตือนได้เลย'),
    'หน้าภาพรวมมีแถบ "ใกล้ครบกำหนด" (เดิมมีแค่ "เกินกำหนด" ซึ่งสายไปแล้ว)',
    '🔴 ไม่มีแถบนี้บนหน้าภาพรวม');

check('DS-E2', str_contains($dash, 'href="tel:'),
    'เบอร์โทรเป็นลิงก์กดโทรได้จากมือถือ',
    '🔴 ไม่มีลิงก์ tel: — บรรณารักษ์ต้องพิมพ์เบอร์เอง');

check('DS-E3', str_contains($dash, 'reports.php?report=due_soon'),
    'มีทางกดไปพิมพ์ใบรายชื่อทั้งหมด',
    '🔴 ไม่มีลิงก์ไปใบรายชื่อ');

// ============================================================
echo "\n══════════════════════════════════════\n";
printf(" RESULTS: %d/%d passed (%.1f%%)%s\n",
    $results['passed'], $results['total'],
    $results['total'] ? $results['passed'] / $results['total'] * 100 : 0,
    $results['failed'] ? ' | ' . $results['failed'] . ' FAILED' : '');
echo "══════════════════════════════════════\n";

exit($results['failed'] > 0 ? 1 : 0);
