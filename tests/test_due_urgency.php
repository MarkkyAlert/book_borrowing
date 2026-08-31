<?php

/**
 * ความเร่งด่วนในกระดิ่ง — "วันนี้" กับ "ใกล้หมดอายุ"
 *
 * ==========================================================================
 * 🔴 ปัญหาเดิม: กระดิ่งบอกช่วงกว้างช่วงเดียว ไม่บอกว่าด่วนแค่ไหน
 * ==========================================================================
 * · สมาชิกเห็น "จองไว้ รอมารับ 1" — ไม่รู้ว่าเหลือกี่วัน
 *   ค่าเริ่มต้นให้เวลามารับแค่ 2 วัน (RESERVATION_EXPIRE_DAYS) เสียคิวได้ง่าย ๆ
 * · "ใกล้ครบกำหนด 74" กลืน "ครบกำหนดวันนี้ 20" ไว้ข้างใน มองไม่เห็นแยก
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. ตัวเลขตรงกับ query ตรง ๆ + 🔴 ป้ายแดง **ไม่นับซ้ำ**
 * B. ครบกำหนดวันนี้ — เลขในกระดิ่ง = จำนวนแถวในหน้าปลายทาง
 * C. จองใกล้หมดอายุ — 🔴 เกณฑ์ต้องตรงกับป้าย "ใกล้หมดอายุ!" ใน my_reservations.php
 *    + คิวรอ (expires_at NULL) ต้องไม่ถูกนับ
 * D. สมาชิกเห็นเฉพาะของตัวเอง
 * E. ลิงก์เปิดได้ด้วยสิทธิ์ของคนที่เห็น + เลขตรงกับหน้าปลายทาง
 * F. 🔴 ใบรายชื่อโทรตามต้องยัง "รวมวันนี้" เหมือนเดิม
 *
 * 🧹 ยกเลิกการจองที่สร้าง (คืนสต็อก) · ลบหนังสือ/สมาชิกที่สร้างเอง
 *
 * 📌 การใช้งาน: php tests/test_due_urgency.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Repositories/BookRepository.php';
require_once __DIR__ . '/../app/Repositories/BorrowRepository.php';
require_once __DIR__ . '/../app/Repositories/ReservationRepository.php';
require_once __DIR__ . '/../app/Services/ReservationService.php';
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

$pdo      = getDB();
$uniq     = bin2hex(random_bytes(3));
$bookRepo = new App\Repositories\BookRepository($pdo);
$resRepo  = new App\Repositories\ReservationRepository($pdo);
$resSvc   = new App\Services\ReservationService($pdo);

/**
 * 🧹 ทุกอย่างที่สร้างต้องถูกเก็บกวาด แม้สคริปต์ตายกลางคัน
 *    ยกเลิกการจองผ่าน Service เท่านั้น — ลบแถวดิบ ๆ จะทำให้ available หายไปเฉย ๆ
 */
$madeReservations = [];
$madeBooks = [];
$madeUsers = [];
$cleanup = function () use ($pdo, $resSvc, &$madeReservations, &$madeBooks, &$madeUsers) {
    foreach ($madeReservations as $rid) {
        try { $resSvc->cancelReservation((int) $rid); } catch (Throwable $e) {}
    }
    $madeReservations = [];

    /**
     * 🔴 ต้องลบแถว reservations ทิ้งด้วย ไม่ใช่แค่ยกเลิก
     *    ยกเลิกแล้วแถวยังอยู่ (status = 'cancelled') → foreign key กันไม่ให้ลบหนังสือ/สมาชิก
     *    → รันรอบถัดไปจะชนกับ unique index ของรอบก่อน (เจอมาแล้วตอนเขียนเทสต์นี้)
     * 🧠 ลบแถวตรง ๆ ตรงนี้ปลอดภัย เพราะ cancelReservation() คืนสต็อกไปเรียบร้อยแล้ว
     */
    foreach ($madeBooks as $bid) {
        try { $pdo->prepare("DELETE FROM reservations WHERE book_id = ?")->execute([(int) $bid]); } catch (Throwable $e) {}
    }
    foreach ($madeBooks as $bid) {
        try { $pdo->prepare("DELETE FROM books WHERE id = ?")->execute([(int) $bid]); } catch (Throwable $e) {}
    }
    foreach ($madeUsers as $uidD) {
        try { $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([(int) $uidD]); } catch (Throwable $e) {}
    }
    $madeBooks = []; $madeUsers = [];
};
register_shutdown_function($cleanup);

echo "\n🔔 ความเร่งด่วนในกระดิ่ง\n══════════════════════════════════════\n\n";

// ============================================================
echo "── A. ตัวเลข + ไม่นับซ้ำ ──\n";
// ============================================================

$svc    = new App\Services\DashboardService($pdo);
$counts = $svc->getAlertCounts();

$q = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
$rawDueToday = $q("SELECT COUNT(*) FROM borrows WHERE status='borrowing' AND due_date = CURDATE()");
$rawDueSoon  = $q("SELECT COUNT(*) FROM borrows WHERE status='borrowing' AND due_date >= CURDATE()
                    AND due_date <= DATE_ADD(CURDATE(), INTERVAL " . (int) DUE_SOON_DAYS . " DAY)");

check('DUE-A1', $counts['due_today'] === $rawDueToday && $counts['due_soon'] === $rawDueSoon,
    "ครบกำหนดวันนี้ {$counts['due_today']} · ใกล้ครบกำหนด {$counts['due_soon']} — ตรงกับ query ตรง ๆ",
    "🔴 ไม่ตรง: กระดิ่ง {$counts['due_today']}/{$counts['due_soon']} · query {$rawDueToday}/{$rawDueSoon}");

/**
 * 🔴 หัวใจของข้อนี้: due_today เป็นส่วนย่อยของ due_soon
 *    ถ้าเอาไปบวกใน total การยืมรายการเดียวจะถูกนับสองรอบ = ตัวเลขบนป้ายโกหก
 */
$expectTotal = $counts['overdue'] + $counts['due_soon']
             + $counts['pending_reservations'] + $counts['unpaid_people'];
check('DUE-A2', $counts['total'] === $expectTotal,
    "ป้ายแดงนับ {$counts['total']} (ไม่ได้บวก due_today {$counts['due_today']} "
        . "และ จองใกล้หมดอายุ {$counts['expiring_reservations']} ซ้ำเข้าไป)",
    "🔴 ป้ายนับ {$counts['total']} แต่ควรเป็น {$expectTotal} — นับรายการเดียวซ้ำสองรอบ");

check('DUE-A3', $counts['due_today'] <= $counts['due_soon'],
    'ครบกำหนดวันนี้ ⊆ ใกล้ครบกำหนด — ความสัมพันธ์ที่ป้ายบอกเป็นจริง',
    "🔴 วันนี้ {$counts['due_today']} มากกว่าใกล้ครบกำหนด {$counts['due_soon']} — เป็นไปไม่ได้");

// ============================================================
echo "\n── B. ครบกำหนดวันนี้ ──\n";
// ============================================================

$borrowRepo = new App\Repositories\BorrowRepository($pdo);
$listed = count($borrowRepo->findAll(['due_today' => true, 'limit' => 1000, 'offset' => 0]));
check('DUE-B1', $listed === $counts['due_today'],
    "กระดิ่ง {$counts['due_today']} · หน้า borrows.php?filter=due_today แสดง {$listed} — ตรงกัน",
    "🔴 กระดิ่ง {$counts['due_today']} แต่หน้าปลายทางแสดง {$listed} — กดแล้วเจอคนละจำนวน");

// ============================================================
echo "\n── C. จองใกล้หมดอายุ ──\n";
// ============================================================

$before = $resRepo->countExpiringSoon();

// 🧪 สร้างหนังสือ + สมาชิก แล้วจองจริงผ่าน Service (สต็อกถูกหักถูกต้อง)
$bookId = $bookRepo->create([
    // 🧠 ต้องใส่ ISBN ไม่ซ้ำ — มี unique index uq_isbn และค่า '' ก็ชนกับแถวอื่นที่เป็น '' ได้
    'title' => "[DUETEST] หนังสือ {$uniq}", 'author' => 'ผู้แต่งทดสอบ', 'isbn' => "DUE{$uniq}",
    'category_id' => null, 'description' => '', 'quantity' => 2, 'price' => 100,
    'is_visible' => 1, 'is_reference' => 0,
]);
$madeBooks[] = $bookId;

$st = $pdo->prepare("INSERT INTO users (name, email, password, phone, role, must_change_password)
                     VALUES (?, ?, ?, ?, 'member', 0)");
$st->execute(["[DUETEST] สมาชิก {$uniq}", "due{$uniq}@test.local", hashPassword('Test1234!'), '0800000000']);
$memberId = (int) $pdo->lastInsertId();
$madeUsers[] = $memberId;

$made = $resSvc->createReservation($memberId, $bookId);
$resId = (int) ($made['reservation_id'] ?? $made['id'] ?? 0);
if ($resId <= 0) {
    fail('DUE-C0', '🔴 สร้างการจองไม่สำเร็จ — ข้อ C เชื่อไม่ได้');
} else {
    $madeReservations[] = $resId;
    pass('DUE-C0', "สร้างการจองจริงผ่าน Service (id={$resId}) — สต็อกถูกหักตามปกติ");

    // ⏰ ดันวันหมดอายุให้เหลือ 2 ชั่วโมง (ยังอยู่ในเกณฑ์ 24 ชม.)
    $pdo->prepare("UPDATE reservations SET expires_at = NOW() + INTERVAL 2 HOUR WHERE id = ?")
        ->execute([$resId]);
    check('DUE-C1', $resRepo->countExpiringSoon() === $before + 1,
        'จองที่เหลือ 2 ชั่วโมง → ถูกนับว่าใกล้หมดอายุ',
        '🔴 ไม่ถูกนับ');

    // ⏰ ดันออกไป 5 วัน (พ้นเกณฑ์) → ต้องไม่ถูกนับ
    $pdo->prepare("UPDATE reservations SET expires_at = NOW() + INTERVAL 5 DAY WHERE id = ?")
        ->execute([$resId]);
    check('DUE-C2', $resRepo->countExpiringSoon() === $before,
        'จองที่เหลือ 5 วัน → ไม่ถูกนับ เกณฑ์ทำงานจริงไม่ใช่นับทุกอันที่ pending',
        '🔴 ยังถูกนับทั้งที่เหลือตั้ง 5 วัน — เกณฑ์ไม่ทำงาน');

    /**
     * 🔴 เกณฑ์ของกระดิ่ง ต้องเป็นเกณฑ์เดียวกับป้ายแดง "ใกล้หมดอายุ!" ใน my_reservations.php
     *    ไม่งั้นกระดิ่งขึ้น 1 แต่เปิดหน้าไปไม่มีรายการไหนติดป้าย = แย่กว่าไม่เตือน
     */
    $pdo->prepare("UPDATE reservations SET expires_at = NOW() + INTERVAL 2 HOUR WHERE id = ?")
        ->execute([$resId]);
    $row = $pdo->query("SELECT expires_at, status FROM reservations WHERE id = {$resId}")->fetch();
    $pageBadge = $row['status'] === 'pending' && strtotime($row['expires_at']) < strtotime('+1 day');
    $bellSays  = $resRepo->countExpiringSoon(null) > $before;
    check('DUE-C3', $pageBadge === $bellSays,
        'เกณฑ์กระดิ่ง = เกณฑ์ป้าย "ใกล้หมดอายุ!" ในหน้าการจองของฉัน',
        '🔴 กระดิ่งกับหน้าเว็บใช้คนละเกณฑ์ — กดเข้าไปแล้วจะไม่เห็นว่าอันไหนด่วน');

    /**
     * 🔄 คิวรอไม่มีวันหมดอายุ (expires_at = NULL) → ห้ามนับ
     *
     * 🧠 ต้องใช้สมาชิกคนที่สอง — มี unique index `uq_reservation_active`
     *    กันไม่ให้คนเดียวจองเล่มเดียวซ้ำซ้อน (เจอตอนเขียนเทสต์นี้ ซึ่งเป็นเรื่องดี)
     */
    $st3 = $pdo->prepare("INSERT INTO users (name, email, password, phone, role, must_change_password)
                          VALUES (?, ?, ?, ?, 'member', 0)");
    $st3->execute(["[DUETEST] สมาชิกคิว {$uniq}", "queue{$uniq}@test.local", hashPassword('Test1234!'), '0800000001']);
    $queueMemberId = (int) $pdo->lastInsertId();
    $madeUsers[] = $queueMemberId;

    $st2 = $pdo->prepare("INSERT INTO reservations (user_id, book_id, status, expires_at, created_at)
                          VALUES (?, ?, 'waiting', NULL, NOW())");
    $st2->execute([$queueMemberId, $bookId]);
    $waitingId = (int) $pdo->lastInsertId();
    check('DUE-C4', $resRepo->countExpiringSoon() === $before + 1,
        'คิวรอ (expires_at เป็น NULL) ไม่ถูกนับ — เทียบ NULL ใน SQL ได้ NULL ไม่ใช่ TRUE',
        '🔴 คิวรอถูกนับด้วย ทั้งที่ไม่มีวันหมดอายุ');
    // 🧹 คิวรอไม่เคยหักสต็อก ลบแถวตรงได้โดยไม่กระทบ invariant
    $pdo->prepare("DELETE FROM reservations WHERE id = ?")->execute([$waitingId]);

    // 🔗 ตัวนับ = ตัวกรอง
    check('DUE-C5', $resRepo->countExpiringSoon() === $resRepo->countAll(['expiring' => '1']),
        'ตัวนับในกระดิ่ง = จำนวนแถวใน reservations.php?expiring=1',
        '🔴 ตัวนับกับตัวกรองให้คนละเลข');

    // 👤 ฝั่งสมาชิก: เห็นเฉพาะของตัวเอง
    $mine   = $borrowRepo->getMemberAlertCounts($memberId, (int) DUE_SOON_DAYS);
    $other  = (int) $pdo->query("SELECT id FROM users WHERE role='member' AND id <> {$memberId} LIMIT 1")->fetchColumn();
    $theirs = $borrowRepo->getMemberAlertCounts($other, (int) DUE_SOON_DAYS);
    check('DUE-D1', $mine['expiring_reservations'] === 1 && $theirs['expiring_reservations'] === 0,
        "สมาชิกที่จองเห็น 1 · สมาชิกคนอื่นเห็น 0 — ไม่รั่วข้ามคน",
        "🔴 เจ้าตัวเห็น {$mine['expiring_reservations']} · คนอื่นเห็น {$theirs['expiring_reservations']}");

    check('DUE-D2', $mine['total'] === $mine['overdue'] + $mine['due_soon'] + $mine['ready_pickup'] + $mine['unpaid'],
        'ป้ายแดงฝั่งสมาชิกก็ไม่นับซ้ำเหมือนกัน',
        '🔴 ป้ายฝั่งสมาชิกนับ due_today/expiring ซ้ำเข้าไปด้วย');
}

// ============================================================
echo "\n── E. ลิงก์เปิดได้จริง + เลขตรงกับหน้า ──\n";
// ============================================================

$jar = tempnam(sys_get_temp_dir(), 'bbdue');
$lp  = httpAs($jar, 'GET', "{$BASE_URL}/login.php");
httpAs($jar, 'POST', "{$BASE_URL}/login.php",
    ['csrf_token' => csrfFrom($lp), 'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD]);

$expPage = httpAs($jar, 'GET', "{$BASE_URL}/admin/reservations.php?expiring=1");
check('DUE-E1', str_contains($expPage, 'เฉพาะการจองที่ใกล้หมดอายุ'),
    'reservations.php?expiring=1 เปิดได้และบอกชัดว่ากำลังกรองอยู่',
    '🔴 หน้าไม่ขึ้นแถบบอกว่ากรองอยู่ — จะนึกว่าทั้งระบบมีเท่านี้');

$dueTodayPage = httpAs($jar, 'GET', "{$BASE_URL}/admin/borrows.php?filter=due_today");
check('DUE-E2', $dueTodayPage !== '' && !str_contains($dueTodayPage, 'ไม่มีสิทธิ์'),
    'borrows.php?filter=due_today เปิดได้ด้วยสิทธิ์ผู้ดูแล',
    '🔴 เปิดไม่ได้');

$home = httpAs($jar, 'GET', "{$BASE_URL}/admin/index.php");
$freshCounts = (new App\Services\DashboardService($pdo))->getAlertCounts();
check('DUE-E3',
    $freshCounts['due_today'] === 0 || str_contains($home, 'ครบกำหนดคืนวันนี้'),
    $freshCounts['due_today'] > 0
        ? 'แถว "ครบกำหนดคืนวันนี้" แสดงในกระดิ่งจริง'
        : 'วันนี้ไม่มีรายการครบกำหนด จึงไม่ขึ้นแถว — ถูกต้อง',
    '🔴 มีรายการครบกำหนดวันนี้แต่กระดิ่งไม่ขึ้นแถวนั้น');
@unlink($jar);

// ============================================================
echo "\n── F. ไม่ทำใบรายชื่อโทรตามพัง ──\n";
// ============================================================

require_once __DIR__ . '/../app/Repositories/ReportRepository.php';
$reportRepo = new App\Repositories\ReportRepository($pdo);
$reportRows = $reportRepo->getDueSoonReport((int) DUE_SOON_DAYS);
$hasToday   = false;
foreach ($reportRows as $r) {
    if ((int) ($r['days_left'] ?? -1) === 0) { $hasToday = true; break; }
}
check('DUE-F1', $rawDueToday === 0 || $hasToday,
    'ใบรายชื่อโทรตามยังรวมคนที่ครบกำหนด "วันนี้" เหมือนเดิม — คนที่ต้องโทรที่สุดไม่หายไป',
    '🔴 คนที่ครบกำหนดวันนี้หายไปจากใบโทรตาม');

check('DUE-F2', count($reportRows) === $counts['due_soon'],
    "จำนวนในใบโทรตาม (" . count($reportRows) . ") ="  . " เลข \"ใกล้ครบกำหนด\" ในกระดิ่ง ({$counts['due_soon']})",
    '🔴 เลขในกระดิ่งไม่ตรงกับรายงานที่มันลิงก์ไป');

// 🧹 เก็บกวาด แล้วตรวจว่าไม่เหลืออะไร
$cleanup();
$leftRes  = (int) $pdo->query("SELECT COUNT(*) FROM reservations r JOIN users u ON u.id=r.user_id
                               WHERE u.name LIKE '%[DUETEST]%'")->fetchColumn();
$leftRows = (int) $pdo->query("SELECT COUNT(*) FROM books WHERE title LIKE '%[DUETEST]%'")->fetchColumn()
          + (int) $pdo->query("SELECT COUNT(*) FROM users WHERE name LIKE '%[DUETEST]%'")->fetchColumn();
check('DUE-G1', $leftRes === 0 && $leftRows === 0,
    'ลบหนังสือ/สมาชิก/การจองที่สร้างขึ้นครบ',
    "🔴 เหลือค้าง: การจอง {$leftRes} · หนังสือ+สมาชิก {$leftRows}");

$inv = (int) $pdo->query("SELECT COUNT(*) FROM books b WHERE b.available <> b.quantity
  - (SELECT COUNT(*) FROM borrows bo WHERE bo.book_id=b.id AND bo.status='borrowing')
  - (SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id AND r.status='pending')")->fetchColumn();
check('DUE-G2', $inv === 0,
    'สต็อกยังตรงสูตรทุกเล่ม — จองแล้วยกเลิกผ่าน Service ไม่ทำ invariant พัง',
    "🔴 เหลือเล่มเพี้ยน {$inv} เล่ม");

// ============================================================
echo "\n══════════════════════════════════════\n";
printf(" RESULTS: %d/%d passed (%.1f%%)%s\n",
    $results['passed'], $results['total'],
    $results['total'] ? $results['passed'] / $results['total'] * 100 : 0,
    $results['failed'] ? ' | ' . $results['failed'] . ' FAILED' : '');
echo "══════════════════════════════════════\n";

exit($results['failed'] > 0 ? 1 : 0);
