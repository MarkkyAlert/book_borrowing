<?php

/**
 * ตัวกรองสำหรับงานที่บรรณารักษ์ทำจริง — F-48
 *
 * ==========================================================================
 * 🔴 ปัญหาเดิม
 * ==========================================================================
 * วัดจากโจทย์ "หาของให้เจอ" แล้วมีงานประจำที่หาไม่เจอเลยจากหน้าจอ:
 *   - หนังสือที่ยังไม่ได้ลง ISBN  → ต้องไล่ดูเอง 21 หน้า
 *   - สมาชิกที่เต็มโควตา          → ไม่มีตัวกรอง
 *   - สมาชิกที่ค้างค่าปรับ        → หาได้เฉพาะในหน้าการเงิน คนละหน้ากับที่เจ้าหน้าที่เปิดอยู่
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. ไม่มี ISBN — รวมเคส `''` ไม่ใช่แค่ NULL · ใช้ร่วมกับตัวกรองอื่นได้
 * B. เต็มโควตา — 🔴 `waiting` (ต่อคิวรอ) ต้องไม่ถูกนับ ไม่งั้นบอกว่าเต็มทั้งที่ยืมได้อีก
 * C. ค้างค่าปรับ — 🔴 จ่ายแล้ว/ยกเว้นแล้ว ต้องไม่ติด และตัวเลขต้องตรงกับหน้าการเงิน
 * D. 🔴 ยอดรวมบนหัวตารางต้องตรงกับจำนวนแถวจริง
 *    (จุดที่พังง่ายที่สุด: ตัวนับกับตัวดึงรายการเป็นคนละ query
 *     ถ้าคอลัมน์คำนวณไม่ครบทั้งสองฝั่ง ตัวนับจะพังด้วย Unknown column = หน้าขาว)
 * E. ตัวกรองไม่หายเมื่อกดหน้า 2 หรือกดบันทึกแล้วกลับมา (ต่อจาก F-37)
 *
 * 🧹 ลบ fixture ทั้งหมด + คืนสต็อกให้ตรง invariant
 *
 * 📌 การใช้งาน: php tests/test_filters.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/BookService.php';
require_once __DIR__ . '/../app/Services/MemberService.php';
require_once __DIR__ . '/../app/Services/ReservationService.php';
require_once __DIR__ . '/../app/Repositories/BorrowRepository.php';

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
$COOKIE = tempnam(sys_get_temp_dir(), 'bbflt');

$madeBooks = $madeUsers = $madeBorrows = [];
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

    $failed = [];
    // 🔓 คืนสต็อกจากการจองที่ยังกันของอยู่ ก่อนลบอะไรทั้งนั้น
    //    การจอง pending กัน available ไว้ — ลบดิบ ๆ = สต็อกหายไปเฉย ๆ (invariant พัง)
    foreach ($madeUsers as $uid) {
        try {
            $svc = new \App\Services\ReservationService($pdo);
            $held = $pdo->prepare("SELECT id FROM reservations WHERE user_id = ? AND status = 'pending'");
            $held->execute([$uid]);
            foreach ($held->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                try { $svc->cancelReservation((int) $rid, (int) $uid); }
                catch (Throwable $e) { echo "  ⚠️ ยกเลิกการจอง #{$rid} ไม่สำเร็จ: " . $e->getMessage() . "\n"; }
            }
        } catch (Throwable $e) {
            $failed[] = "คืนสต็อกของ #{$uid}";
        }
    }

    // 📚 คืนสต็อกจากการยืมที่ทดสอบสร้างเอง (insert ดิบ → ต้องคืนเอง)
    foreach ($madeBorrows as $bw) {
        try {
            $pdo->prepare("DELETE FROM payments WHERE borrow_id = ?")->execute([$bw['id']]);
            $pdo->prepare("DELETE FROM borrows WHERE id = ?")->execute([$bw['id']]);
            if ($bw['held']) {
                $pdo->prepare("UPDATE books SET available = available + 1 WHERE id = ?")->execute([$bw['book_id']]);
            }
        } catch (Throwable $e) {
            $failed[] = "borrow#{$bw['id']}";
        }
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
    if ($failed) echo '  🔴 ลบไม่สำเร็จ ต้องลบมือ: ' . implode(' · ', $failed) . "\n";

    // 🔍 ตรวจ invariant ทันทีหลังล้าง — ถ้าเทสต์นี้ทำสต็อกเพี้ยน ต้องรู้เดี๋ยวนั้น
    try {
        $bad = $pdo->query("
            SELECT COUNT(*) FROM (
                SELECT b.id, b.quantity, b.available FROM books b
                HAVING b.available <> b.quantity
                    - (SELECT COUNT(*) FROM borrows bo WHERE bo.book_id = b.id AND bo.status = 'borrowing')
                    - (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.id AND r.status = 'pending')
            ) t
        ")->fetchColumn();
        echo ((int) $bad === 0)
            ? "  ✅ invariant สต็อกยังตรง\n"
            : "  🔴 invariant สต็อกเพี้ยน {$bad} เล่ม — ต้องแก้มือ\n";
    } catch (Throwable $e) {
        echo '  ⚠️ ตรวจ invariant ไม่ได้: ' . $e->getMessage() . "\n";
    }
    @unlink($COOKIE);
};
register_shutdown_function($cleanup);

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  ตัวกรองสำหรับงานที่บรรณารักษ์ทำจริง (F-48)              ║\n";
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

/**
 * อ่านยอดรวมที่หัวตาราง เช่น "ทั้งหมด 4 ชื่อเรื่อง" / "ทั้งหมด 4 คน"
 *
 * 🔴 หน่วยนับต้องตรงกับที่หน้าเว็บใช้จริง — ตอนแก้หน้าจัดการหนังสือจาก
 *    "เล่ม" เป็น "ชื่อเรื่อง" (เพราะนับแถว = ชื่อเรื่อง ไม่ใช่จำนวนเล่ม)
 *    ตัวอ่านนี้อ่านไม่ออกทันที แล้วเคสฟ้องว่า "หน้าน่าจะพัง" ทั้งที่หน้าปกติดี
 *    → เก็บ "เล่ม" ไว้ด้วยเผื่อหน้าอื่นยังใช้ และเพิ่ม "ชื่อเรื่อง" เข้าไป
 */
function totalFrom(string $html): ?int
{
    return preg_match('/ทั้งหมด\s*([\d,]+)\s*(?:ชื่อเรื่อง|เล่ม|คน|รายการ)/u', $html, $m)
        ? (int) str_replace(',', '', $m[1]) : null;
}

/**
 * ดึง id ของทุกแถวในตาราง
 *
 * 🔴 [บทเรียน] ฉบับแรกอ่านจาก `name="id" value="N"` ในฟอร์มลบ
 *    แต่ **ปุ่มลบไม่ได้ขึ้นทุกแถว** (ขึ้นเฉพาะรายการที่ลบได้)
 *    → นับได้ 6 จาก 13 แถว แล้วเทสต์แดงทั้งที่โค้ดถูก
 *    ตอนนี้อ่านจากลิงก์ "แก้ไข" ซึ่งมีทุกแถวเสมอ
 */
function rowIdsFrom(string $html, string $form = 'book_form'): array
{
    preg_match_all('/' . preg_quote($form, '/') . '\.php\?[^"]*\bid=(\d+)/', $html, $m);
    return array_values(array_unique($m[1] ?? []));
}

$login = http('GET', "$BASE_URL/login.php");
$r = http('POST', "$BASE_URL/login.php", [
    'csrf_token' => csrfFrom($login), 'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD,
]);
if (!str_contains($r, 'ออกจากระบบ') && !str_contains($r, 'logout')) {
    fail('FLT-00', 'ล็อกอินไม่สำเร็จ — ส่งรหัสผ่าน admin เป็น argument');
    $cleanup();
    exit(1);
}

$uniq          = substr((string) getmypid(), -4) . mt_rand(100, 999);
$bookService   = new \App\Services\BookService($pdo);
$memberService = new \App\Services\MemberService($pdo);
$resService    = new \App\Services\ReservationService($pdo);
$borrowRepo    = new \App\Repositories\BorrowRepository($pdo);
$catId         = (int) $pdo->query("SELECT id FROM categories LIMIT 1")->fetchColumn();

$mkBook = function (string $title, ?string $isbn, int $qty = 1) use ($bookService, $catId, &$madeBooks): int {
    // 🔴 สร้างผ่าน Service เสมอ — Repository เป็นตัวเติม search_tokens (trigram ค้นไทย)
    $id = (int) $bookService->createBook([
        'title' => $title, 'author' => 'ผู้แต่งทดสอบ', 'category_id' => $catId,
        'quantity' => $qty, 'isbn' => $isbn,
    ]);
    $madeBooks[] = $id;
    return $id;
};

$mkUser = function (string $name) use ($pdo, $uniq, &$madeUsers): int {
    static $n = 0;
    $n++;
    $st = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'member')");
    $st->execute(["[FLTTEST] {$name} {$uniq}", "flt_{$n}_{$uniq}@test.com", password_hash('x', PASSWORD_DEFAULT)]);
    $id = (int) $pdo->lastInsertId();
    $madeUsers[] = $id;
    return $id;
};

// ============================================================
// A. หนังสือที่ยังไม่ได้ลง ISBN
// ============================================================
echo "── A. หนังสือที่ยังไม่ได้ลง ISBN ──\n";

$baselineNoIsbn = (int) $pdo->query("SELECT COUNT(*) FROM books WHERE isbn IS NULL OR isbn = ''")->fetchColumn();

$bookNullIsbn  = $mkBook("[FLTTEST] เล่มไม่มี ISBN (null) {$uniq}", null);
// 🔴 ฟอร์มเพิ่มหนังสือบันทึกช่องว่างเป็น '' ไม่ใช่ NULL เสมอไป
//    เช็คแค่ IS NULL จะหาเจอไม่ครบ — เคสนี้คุมไว้โดยเฉพาะ
$bookEmptyIsbn = $mkBook("[FLTTEST] เล่มไม่มี ISBN (ว่าง) {$uniq}", '');
$bookHasIsbn   = $mkBook("[FLTTEST] เล่มมี ISBN {$uniq}", '978-FLT-' . $uniq);

$htmlNoIsbn = http('GET', "$BASE_URL/admin/books.php?no_isbn=1&search=" . urlencode('FLTTEST'));
$ids = rowIdsFrom($htmlNoIsbn);

check('FLT-A1',
    in_array((string) $bookNullIsbn, $ids, true) && !in_array((string) $bookHasIsbn, $ids, true),
    'กรอง "ยังไม่ได้ลง ISBN" ได้เล่มที่ไม่มี และไม่ติดเล่มที่มี',
    '🔴 ผลไม่ถูก — เจอ: ' . implode(',', $ids)
        . ' / ต้องมี ' . $bookNullIsbn . ' ต้องไม่มี ' . $bookHasIsbn);

check('FLT-A2',
    in_array((string) $bookEmptyIsbn, $ids, true),
    '🔴 เล่มที่ ISBN เป็นสตริงว่าง ("") ก็ถูกนับด้วย — ไม่ได้เช็คแค่ IS NULL',
    '🔴 เล่มที่ ISBN เป็น "" หาไม่เจอ — ฟอร์มบันทึกช่องว่างเป็น "" ไม่ใช่ NULL '
        . 'เช็คแค่ IS NULL จะพลาดหนังสือกลุ่มใหญ่');

// A3 — ยอดรวมต้องตรงกับความจริงใน DB
$htmlAll = http('GET', "$BASE_URL/admin/books.php?no_isbn=1");
$totalPage = totalFrom($htmlAll);
$totalDb   = (int) $pdo->query("SELECT COUNT(*) FROM books WHERE isbn IS NULL OR isbn = ''")->fetchColumn();
check('FLT-A3', $totalPage === $totalDb,
    "ยอดรวมบนหัวตารางตรงกับ DB ({$totalPage} เล่ม)",
    "🔴 หน้าบอก " . var_export($totalPage, true) . " แต่ DB มี {$totalDb}");

check('FLT-A4', $totalDb === $baselineNoIsbn + 2,
    'จำนวนเพิ่มขึ้นตรงกับ fixture ที่สร้าง (+2)',
    "🔴 คาดว่าเพิ่ม 2 แต่ได้ " . ($totalDb - $baselineNoIsbn));

// A5 — ต้องใช้ร่วมกับตัวกรองอื่นได้ (นี่คือเหตุผลที่แยกเป็นพารามิเตอร์ของตัวเอง)
$htmlCombo = http('GET', "$BASE_URL/admin/books.php?no_isbn=1&status=available&search=" . urlencode('FLTTEST'));
$comboIds = rowIdsFrom($htmlCombo);
check('FLT-A5',
    in_array((string) $bookNullIsbn, $comboIds, true) && !in_array((string) $bookHasIsbn, $comboIds, true),
    'ใช้ร่วมกับตัวกรองสถานะได้ (ไม่มี ISBN + ยังมีของ)',
    '🔴 ใช้ร่วมกับตัวกรองอื่นไม่ได้ — เจอ: ' . implode(',', $comboIds));

// A6 — ต้องอยู่ใน whitelist ของ F-37 ไม่งั้นบันทึกแล้วตัวกรองหาย
check('FLT-A6', in_array('no_isbn', LIST_STATE_BOOKS, true),
    'no_isbn อยู่ใน LIST_STATE_BOOKS — กดแก้ไขแล้วบันทึก ตัวกรองไม่หาย',
    '🔴 ลืมใส่ใน LIST_STATE_BOOKS — เจ้าหน้าที่กรองแล้วกดแก้หนังสือ พอบันทึกเสร็จตัวกรองหาย (บั๊กเดียวกับ F-37)');

// ============================================================
// B. สมาชิกที่เต็มโควตา
// ============================================================
echo "\n── B. สมาชิกที่เต็มโควตา ──\n";

$quotaLimit = MAX_BORROW_BOOKS;
echo "  ℹ️  เพดานโควตาของระบบตอนนี้: {$quotaLimit} เล่ม\n";

// 👤 คนที่เต็มโควตาด้วย "การจองที่รอมารับ" — pending กินโควตา
$userFull = $mkUser('เต็มโควตา');
$stockBooks = [];
for ($i = 0; $i < $quotaLimit; $i++) {
    $stockBooks[] = $mkBook("[FLTTEST] เล่มจอง {$i} {$uniq}", null, 1);
}
foreach ($stockBooks as $bid) {
    $resService->createReservation($userFull, $bid);
}

// 👤 คนที่ "ต่อคิวรอ" เท่าจำนวนเพดาน — 🔴 ต้องไม่ถือว่าเต็มโควตา
//    waiting ไม่กินสต็อกและไม่กินโควตา (F-41 / ROADMAP ข้อ 5)
//    insert ตรงได้เพราะไม่แตะ available — invariant ไม่กระทบ
$userWaiting = $mkUser('ต่อคิวรอ');
$queueBooks = [];
for ($i = 0; $i < $quotaLimit; $i++) {
    $queueBooks[] = $mkBook("[FLTTEST] เล่มคิว {$i} {$uniq}", null, 1);
}
$stQ = $pdo->prepare("INSERT INTO reservations (user_id, book_id, status, queued_at, created_at) VALUES (?, ?, 'waiting', NOW(), NOW())");
foreach ($queueBooks as $bid) {
    $stQ->execute([$userWaiting, $bid]);
}

$htmlQuota = http('GET', "$BASE_URL/admin/members.php?status=quota_full&search=" . urlencode('FLTTEST'));
$quotaIds = rowIdsFrom($htmlQuota, 'member_form');

check('FLT-B1', in_array((string) $userFull, $quotaIds, true),
    'คนที่จองรอมารับครบเพดาน → ถูกกรองเจอว่าเต็มโควตา',
    '🔴 หาไม่เจอ — เจอ: ' . implode(',', $quotaIds) . ' (ต้องมี ' . $userFull . ')');

check('FLT-B2', !in_array((string) $userWaiting, $quotaIds, true),
    "🔴 คนที่ต่อคิวรอ {$quotaLimit} เล่ม → **ไม่ใช่** เต็มโควตา (คิวรอไม่กินโควตา)",
    '🔴 นับคิวรอเป็นโควตาด้วย — คนที่ต่อคิวจะถูกบอกว่ายืมไม่ได้ ทั้งที่ยังยืมได้อีก '
        . $quotaLimit . ' เล่ม (ผิดเงื่อนไขเดียวกับ F-41)');

// B3 — คนที่ต่อคิวต้องยังหาเจอในรายการปกติ (ไม่ได้หายไปไหน)
$htmlAllMembers = http('GET', "$BASE_URL/admin/members.php?search=" . urlencode('FLTTEST'));
check('FLT-B3', in_array((string) $userWaiting, rowIdsFrom($htmlAllMembers, 'member_form'), true),
    'คนที่ต่อคิวรอยังอยู่ในรายการสมาชิกตามปกติ',
    '🔴 คนที่ต่อคิวรอหายไปจากรายการสมาชิก');

// ============================================================
// C. สมาชิกที่ค้างค่าปรับ
// ============================================================
echo "\n── C. สมาชิกที่ค้างค่าปรับ ──\n";

$fineBook = $mkBook("[FLTTEST] เล่มค่าปรับ {$uniq}", null, 5);

/** สร้างการยืมที่มีค่าปรับ — คืนสต็อกเองใน cleanup */
$mkFinedBorrow = function (int $userId, float $fine, bool $stillOut = false) use ($pdo, $fineBook, &$madeBorrows): int {
    $st = $pdo->prepare("
        INSERT INTO borrows (user_id, book_id, borrow_date, due_date, return_date, status, fine_amount, created_at)
        VALUES (?, ?, DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_SUB(CURDATE(), INTERVAL 20 DAY),
                " . ($stillOut ? 'NULL' : 'CURDATE()') . ", ?, ?, NOW())
    ");
    $st->execute([$userId, $fineBook, $stillOut ? 'borrowing' : 'returned', $fine]);
    $id = (int) $pdo->lastInsertId();
    // 🔴 ยืมที่ยังไม่คืน = กันสต็อกไว้ ต้องลด available ให้ตรง invariant
    if ($stillOut) {
        $pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?")->execute([$fineBook]);
    }
    $madeBorrows[] = ['id' => $id, 'book_id' => $fineBook, 'held' => $stillOut];
    return $id;
};

$userOwes   = $mkUser('ค้างค่าปรับ');
$userPaid   = $mkUser('จ่ายแล้ว');
$userWaived = $mkUser('ยกเว้นแล้ว');
$userClean  = $mkUser('ไม่มีค่าปรับ');

$mkFinedBorrow($userOwes, 50.00);

$paidBorrow = $mkFinedBorrow($userPaid, 40.00);
$pdo->prepare("INSERT INTO payments (borrow_id, amount, created_at) VALUES (?, ?, NOW())")
    ->execute([$paidBorrow, 40.00]);

$waivedBorrow = $mkFinedBorrow($userWaived, 30.00);
$pdo->prepare("UPDATE borrows SET fine_waived_at = NOW(), fine_waived_note = 'ทดสอบ' WHERE id = ?")
    ->execute([$waivedBorrow]);

$htmlFine = http('GET', "$BASE_URL/admin/members.php?status=has_unpaid_fine&search=" . urlencode('FLTTEST'));
$fineIds = rowIdsFrom($htmlFine, 'member_form');

check('FLT-C1', in_array((string) $userOwes, $fineIds, true),
    'คนที่ค้างค่าปรับ → ถูกกรองเจอ',
    '🔴 หาไม่เจอ — เจอ: ' . implode(',', $fineIds));

check('FLT-C2', !in_array((string) $userPaid, $fineIds, true),
    '🔴 คนที่จ่ายค่าปรับแล้ว → ไม่ติดในรายการค้าง',
    '🔴 คนที่จ่ายแล้วยังขึ้นว่าค้าง — เจ้าหน้าที่จะไปทวงซ้ำ');

check('FLT-C3', !in_array((string) $userWaived, $fineIds, true),
    '🔴 คนที่ถูกยกเว้นค่าปรับ → ไม่ติดในรายการค้าง',
    '🔴 คนที่ถูกยกเว้นแล้วยังขึ้นว่าค้าง — การยกเว้นไม่มีผล (ผิดเงื่อนไขเดียวกับหน้าการเงิน)');

check('FLT-C4', !in_array((string) $userClean, $fineIds, true),
    'คนที่ไม่เคยมีค่าปรับ → ไม่ติด',
    '🔴 คนที่ไม่มีค่าปรับก็ติดด้วย');

// C5 — 🔴 ตัวเลขต้องตรงกับหน้าการเงิน ไม่งั้นสองหน้าจะบอกคนละอย่าง
$htmlFineAll  = http('GET', "$BASE_URL/admin/members.php?status=has_unpaid_fine");
$memberPageNo = totalFrom($htmlFineAll);
$paymentsNo   = $borrowRepo->countUnpaidDebtors()['people'];
check('FLT-C5', $memberPageNo === $paymentsNo,
    "ยอดค้างชำระตรงกับหน้าการเงิน ({$memberPageNo} คน)",
    "🔴 หน้าสมาชิกบอก " . var_export($memberPageNo, true) . " คน แต่หน้าการเงินบอก {$paymentsNo} คน "
        . '— นิยาม "ค้างชำระ" ไม่ตรงกัน');

// ============================================================
// D. 🔴 ยอดรวมต้องตรงกับจำนวนแถวจริง
// ============================================================
echo "\n── D. ยอดรวมกับจำนวนแถวต้องตรงกัน ──\n";

// 🧠 ตัวนับกับตัวดึงรายการเป็นคนละ query ถ้าคอลัมน์คำนวณไม่ครบทั้งสองฝั่ง
//    ตัวนับจะพังด้วย Unknown column = หน้าขาวทั้งหน้า หรือบอกจำนวนหน้าผิด
$mismatch = [];
foreach ([
    'members.php?status=quota_full'      => ['คน', 'member_form'],
    'members.php?status=has_unpaid_fine' => ['คน', 'member_form'],
    'books.php?no_isbn=1'                => ['เล่ม', 'book_form'],
] as $path => [$unit, $form]) {
    $html  = http('GET', "$BASE_URL/admin/{$path}");
    $total = totalFrom($html);

    if ($total === null) {
        $mismatch[] = "{$path}: อ่านยอดรวมไม่ได้ (หน้าน่าจะพัง)";
        continue;
    }

    // ไล่เก็บ id ทุกหน้าแล้วนับ
    $seen = [];
    $perPage = (int) ITEMS_PER_PAGE;
    $pages = max(1, (int) ceil($total / max(1, $perPage)));
    for ($p = 1; $p <= min($pages, 30); $p++) {
        $sep = str_contains($path, '?') ? '&' : '?';
        $h = http('GET', "$BASE_URL/admin/{$path}{$sep}page={$p}");
        foreach (rowIdsFrom($h, $form) as $id) $seen[$id] = true;
    }
    if ($pages <= 30 && count($seen) !== $total) {
        $mismatch[] = "{$path}: หัวตารางบอก {$total} {$unit} แต่ไล่นับแถวได้ " . count($seen);
    }
}

check('FLT-D1', $mismatch === [],
    'ยอดรวมตรงกับจำนวนแถวจริงทั้ง 3 ตัวกรอง — ตัวนับกับตัวดึงรายการใช้เงื่อนไขชุดเดียวกัน',
    '🔴 ' . implode(' · ', $mismatch));

// ============================================================
// E. ตัวกรองต้องไม่หายเมื่อเปลี่ยนหน้า
// ============================================================
echo "\n── E. ตัวกรองไม่หายเมื่อกดหน้าถัดไป ──\n";

/**
 * 🔴 [บทเรียน] ฉบับแรกจับ href ที่มีชื่อไฟล์ (`members.php?...page=`)
 *    แต่ของจริงเขียนแบบสั้น `href="?status=...&page=2"` → จับได้ 0 ลิงก์
 *    แล้ว foreach ที่วนบน array ว่างก็ไม่ทำอะไร เคสจึง **ผ่านแบบไม่มีฟันเลย**
 *    ตอนนี้จับรูปแบบจริง + บังคับว่าต้องเจอลิงก์อย่างน้อย 1 อัน ไม่งั้นถือว่าสอบตก
 */
$checkPagingKeeps = function (string $id, string $url, string $mustKeep, string $label) {
    $html = http('GET', $url);
    preg_match_all('/href="(\?[^"]*page=\d+[^"]*)"/', $html, $pm);
    $links = array_map(fn($h) => html_entity_decode($h, ENT_QUOTES, 'UTF-8'), $pm[1] ?? []);

    if (!$links) {
        /**
         * 🧠 ไม่มีลิงก์เปลี่ยนหน้า = อาจแปลได้ 2 อย่าง ต้องแยกให้ออก
         *    ① ผลลัพธ์พอดีหน้าเดียว → ถูกต้องแล้ว ไม่ใช่บั๊ก
         *    ② มีหลายหน้าแต่ลิงก์หาย/รูปแบบเปลี่ยน → บั๊กจริง
         *
         * 🔴 เจอตอนรันบน clone ที่ติดตั้งสด: ที่นั่นมีหนังสือ 5 เล่ม สมาชิกไม่กี่คน
         *    ผลลัพธ์จึงพอดีหน้าเดียวเสมอ แล้วเทสต์ฟ้องว่าพัง ทั้งที่ระบบถูกต้อง
         *    (รูปแบบเดียวกับที่เคยเจอใน test_alert_bell และ test_system_health)
         */
        $total = preg_match('/ทั้งหมด\s*([\d,]+)\s*(รายการ|คน|ชื่อเรื่อง)/u', $html, $tm)
            ? (int) str_replace(',', '', $tm[1])
            : null;

        if ($total !== null && $total <= (int) ITEMS_PER_PAGE) {
            pass($id, "⚠️ ผลลัพธ์มี {$total} รายการ พอดีหน้าเดียว จึงไม่มีลิงก์เปลี่ยนหน้าให้ตรวจ "
                . '— ข้ามอย่างมีเหตุผล ไม่ใช่ความล้มเหลว (ต้องรันบนเครื่องที่มีข้อมูลจริงเพื่อตรวจข้อนี้เต็ม)');
            return;
        }
        fail($id, '🔴 ไม่เจอลิงก์เปลี่ยนหน้าเลย ทั้งที่ผลลัพธ์มี '
            . ($total === null ? 'ไม่ทราบจำนวน' : $total . ' รายการ')
            . ' — ลิงก์หายหรือรูปแบบเปลี่ยนไป');
        return;
    }
    $bad = [];
    foreach ($links as $q) {
        if (!str_contains($q, $mustKeep)) $bad[] = $q;
    }
    check($id, $bad === [],
        "{$label} — ลิงก์เปลี่ยนหน้าพาตัวกรองไปด้วยครบ " . count($links) . ' ลิงก์',
        '🔴 ลิงก์เปลี่ยนหน้าทิ้งตัวกรอง: ' . implode(' · ', array_slice($bad, 0, 3)));
};

$checkPagingKeeps('FLT-E1', "$BASE_URL/admin/members.php?status=has_unpaid_fine",
    'status=has_unpaid_fine', 'หน้าสมาชิก (ค้างค่าปรับ)');
// 📚 ต้องมีหนังสือไม่มี ISBN เกิน 1 หน้า ไม่งั้นไม่มีลิงก์เปลี่ยนหน้าให้ตรวจ
//    (เคสนี้เคย "ผ่าน" ทั้งที่ไม่มีลิงก์เลย — ตอนนี้ตัวตรวจถือว่าสอบตกถ้าไม่มีลิงก์
//     จึงต้องสร้าง fixture ให้พอ)
$existingNoIsbn = (int) $pdo->query("SELECT COUNT(*) FROM books WHERE isbn IS NULL OR isbn = ''")->fetchColumn();
$needMore = max(0, (int) ITEMS_PER_PAGE + 1 - $existingNoIsbn);
for ($i = 0; $i < $needMore; $i++) {
    $mkBook("[FLTTEST] เล่มเติมหน้า {$i} {$uniq}", null);
}
echo "  ℹ️  สร้างหนังสือไม่มี ISBN เพิ่ม {$needMore} เล่ม เพื่อให้มีมากกว่า 1 หน้า\n";

$checkPagingKeeps('FLT-E2', "$BASE_URL/admin/books.php?no_isbn=1",
    'no_isbn=1', 'หน้าหนังสือ (ยังไม่ได้ลง ISBN)');

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
