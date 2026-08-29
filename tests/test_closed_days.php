<?php

/**
 * ไม่คิดค่าปรับวันที่ห้องสมุดปิด — งานประจำข้อ 3 + 4
 *
 * ==========================================================================
 * 🔴 ปัญหาเดิม
 * ==========================================================================
 * ค่าปรับนับ **ทุกวันตามปฏิทิน** ไม่สนใจว่าห้องสมุดเปิดหรือไม่
 *   - ยืมก่อนหยุดยาว ครบกำหนดระหว่างที่ปิด กลับมาคืนวันแรกที่เปิด → โดนปรับ
 *     ทั้งที่ **ไม่มีวันไหนให้มาคืนได้เลย**
 *   - ปิดปรับปรุง 60 วัน = 600 บาท/เล่ม ซึ่งแพงกว่าหนังสือส่วนใหญ่
 *
 * 🧠 สองข้อในเอกสาร ("ไม่คิดค่าปรับวันที่ปิด" + "หยุดค่าปรับช่วงปิดยาว")
 *    เป็นฟีเจอร์เดียวกัน — ปิดยาว 60 วันคือช่วงวันปิดที่ยาวขึ้นเท่านั้น
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. การนับวันปิด — ขอบเขตช่วงต้องตรงกับสูตรค่าปรับเป๊ะ · ช่วงซ้อนกันห้ามนับซ้ำ
 * B. ค่าปรับ — หักออกจริง · ปิดยาวไม่ทำให้เป็นหนี้ก้อนโต · ไม่ติดลบ
 * C. 🔴 หน้ารายการยืมกับตอนกดคืนจริง **ต้องคิดตรงกัน**
 *    (สองที่เรียก calculateFine() คนละจังหวะ ถ้าคิดคนละแบบ เจ้าหน้าที่จะเก็บเงินผิด)
 * D. หน้าตั้งค่า — เพิ่ม/ลบได้ · กันข้อมูลที่ทำให้ระบบเพี้ยน
 *
 * 🧹 ลบวันปิดและหนังสือ/สมาชิก/การยืมที่สร้างขึ้นทั้งหมด
 *
 * 📌 การใช้งาน: php tests/test_closed_days.php [รหัสผ่าน admin]
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
require_once __DIR__ . '/../app/Repositories/ClosedDayRepository.php';

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
$COOKIE = tempnam(sys_get_temp_dir(), 'bbcld');

// 🔴 ต้องจำไว้ว่ามีวันปิดอะไรอยู่ก่อนเทสต์ จะได้ไม่ลบของลูกค้าทิ้ง
$preExistingIds = $pdo->query("SELECT id FROM closed_days")->fetchAll(PDO::FETCH_COLUMN);

$madeClosed = $madeBooks = $madeUsers = $madeBorrows = [];
$cleanupDone = false;
$cleanup = function () use (
    &$madeClosed, &$madeBooks, &$madeUsers, &$madeBorrows, &$cleanupDone, $pdo, $COOKIE, $preExistingIds
) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    try {
        if ($pdo->inTransaction()) $pdo->rollBack();
    } catch (Throwable $e) {
        echo '  ⚠️ rollback ไม่สำเร็จ: ' . $e->getMessage() . "\n";
    }

    $failed = [];
    // 📅 ลบ **เฉพาะที่เทสต์สร้าง** — ห้ามลบวันปิดที่ลูกค้าตั้งไว้เอง
    foreach ($madeClosed as $id) {
        if (in_array($id, $preExistingIds)) continue;
        try { $pdo->prepare("DELETE FROM closed_days WHERE id = ?")->execute([$id]); }
        catch (Throwable $e) { $failed[] = "closed#{$id}"; }
    }

    // 🧹 [บทเรียน] กวาดแถวที่ติดป้าย [CLDTEST] ทั้งหมด ไม่ใช่แค่ที่ $madeClosed จำไว้
    //    เจอจริง 2 แถวค้าง:
    //      1. รอบที่ถูกฆ่ากลางคัน (ตอนพิสูจน์ฟันแล้วเกิดลูปไม่รู้จบ) → cleanup ไม่ได้รัน
    //      2. รอบพิสูจน์ฟันเคส D2 — แถวถูกสร้างผ่าน **ฟอร์ม HTTP** ไม่ผ่าน $mkClosed
    //         จึงไม่มีใน $madeClosed เลย
    //    ป้าย [CLDTEST] เป็นของเทสต์นี้เท่านั้น กวาดตามป้ายจึงไม่แตะข้อมูลลูกค้า
    try {
        $swept = $pdo->exec("DELETE FROM closed_days WHERE note LIKE '%[CLDTEST]%'");
        if ($swept > 0) echo "  🧹 กวาดแถวที่ค้างจากรอบก่อนเพิ่มอีก {$swept} แถว\n";
    } catch (Throwable $e) {
        $failed[] = 'กวาด [CLDTEST]';
    }
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
        try { $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]); }
        catch (Throwable $e) { $failed[] = "user#{$uid}"; }
    }
    foreach ($madeBooks as $bid) {
        try { $pdo->prepare("DELETE FROM books WHERE id = ?")->execute([$bid]); }
        catch (Throwable $e) { $failed[] = "book#{$bid}"; }
    }

    echo '  ลบวันปิด ' . count($madeClosed) . ' · หนังสือ ' . count($madeBooks)
        . ' · สมาชิก ' . count($madeUsers) . ' · การยืม ' . count($madeBorrows) . "\n";
    if ($failed) echo '  🔴 ลบไม่สำเร็จ: ' . implode(' · ', $failed) . "\n";

    $left = (int) $pdo->query("SELECT COUNT(*) FROM closed_days")->fetchColumn();
    echo '  วันปิดที่เหลือในระบบ ' . $left . ' รายการ (ก่อนเทสต์มี ' . count($preExistingIds) . ")\n";

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
echo "║  ไม่คิดค่าปรับวันที่ห้องสมุดปิด                            ║\n";
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

$closedRepo = new \App\Repositories\ClosedDayRepository($pdo);
$uniq       = substr((string) getmypid(), -4) . mt_rand(100, 999);

/** สร้างวันปิด แล้วคืน BorrowService ตัวใหม่ (cache สะอาด) */
$mkClosed = function (string $s, string $e, string $note) use ($closedRepo, &$madeClosed): int {
    $id = $closedRepo->create($s, $e, $note);
    $madeClosed[] = $id;
    return $id;
};
$freshService = fn() => new \App\Services\BorrowService($pdo);

// ============================================================
// A. การนับวันปิด
// ============================================================
echo "── A. การนับวันปิด ──\n";

$mkClosed('2026-08-05', '2026-08-09', "[CLDTEST] หยุดยาว {$uniq}");   // 5 วัน

// A1 — 🔴 ขอบเขตต้องเป็น (วันครบกำหนด, วันคืน] ให้ตรงกับสูตรค่าปรับเป๊ะ
//      วันครบกำหนดไม่ถูกนับเป็นวันสาย ตัวหักจึงต้องไม่นับด้วย
//      ครบกำหนด 5 ส.ค. (ซึ่งเป็นวันปิด) คืน 9 ส.ค. → ช่วงที่นับคือ 6,7,8,9 = ปิด 4 วัน
check('CLD-A1',
    $closedRepo->countClosedDaysBetween('2026-08-05', '2026-08-09') === 4,
    'ไม่นับวันครบกำหนดเป็นวันปิด — ขอบเขตตรงกับสูตรค่าปรับ (ได้ 4 ไม่ใช่ 5)',
    '🔴 นับได้ ' . $closedRepo->countClosedDaysBetween('2026-08-05', '2026-08-09')
        . ' — ขอบเขตไม่ตรงกับสูตรค่าปรับ จะหักเกิน/ขาดไป 1 วัน');

// A2 — 🔴 ช่วงที่ซ้อนทับกันห้ามนับซ้ำ
$mkClosed('2026-08-07', '2026-08-07', "[CLDTEST] ซ้อน {$uniq}");
check('CLD-A2',
    $closedRepo->countClosedDaysBetween('2026-08-01', '2026-08-11') === 5,
    '🔴 ช่วงวันปิดที่ซ้อนทับกันไม่ถูกนับซ้ำ (ยังได้ 5 วัน)',
    '🔴 นับซ้ำเป็น ' . $closedRepo->countClosedDaysBetween('2026-08-01', '2026-08-11')
        . ' วัน — ถ้าบวกความยาวของแต่ละช่วงตรง ๆ วันที่ซ้อนกันจะถูกนับสองครั้ง');

// A3 — ช่วงที่ไม่มีวันปิดเลย
check('CLD-A3', $closedRepo->countClosedDaysBetween('2026-09-01', '2026-09-10') === 0,
    'ช่วงที่ไม่มีวันปิด → 0',
    '🔴 ได้ค่าอื่นที่ไม่ใช่ 0');

// A4 — วันที่กลับด้าน ต้องไม่วนไม่รู้จบ
$start = microtime(true);
$rev = $closedRepo->countClosedDaysBetween('2026-08-20', '2026-08-01');
check('CLD-A4', $rev === 0 && (microtime(true) - $start) < 2,
    'วันที่กลับด้าน → คืน 0 ทันที ไม่วนไม่รู้จบ',
    '🔴 คืน ' . $rev . ' หรือใช้เวลานานผิดปกติ');

// ============================================================
// B. ค่าปรับ
// ============================================================
echo "\n── B. ค่าปรับ ──\n";

$svc = $freshService();
$r = $svc->calculateFine('2026-08-01', '2026-08-11');

check('CLD-B1', $r['calendar_days'] === 10 && $r['closed_days'] === 5 && $r['days'] === 5,
    "หักวันปิดออกจริง — ปฏิทิน 10 วัน หัก 5 → คิด 5 วัน = {$r['amount']} บาท",
    '🔴 ผลไม่ถูก: ' . json_encode($r, JSON_UNESCAPED_UNICODE));

check('CLD-B2', abs($r['amount'] - (5 * FINE_PER_DAY)) < 0.01,
    'ยอดเงิน = วันที่เปิด × ค่าปรับต่อวัน (' . (5 * FINE_PER_DAY) . ' บาท)',
    '🔴 ยอดเงินผิด: ' . $r['amount']);

// B3 — 🔴 ปิดยาว 60 วัน ต้องไม่กลายเป็นหนี้ 600 บาท
$mkClosed('2026-06-01', '2026-07-30', "[CLDTEST] ปิดปรับปรุง {$uniq}");
$long = $freshService()->calculateFine('2026-05-30', '2026-07-31');
check('CLD-B3', $long['closed_days'] === 60 && $long['days'] === 2,
    "ปิดปรับปรุง 60 วัน → คิดแค่ {$long['days']} วัน = {$long['amount']} บาท "
        . '(เดิมจะเป็น ' . ($long['calendar_days'] * FINE_PER_DAY) . ')',
    '🔴 ' . json_encode($long, JSON_UNESCAPED_UNICODE));

// B4 — สายทั้งช่วงอยู่ในวันปิดพอดี → ต้องไม่มีค่าปรับเลย
//      📝 ไม่ได้ทดสอบ "ค่าติดลบ" เพราะตัวนับวันปิดไล่ในหน้าต่างเดียวกับ calendar_days
//         ผลลัพธ์จึงไม่มีทางเกิน — max(0,..) ใน Service เป็นการกันไว้เผื่ออนาคตเท่านั้น
$allClosed = $freshService()->calculateFine('2026-06-10', '2026-06-20');
check('CLD-B4',
    $allClosed['days'] === 0 && $allClosed['amount'] == 0
        && $allClosed['closed_days'] === $allClosed['calendar_days'],
    'สายทั้งช่วงตรงกับวันปิดพอดี (' . $allClosed['closed_days'] . '/'
        . $allClosed['calendar_days'] . ' วัน) → ไม่มีค่าปรับ',
    '🔴 ' . json_encode($allClosed, JSON_UNESCAPED_UNICODE));

// B5 — คืนตรงเวลายังต้องไม่มีค่าปรับ (ของเดิมต้องไม่พัง)
$onTime = $freshService()->calculateFine('2026-08-20', '2026-08-20');
check('CLD-B5', $onTime['days'] === 0 && $onTime['amount'] == 0,
    'คืนตรงกำหนด → ไม่มีค่าปรับ (พฤติกรรมเดิมไม่เสีย)',
    '🔴 คืนตรงเวลากลับมีค่าปรับ: ' . json_encode($onTime, JSON_UNESCAPED_UNICODE));

// ============================================================
// C. 🔴 หน้ารายการยืม vs ตอนกดคืนจริง ต้องคิดตรงกัน
// ============================================================
echo "\n── C. ตัวเลขบนหน้าจอกับตอนเก็บเงินจริง ──\n";

$login = http('GET', "$BASE_URL/login.php");
$loggedIn = http('POST', "$BASE_URL/login.php", [
    'csrf_token' => csrfFrom($login), 'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD,
]);
if (!str_contains($loggedIn, 'ออกจากระบบ') && !str_contains($loggedIn, 'logout')) {
    fail('CLD-C0', 'ล็อกอินไม่สำเร็จ — ข้ามหมวด C');
} else {
    $bookService = new \App\Services\BookService($pdo);
    $catId = (int) $pdo->query("SELECT id FROM categories LIMIT 1")->fetchColumn();
    $bookId = (int) $bookService->createBook([
        'title' => "[CLDTEST] เล่มทดสอบค่าปรับ {$uniq}", 'author' => 'ผู้แต่ง',
        'category_id' => $catId, 'quantity' => 1, 'isbn' => null,
    ]);
    $madeBooks[] = $bookId;

    $st = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'member')");
    $st->execute(["[CLDTEST] ผู้ยืม {$uniq}", "cld_{$uniq}@test.com", password_hash('x', PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();
    $madeUsers[] = $userId;

    // 📅 ยืมค้างที่ครบกำหนดไปแล้ว โดยมีวันปิดคร่อมอยู่
    $due = date('Y-m-d', strtotime('-10 days'));
    $closeFrom = date('Y-m-d', strtotime('-7 days'));
    $closeTo   = date('Y-m-d', strtotime('-5 days'));
    $mkClosed($closeFrom, $closeTo, "[CLDTEST] ปิดคร่อมรายการจริง {$uniq}");   // 3 วัน

    $st = $pdo->prepare("
        INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status, fine_amount, created_at)
        VALUES (?, ?, DATE_SUB(CURDATE(), INTERVAL 20 DAY), ?, 'borrowing', 0, NOW())
    ");
    $st->execute([$userId, $bookId, $due]);
    $borrowId = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?")->execute([$bookId]);
    $madeBorrows[] = ['id' => $borrowId, 'book_id' => $bookId, 'held' => true];

    // ตัวเลขที่ Service คำนวณ (ตัวเดียวกับที่หน้าจอใช้)
    $expected = $freshService()->calculateFine($due, null);

    // 🖥️ ตัวเลขที่ขึ้นบนหน้ารายการยืม
    $listHtml = http('GET', "$BASE_URL/admin/borrows.php?search=" . urlencode('CLDTEST'));
    $shownFine = null;
    if (preg_match('/data-fine="([\d.]+)"/', $listHtml, $fm)) {
        $shownFine = (float) $fm[1];
    }

    check('CLD-C1', $expected['closed_days'] === 3,
        "รายการจริงมีวันปิดคร่อมอยู่ {$expected['closed_days']} วัน — เคสนี้จึงมีความหมาย",
        '🔴 วันปิดไม่ได้คร่อมรายการนี้ (' . $expected['closed_days'] . ') — เคสพิสูจน์อะไรไม่ได้');

    if ($shownFine === null) {
        echo "  ⏭️  หน้ารายการยืมไม่มี data-fine ให้อ่าน — ข้าม CLD-C2\n";
    } else {
        check('CLD-C2', abs($shownFine - $expected['amount']) < 0.01,
            "ตัวเลขบนหน้ารายการยืม ({$shownFine}) ตรงกับที่ Service คำนวณ ({$expected['amount']})",
            "🔴 หน้าจอบอก {$shownFine} แต่ตอนเก็บเงินจริงจะเป็น {$expected['amount']} — "
                . 'เจ้าหน้าที่จะเก็บเงินผิดจำนวน');
    }

    // 🔴 กดคืนจริงแล้วค่าปรับที่บันทึกลง DB ต้องเท่ากับที่หน้าจอบอก
    $formHtml = http('GET', "$BASE_URL/admin/borrows.php?search=" . urlencode('CLDTEST'));
    http('POST', "$BASE_URL/admin/borrows.php", [
        'csrf_token' => csrfFrom($formHtml),
        'action' => 'return',
        'borrow_id' => $borrowId,
    ]);
    $st = $pdo->prepare("SELECT status, fine_amount FROM borrows WHERE id = ?");
    $st->execute([$borrowId]);
    $after = $st->fetch(PDO::FETCH_ASSOC);

    if (($after['status'] ?? '') !== 'returned') {
        fail('CLD-C3', '🔴 กดคืนไม่สำเร็จ (สถานะ ' . ($after['status'] ?? '?') . ') — ตรวจไม่ได้');
    } else {
        // คืนแล้ว = ไม่กันสต็อกอีก cleanup ต้องไม่บวกคืนซ้ำ
        $madeBorrows[0]['held'] = false;
        check('CLD-C3', abs((float) $after['fine_amount'] - $expected['amount']) < 0.01,
            "กดคืนจริงแล้วบันทึกค่าปรับ {$after['fine_amount']} บาท — ตรงกับที่หน้าจอบอก",
            "🔴 บันทึกจริง {$after['fine_amount']} แต่หน้าจอบอก {$expected['amount']} — "
                . 'สองที่คิดคนละแบบ');
    }
}

// ============================================================
// D. หน้าตั้งค่า
// ============================================================
echo "\n── D. หน้าตั้งค่า ──\n";

$settingsHtml = http('GET', "$BASE_URL/admin/settings.php");

check('CLD-D1', str_contains($settingsHtml, 'วันที่ห้องสมุดไม่เปิดทำการ'),
    'หน้าตั้งค่ามีส่วนจัดการวันปิด',
    '🔴 ไม่มีส่วนจัดการวันปิด — ลูกค้าตั้งเองไม่ได้ ต้องให้คนแก้ DB ให้');

// D2 — 🔴 วันสิ้นสุดมาก่อนวันเริ่ม ต้องถูกปฏิเสธ
//      ถ้าบันทึกได้ จะได้ช่วงที่ไม่มีวันไหนอยู่ในนั้นเลย ขึ้นในตารางเหมือนตั้งค่าสำเร็จ
$before = (int) $pdo->query("SELECT COUNT(*) FROM closed_days")->fetchColumn();
http('POST', "$BASE_URL/admin/settings.php", [
    'csrf_token' => csrfFrom($settingsHtml),
    'form' => 'closed_add',
    'start_date' => '2026-10-20',
    'end_date'   => '2026-10-01',
    'note'       => "[CLDTEST] กลับด้าน {$uniq}",
]);
$afterBad = (int) $pdo->query("SELECT COUNT(*) FROM closed_days")->fetchColumn();
check('CLD-D2', $afterBad === $before,
    '🔴 วันสิ้นสุดมาก่อนวันเริ่ม → ถูกปฏิเสธ ไม่บันทึก',
    '🔴 บันทึกช่วงที่กลับด้านได้ — จะขึ้นในตารางเหมือนตั้งค่าสำเร็จ แต่ไม่มีผลกับค่าปรับเลย');

// D3 — วันที่ไม่มีอยู่จริง (30 ก.พ.) ต้องถูกปฏิเสธ **โดยแอป** ไม่ใช่ปล่อยให้ MySQL ปฏิเสธ
//      🔴 [บทเรียน] เคยเช็คแค่ "จำนวนแถวไม่เพิ่ม" ซึ่งผ่านทั้งที่ถอด checkdate() ออกแล้ว
//         เพราะ MySQL ปฏิเสธ DATE ที่ไม่มีอยู่จริงให้เอง — แต่ผลคือลูกค้าเจอหน้า error
//         แทนที่จะเจอข้อความบอกว่ากรอกอะไรผิด → ต้องวัดว่าได้ข้อความที่อ่านรู้เรื่อง
$respBad2 = http('POST', "$BASE_URL/admin/settings.php", [
    'csrf_token' => csrfFrom($settingsHtml),
    'form' => 'closed_add',
    'start_date' => '2026-02-30',
    'end_date'   => '2026-02-30',
    'note'       => "[CLDTEST] วันที่ไม่มีจริง {$uniq}",
]);
$afterBad2 = (int) $pdo->query("SELECT COUNT(*) FROM closed_days")->fetchColumn();
$gracefulMsg = str_contains($respBad2, 'วันเริ่มต้นไม่ถูกต้อง')
    || str_contains($respBad2, 'วันสิ้นสุดไม่ถูกต้อง');
check('CLD-D3', $afterBad2 === $before && $gracefulMsg,
    'วันที่ที่ไม่มีอยู่จริง (30 ก.พ.) → แอปปฏิเสธพร้อมข้อความที่อ่านรู้เรื่อง',
    '🔴 ' . ($afterBad2 !== $before
        ? 'บันทึกวันที่ที่ไม่มีอยู่จริงได้'
        : 'ไม่บันทึกก็จริง แต่ไม่มีข้อความบอกผู้ใช้ว่ากรอกอะไรผิด '
          . '(น่าจะเป็น MySQL ปฏิเสธเอง ลูกค้าจะเจอหน้า error แทน)'));

// D4 — เพิ่มผ่านหน้าเว็บได้จริง
$freshSettings = http('GET', "$BASE_URL/admin/settings.php");
http('POST', "$BASE_URL/admin/settings.php", [
    'csrf_token' => csrfFrom($freshSettings),
    'form' => 'closed_add',
    'start_date' => '2026-11-01',
    'end_date'   => '2026-11-03',
    'note'       => "[CLDTEST] เพิ่มผ่านเว็บ {$uniq}",
]);
$addedId = (int) $pdo->query("
    SELECT id FROM closed_days WHERE note LIKE '%เพิ่มผ่านเว็บ%' ORDER BY id DESC LIMIT 1
")->fetchColumn();
if ($addedId > 0) $madeClosed[] = $addedId;
check('CLD-D4', $addedId > 0,
    'เพิ่มวันปิดผ่านหน้าเว็บได้',
    '🔴 เพิ่มไม่สำเร็จ');

// D5 — ลบผ่านหน้าเว็บได้จริง
if ($addedId > 0) {
    $s2 = http('GET', "$BASE_URL/admin/settings.php");
    http('POST', "$BASE_URL/admin/settings.php", [
        'csrf_token' => csrfFrom($s2),
        'form' => 'closed_delete',
        'id'   => $addedId,
    ]);
    $stillThere = (int) $pdo->query("SELECT COUNT(*) FROM closed_days WHERE id = {$addedId}")->fetchColumn();
    check('CLD-D5', $stillThere === 0,
        'ลบวันปิดผ่านหน้าเว็บได้',
        '🔴 ลบไม่สำเร็จ');
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
