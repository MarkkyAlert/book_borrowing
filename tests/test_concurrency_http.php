<?php

/**
 * Concurrency Tests — เจ้าหน้าที่ 2 คนกดพร้อมกันจริง ๆ ผ่าน HTTP
 *
 * ครอบคลุม race condition ที่ idempotency key (ซึ่งเก็บใน session) กันไม่ได้
 * เพราะเป็นคนละ session กัน — ด่านที่ต้องรับไว้คือ row lock + constraint ใน DB:
 *
 * - CC-01 อนุมัติการจองใบเดียวกันพร้อมกัน  → ต้องได้ borrow เดียว
 * - CC-02 คืนหนังสือรายการเดียวกันพร้อมกัน → stock ต้อง +1 ครั้งเดียว
 * - CC-03 รับชำระค่าปรับรายการเดียวกันพร้อมกัน → payment ต้องมีแถวเดียว
 * - CC-04 ยืมหนังสือเล่มสุดท้ายพร้อมกัน (คนละสมาชิก) → ต้องสำเร็จคนเดียว, available ห้ามติดลบ
 *
 * Usage: php tests/test_concurrency_http.php [admin_password]
 * ⚠️ รันบน CLI เท่านั้น + ต้องเปิด Apache
 * ⚠️ ต้องมีข้อมูลจาก tests/fixtures/seed_test_data.php (ใช้บัญชี staff คนที่ 2)
 *
 * 🧠 ทำไมต้องยิงพร้อมกันจริง:
 *    การอ่านโค้ดเห็นแค่ว่ามี FOR UPDATE เขียนไว้ แต่ไม่ได้พิสูจน์ว่ามันกันได้จริง
 *    ใช้ curl_multi ยิง 2 request ออกไปพร้อมกัน แล้ววัดผลจากสถานะ DB หลังจบ
 *    (PHP ล็อกไฟล์ session ต่อ session — ใช้คนละบัญชีจึงไม่ถูก serialize ที่ชั้น session)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$BASE_URL       = rtrim(APP_URL, '/');
$ADMIN_EMAIL    = 'admin@library.com';
$ADMIN_PASSWORD = $argv[1] ?? '123456';
$STAFF_EMAIL    = 't_staff2@test.local';
$STAFF_PASSWORD = '123456';

$pdo     = getDB();
$results = ['passed' => 0, 'failed' => 0, 'total' => 0, 'errors' => []];

function pass(string $id, string $msg = 'OK'): void
{
    global $results;
    $results['total']++;
    $results['passed']++;
    echo "  \033[32m✅ $id\033[0m: $msg\n";
}

function fail(string $id, string $msg): void
{
    global $results;
    $results['total']++;
    $results['failed']++;
    $results['errors'][] = "$id: $msg";
    echo "  \033[31m❌ $id\033[0m: $msg\n";
}

// ============================================================
// HTTP helpers — 1 cookie jar ต่อ 1 "เจ้าหน้าที่"
// ============================================================
function newJar(string $tag): string
{
    return sys_get_temp_dir() . "/bb_cc_{$tag}_" . getmypid();
}

function req(string $jar, string $method, string $url, array $fields = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = curl_exec($ch);
    curl_close($ch);
    return ['body' => (string) $body];
}

function csrf(string $html): string
{
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}

function login(string $jar, string $email, string $password): bool
{
    $r = req($jar, 'GET', $GLOBALS['BASE_URL'] . '/login.php');
    $r = req($jar, 'POST', $GLOBALS['BASE_URL'] . '/login.php', [
        'csrf_token' => csrf($r['body']),
        'email'      => $email,
        'password'   => $password,
    ]);
    return str_contains($r['body'], 'Dashboard') || str_contains($r['body'], 'จัดการ');
}

/**
 * 🎯 ยิง 2 POST ออกไป "พร้อมกัน" ด้วย curl_multi
 *
 * 📥 $calls = [[jar, url, fields], [jar, url, fields]]
 * 📤 คืน body ของทั้งสอง request
 *
 * 🧠 curl_multi_exec ปล่อย request ทั้งคู่ออกไปในรอบเดียว — ต่างจากการยิงทีละอัน
 *    ซึ่งอันที่สองจะเริ่มหลังอันแรกจบไปแล้ว (ไม่เกิด race)
 */
function fireTogether(array $calls): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($calls as $i => [$jar, $url, $fields]) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running > 0);

    $out = [];
    foreach ($handles as $i => $ch) {
        $out[$i] = (string) curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

function scalar(string $sql, array $params = [])
{
    $st = $GLOBALS['pdo']->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}

// ============================================================
// เตรียม session ของเจ้าหน้าที่ 2 คน
// ============================================================
echo "\n╔════════════════════════════════════════════════════════════╗\n";
echo "║  Concurrency Tests — เจ้าหน้าที่ 2 คนกดพร้อมกัน            ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "  เป้าหมาย: $BASE_URL\n\n";

$jarA = newJar('a');
$jarB = newJar('b');

if (!login($jarA, $ADMIN_EMAIL, $ADMIN_PASSWORD)) {
    echo "  ❌ เจ้าหน้าที่ A ($ADMIN_EMAIL) login ไม่สำเร็จ\n\n";
    exit(1);
}
if (!login($jarB, $STAFF_EMAIL, $STAFF_PASSWORD)) {
    echo "  ❌ เจ้าหน้าที่ B ($STAFF_EMAIL) login ไม่สำเร็จ\n";
    echo "     ต้องรัน `php tests/fixtures/seed_test_data.php` ก่อน\n\n";
    exit(1);
}
echo "  เจ้าหน้าที่ A = $ADMIN_EMAIL\n";
echo "  เจ้าหน้าที่ B = $STAFF_EMAIL\n";

/** ดึง CSRF ของทั้งสอง session จากหน้าเดียวกัน */
function tokensFor(string $path): array
{
    global $jarA, $jarB, $BASE_URL;
    return [
        csrf(req($jarA, 'GET', $BASE_URL . $path)['body']),
        csrf(req($jarB, 'GET', $BASE_URL . $path)['body']),
    ];
}

// ============================================================
// CC-01 — อนุมัติการจองใบเดียวกันพร้อมกัน
// ============================================================
echo "\n── CC-01: อนุมัติการจองใบเดียวกันพร้อมกัน ──\n";

$resId = (int) scalar("SELECT id FROM reservations WHERE status='pending' ORDER BY id DESC LIMIT 1");
if (!$resId) {
    fail('CC-01', 'ไม่มี pending reservation ให้ทดสอบ (รัน seed ก่อน)');
} else {
    $bookId  = (int) scalar("SELECT book_id FROM reservations WHERE id=?", [$resId]);
    $availB4 = (int) scalar("SELECT available FROM books WHERE id=?", [$bookId]);
    [$tA, $tB] = tokensFor('/admin/reservations.php');

    fireTogether([
        [$jarA, "$BASE_URL/admin/reservations.php", ['csrf_token' => $tA, 'id' => $resId, 'action' => 'approve']],
        [$jarB, "$BASE_URL/admin/reservations.php", ['csrf_token' => $tB, 'id' => $resId, 'action' => 'approve']],
    ]);

    $status   = scalar("SELECT status FROM reservations WHERE id=?", [$resId]);
    $borrows  = (int) scalar("SELECT COUNT(*) FROM borrows WHERE user_id=(SELECT user_id FROM reservations WHERE id=?) AND book_id=? AND status='borrowing'", [$resId, $bookId]);
    $availNow = (int) scalar("SELECT available FROM books WHERE id=?", [$bookId]);

    $borrows === 1
        ? pass('CC-01a', "สร้างรายการยืมเพียง 1 รายการ (สถานะการจอง: $status)")
        : fail('CC-01a', "สร้างรายการยืม $borrows รายการ — ควรได้ 1");

    // อนุมัติไม่หัก stock ซ้ำ (หักไปแล้วตอนจอง)
    $availNow === $availB4
        ? pass('CC-01b', "stock ไม่ถูกหักซ้ำ ($availB4 → $availNow)")
        : fail('CC-01b', "stock เปลี่ยนจาก $availB4 เป็น $availNow — ไม่ควรเปลี่ยน");
}

// ============================================================
// CC-02 — คืนหนังสือรายการเดียวกันพร้อมกัน
// ============================================================
echo "\n── CC-02: คืนหนังสือรายการเดียวกันพร้อมกัน ──\n";

$borrowId = (int) scalar("SELECT id FROM borrows WHERE status='borrowing' ORDER BY id DESC LIMIT 1");
if (!$borrowId) {
    fail('CC-02', 'ไม่มีรายการยืมค้างให้ทดสอบ');
} else {
    $bookId  = (int) scalar("SELECT book_id FROM borrows WHERE id=?", [$borrowId]);
    $availB4 = (int) scalar("SELECT available FROM books WHERE id=?", [$bookId]);
    [$tA, $tB] = tokensFor('/admin/borrows.php');

    fireTogether([
        [$jarA, "$BASE_URL/admin/borrows.php", ['csrf_token' => $tA, 'action' => 'return', 'borrow_id' => $borrowId]],
        [$jarB, "$BASE_URL/admin/borrows.php", ['csrf_token' => $tB, 'action' => 'return', 'borrow_id' => $borrowId]],
    ]);

    $status   = scalar("SELECT status FROM borrows WHERE id=?", [$borrowId]);
    $availNow = (int) scalar("SELECT available FROM books WHERE id=?", [$bookId]);
    $payments = (int) scalar("SELECT COUNT(*) FROM payments WHERE borrow_id=?", [$borrowId]);

    $status === 'returned'
        ? pass('CC-02a', 'สถานะเปลี่ยนเป็น returned')
        : fail('CC-02a', "สถานะเป็น $status");

    $availNow === $availB4 + 1
        ? pass('CC-02b', "stock คืน +1 ครั้งเดียว ($availB4 → $availNow)")
        : fail('CC-02b', "stock $availB4 → $availNow — ควรเป็น " . ($availB4 + 1) . " (คืนซ้ำ)");

    $payments <= 1
        ? pass('CC-02c', "payment ไม่เกิน 1 แถว ($payments)")
        : fail('CC-02c', "payment $payments แถว — ซ้ำ");
}

// ============================================================
// CC-03 — รับชำระค่าปรับรายการเดียวกันพร้อมกัน
// ============================================================
echo "\n── CC-03: รับชำระค่าปรับรายการเดียวกันพร้อมกัน ──\n";

$fineId = (int) scalar("SELECT b.id FROM borrows b LEFT JOIN payments p ON p.borrow_id=b.id
                        WHERE b.fine_amount > 0 AND p.id IS NULL ORDER BY b.id DESC LIMIT 1");
if (!$fineId) {
    fail('CC-03', 'ไม่มีค่าปรับค้างชำระให้ทดสอบ');
} else {
    [$tA, $tB] = tokensFor('/admin/payments.php');

    fireTogether([
        [$jarA, "$BASE_URL/admin/payments.php", ['csrf_token' => $tA, 'action' => 'pay_fine', 'borrow_id' => $fineId]],
        [$jarB, "$BASE_URL/admin/payments.php", ['csrf_token' => $tB, 'action' => 'pay_fine', 'borrow_id' => $fineId]],
    ]);

    $count = (int) scalar("SELECT COUNT(*) FROM payments WHERE borrow_id=?", [$fineId]);
    $count === 1
        ? pass('CC-03', 'บันทึกการชำระเพียงแถวเดียว')
        : fail('CC-03', "payment $count แถว — ควรได้ 1");
}

// ============================================================
// CC-04 — ยืมหนังสือเล่มสุดท้ายพร้อมกัน (คนละสมาชิก)
// ============================================================
echo "\n── CC-04: ยืมเล่มสุดท้ายพร้อมกัน (คนละสมาชิก) ──\n";

// หาหนังสือที่เหลือ 1 เล่ม + สมาชิก 2 คนที่ยังไม่เต็มโควตาและไม่ได้ยืมเล่มนี้อยู่
$lastBook = (int) scalar("SELECT id FROM books WHERE available = 1 ORDER BY id DESC LIMIT 1");
$members  = $pdo->query("
    SELECT u.id FROM users u
    WHERE u.role = 'member'
      AND (SELECT COUNT(*) FROM borrows br WHERE br.user_id=u.id AND br.status='borrowing')
        + (SELECT COUNT(*) FROM reservations r WHERE r.user_id=u.id AND r.status='pending') < " . MAX_BORROW_BOOKS . "
    ORDER BY u.id DESC LIMIT 2
")->fetchAll(PDO::FETCH_COLUMN);

if (!$lastBook || count($members) < 2) {
    fail('CC-04', 'ไม่มีหนังสือเหลือ 1 เล่ม หรือสมาชิกว่างไม่พอ 2 คน');
} else {
    [$tA, $tB] = tokensFor('/admin/borrow_form.php');

    $bodies = fireTogether([
        [$jarA, "$BASE_URL/admin/borrow_form.php", ['csrf_token' => $tA, 'user_id' => $members[0], 'book_ids[]' => $lastBook, 'borrow_days' => 7]],
        [$jarB, "$BASE_URL/admin/borrow_form.php", ['csrf_token' => $tB, 'user_id' => $members[1], 'book_ids[]' => $lastBook, 'borrow_days' => 7]],
    ]);

    $created  = (int) scalar("SELECT COUNT(*) FROM borrows WHERE book_id=? AND user_id IN (?,?) AND status='borrowing'", [$lastBook, $members[0], $members[1]]);
    $availNow = (int) scalar("SELECT available FROM books WHERE id=?", [$lastBook]);

    $created === 1
        ? pass('CC-04a', 'สำเร็จเพียงรายการเดียว อีกคนถูกปฏิเสธ')
        : fail('CC-04a', "สร้างรายการยืม $created รายการ — ควรได้ 1");

    $availNow === 0
        ? pass('CC-04b', "available = 0 (ไม่ติดลบ)")
        : fail('CC-04b', "available = $availNow — ควรเป็น 0");

    // 🧠 ดึงเฉพาะข้อความในกล่อง error จริง (div.bg-red-50 > ul > li)
    //    ⚠️ ห้าม match <li> ทั้งหน้า — หน้าฟอร์มมีข้อความคำแนะนำ "ยืมได้สูงสุด N เล่มต่อคน"
    //       อยู่ใน <li> เหมือนกัน ถ้า match กว้างไปจะกลายเป็น false pass
    $msgs = [];
    foreach ($bodies as $b) {
        if (preg_match('/bg-red-50.*?<ul[^>]*>(.*?)<\/ul>/su', $b, $box)
            && preg_match_all('/<li[^>]*>\s*(.*?)\s*<\/li>/su', $box[1], $m)) {
            foreach ($m[1] as $t) {
                $t = trim(html_entity_decode(strip_tags($t)));
                if ($t !== '') {
                    $msgs[] = $t;
                }
            }
        }
    }
    $msgs = array_values(array_unique($msgs));

    $joined = implode(' / ', $msgs);
    if (!$msgs) {
        fail('CC-04c', 'คนที่พลาดไม่ได้รับข้อความ error ใด ๆ (กล่อง error ว่าง)');
    } elseif (count(array_filter($msgs, fn($t) => str_contains($t, 'ไม่มีเล่มว่าง')))) {
        pass('CC-04c', 'คนที่พลาดได้ข้อความตรงสาเหตุ (ไม่มีเล่มว่าง)');
    } elseif (str_contains($joined, 'SQLSTATE[40001]') || str_contains($joined, 'Deadlock')) {
        // 🔴 [F-20] InnoDB deadlock — ข้อมูลยังถูกต้อง (rollback แล้ว) แต่ผู้ใช้เห็น error ดิบของ DB
        //    เกิดประมาณ 1 ใน 4 ครั้งของการแย่งเล่มสุดท้าย และไม่มี retry ให้
        fail('CC-04c', 'DEADLOCK รั่วถึงหน้าจอ (ดู FINDINGS F-20) → "' . mb_substr($joined, 0, 90) . '..."');
    } else {
        fail('CC-04c', 'ได้ error แต่ไม่ตรงสาเหตุ (คาดว่า "ไม่มีเล่มว่าง") → "' . $joined . '"');
    }
}

// ============================================================
// ตรวจ invariant รวมหลังยิงทุกเคส
// ============================================================
echo "\n── ตรวจความสมบูรณ์ของข้อมูลหลังทดสอบ ──\n";

$badStock = (int) scalar("SELECT COUNT(*) FROM books b WHERE b.available <> b.quantity
    - (SELECT COUNT(*) FROM borrows br WHERE br.book_id=b.id AND br.status='borrowing')
    - (SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id AND r.status='pending')");
$badStock === 0
    ? pass('CC-05', 'stock ตรงตาม invariant ทุกเล่ม')
    : fail('CC-05', "stock ไม่ตรง $badStock เล่ม");

$negative = (int) scalar("SELECT COUNT(*) FROM books WHERE available < 0 OR available > quantity");
$negative === 0
    ? pass('CC-06', 'ไม่มีเล่มที่ available ติดลบหรือเกิน quantity')
    : fail('CC-06', "$negative เล่มละเมิด CHECK constraint");

$dupPay = (int) scalar("SELECT COUNT(*) FROM (SELECT borrow_id FROM payments GROUP BY borrow_id HAVING COUNT(*) > 1) x");
$dupPay === 0
    ? pass('CC-07', 'ไม่มี borrow ที่มี payment ซ้ำ')
    : fail('CC-07', "$dupPay borrow มี payment ซ้ำ");

@unlink($jarA);
@unlink($jarB);

// ============================================================
// SUMMARY
// ============================================================
$pct = $results['total'] > 0 ? round($results['passed'] / $results['total'] * 100, 1) : 0;
echo "\n══════════════════════════════════════\n";
echo " RESULTS: {$results['passed']}/{$results['total']} passed ($pct%)";
if ($results['failed'] > 0) {
    echo " | {$results['failed']} FAILED";
}
echo "\n══════════════════════════════════════\n";
echo " หมายเหตุ: ชุดนี้เปลี่ยนสถานะข้อมูลจริง (อนุมัติ/คืน/ชำระ/ยืม)\n";
echo "          รัน `php tests/fixtures/seed_test_data.php` เพื่อคืนสภาพก่อนทดสอบรอบถัดไป\n\n";

exit($results['failed'] > 0 ? 1 : 0);
