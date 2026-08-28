<?php

/**
 * ทดสอบ "จองรอคิวเมื่อหนังสือถูกยืมหมด" (ROADMAP ข้อ 5)
 *
 * ==========================================================================
 * 🔴 ข้อนี้แตะ invariant สต็อกโดยตรง — เทสต์ทุกกลุ่มจบด้วยการตรวจ invariant
 * ==========================================================================
 * A. เข้าคิว — เข้าได้เฉพาะตอนถูกยืมหมด · **available ต้องไม่ขยับ** · กันเข้าซ้ำ
 * B. เลื่อนคิวตอนคืน — คนแรกได้ก่อน · available ยังเป็น 0 (กันเล่มไว้ให้)
 *                      · คนนอกคิวยืมแซงไม่ได้
 * C. โควตา — คิวรอไม่กินโควตายืม แต่จำกัดจำนวนคิวต่อคน
 * D. หมดอายุ/ยกเลิก — คนแรกไม่มารับ → เลื่อนคนที่ 2 · ยกเลิกคิวเองได้
 * E. ตัวเลขข้ามหน้า — ทุกที่ที่นับ "การจอง" ต้องตอบให้ตรงความหมายของตัวเอง
 * F. Race — คืนหนังสือพร้อมกับคนนอกคิวยิงยืม → คนที่รอคิวต้องได้
 * G. หน้าเว็บจริงผ่าน HTTP
 *
 * 🧹 ลบทุกอย่างที่สร้างขึ้นเมื่อจบ — อยู่ใน register_shutdown_function
 *    และ rollback ทรานแซกชันที่ค้างก่อนลบ (ไม่งั้น DELETE โดน rollback ไปด้วย)
 *
 * 📌 การใช้งาน: php tests/test_reservation_queue.php [รหัสผ่าน admin]
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
require_once __DIR__ . '/../app/Services/BookService.php';

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

$pdo         = getDB();
$borrowSvc   = new App\Services\BorrowService($pdo);
$resSvc      = new App\Services\ReservationService($pdo);
$bookRepo    = new App\Repositories\BookRepository($pdo);
$borrowRepo  = new App\Repositories\BorrowRepository($pdo);
$resRepo     = new App\Repositories\ReservationRepository($pdo);

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  จองรอคิวเมื่อหนังสือถูกยืมหมด (ROADMAP ข้อ 5)            ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// ============================================================
// FIXTURE
// ============================================================
$created = ['books' => [], 'users' => []];
$COOKIE  = tempnam(sys_get_temp_dir(), 'bbqueue');

$cleanupDone = false;
$cleanup = function () use (&$created, &$cleanupDone, $pdo, $COOKIE) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    try {
        // 🔴 rollback ก่อน ไม่งั้น DELETE ถูก rollback ไปด้วยเมื่อเทสต์ตายกลางทรานแซกชัน
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
            echo "  ↩️  rollback transaction ที่ค้างอยู่ก่อนล้างข้อมูล\n";
        }
        if ($created['books']) {
            $in = implode(',', array_map('intval', $created['books']));
            $pdo->exec("DELETE FROM reservations WHERE book_id IN ($in)");
            $pdo->exec("DELETE FROM payments WHERE borrow_id IN (SELECT id FROM borrows WHERE book_id IN ($in))");
            $pdo->exec("DELETE FROM borrows WHERE book_id IN ($in)");
            $pdo->exec("DELETE FROM books WHERE id IN ($in)");
        }
        if ($created['users']) {
            $in = implode(',', array_map('intval', $created['users']));
            $pdo->exec("DELETE FROM reservations WHERE user_id IN ($in)");
            $pdo->exec("DELETE FROM payments WHERE borrow_id IN (SELECT id FROM borrows WHERE user_id IN ($in))");
            $pdo->exec("DELETE FROM borrows WHERE user_id IN ($in)");
            $pdo->exec("DELETE FROM users WHERE id IN ($in)");
        }
        echo "  ลบหนังสือ/สมาชิก/การจอง/รายการยืมที่สร้างขึ้นทั้งหมด\n";
    } catch (Throwable $e) {
        echo "  ⚠️ ล้างข้อมูลไม่ครบ: " . $e->getMessage() . "\n";
    }
    @unlink($COOKIE);
};
register_shutdown_function($cleanup);

$catId = (int) $pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn();

$mkBook = function (string $title, int $qty) use ($bookRepo, $catId, &$created): int {
    $id = $bookRepo->create([
        'title' => $title, 'author' => 'ผู้แต่งทดสอบ',
        'category_id' => $catId, 'quantity' => $qty,
    ]);
    $created['books'][] = $id;
    return $id;
};

$mkUser = function (string $suffix) use ($pdo, &$created): int {
    $email = "queuetest_{$suffix}_" . time() . rand(100, 999) . "@test.com";
    $st = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'member')");
    $st->execute(["ผู้ใช้คิว {$suffix}", $email, password_hash('Test12345', PASSWORD_DEFAULT)]);
    $id = (int) $pdo->lastInsertId();
    $created['users'][] = $id;
    return $id;
};

/** สร้างรายการยืม + หัก available ให้ตรง invariant */
$mkBorrow = function (int $userId, int $bookId) use ($pdo): int {
    $st = $pdo->prepare("
        INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status)
        VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'borrowing')
    ");
    $st->execute([$userId, $bookId]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?")->execute([$bookId]);
    return $id;
};

/** invariant ทั้งระบบ — waiting ต้องไม่ถูกนับ */
$brokenBooks = function () use ($pdo): int {
    return (int) $pdo->query("
        SELECT COUNT(*) FROM books b
        WHERE b.available <> b.quantity
            - (SELECT COUNT(*) FROM borrows x WHERE x.book_id = b.id AND x.status = 'borrowing')
            - (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.id AND r.status = 'pending')
    ")->fetchColumn();
};

$avail = fn(int $id) => (int) $pdo->query("SELECT available FROM books WHERE id = $id")->fetchColumn();
$stat  = fn(int $id) => (string) $pdo->query("SELECT status FROM reservations WHERE id = $id")->fetchColumn();

$holder = $mkUser('holder');   // คนที่ถือหนังสืออยู่
$u1     = $mkUser('one');
$u2     = $mkUser('two');
$u3     = $mkUser('three');
$outsider = $mkUser('outsider');

$bookSingle = $mkBook('[QTEST] เล่มเดียวในระบบ', 1);
$bookFree   = $mkBook('[QTEST] เล่มที่ยังว่างอยู่', 2);
$bookQuota  = $mkBook('[QTEST] เล่มทดสอบโควตา', 1);
$bookRace   = $mkBook('[QTEST] เล่มทดสอบแข่งกัน', 1);
$bookExpire = $mkBook('[QTEST] เล่มทดสอบหมดอายุ', 1);

$bSingle = $mkBorrow($holder, $bookSingle);
$bExpire = $mkBorrow($holder, $bookExpire);

echo "  📦 fixture: หนังสือ 5 เล่ม · สมาชิก 5 คน\n\n";

// ============================================================
// A. เข้าคิว
// ============================================================
echo "── A. เข้าคิว ──\n";

// A1 — เล่มที่ยังว่างอยู่ ต้องจองตรง ๆ ไม่ใช่ต่อคิว
try {
    $resSvc->joinQueue($u1, $bookFree);
    fail('QUEUE-A1', 'เข้าคิวเล่มที่ยังว่างอยู่ได้ ทั้งที่ควรให้จองตรง ๆ');
} catch (Exception $e) {
    check('QUEUE-A1', str_contains($e->getMessage(), 'มีให้ยืมแล้ว'),
        'เล่มที่ยังว่าง → ถูกบอกให้จองตามปกติ: ' . $e->getMessage(),
        'ถูกปฏิเสธแต่ข้อความไม่ตรง: ' . $e->getMessage());
}

// A2 — 🔴 เข้าคิวเล่มที่ถูกยืมหมด → available ต้องไม่ขยับ
$availBefore = $avail($bookSingle);
$r1 = $resSvc->joinQueue($u1, $bookSingle);
$availAfter = $avail($bookSingle);
check('QUEUE-A2',
    $availBefore === 0 && $availAfter === 0,
    "เข้าคิวสำเร็จ · available คงที่ {$availAfter} — คิวรอไม่กินสต็อก",
    "🔴 available เปลี่ยน {$availBefore} → {$availAfter} — คิวรอไม่ควรแตะสต็อกเลย");

check('QUEUE-A3', (int) $r1['position'] === 1,
    'ได้คิวที่ ' . $r1['position'],
    'ลำดับคิวผิด: ได้ ' . $r1['position'] . ' ควรเป็น 1');

$resId1 = (int) $r1['reservation_id'];
check('QUEUE-A4',
    $stat($resId1) === 'waiting'
        && $pdo->query("SELECT expires_at FROM reservations WHERE id = $resId1")->fetchColumn() === null,
    'status = waiting และ expires_at เป็น NULL — คิวไม่มีวันหมดอายุ',
    'สถานะหรือวันหมดอายุผิด');

// A5 — เข้าคิวซ้ำเล่มเดิมไม่ได้
try {
    $resSvc->joinQueue($u1, $bookSingle);
    fail('QUEUE-A5', 'เข้าคิวซ้ำเล่มเดิมได้');
} catch (Exception $e) {
    pass('QUEUE-A5', 'เข้าคิวซ้ำถูกปฏิเสธ: ' . $e->getMessage());
}

// A6 — คิวเรียงตามลำดับเวลา
$r2 = $resSvc->joinQueue($u2, $bookSingle);
$r3 = $resSvc->joinQueue($u3, $bookSingle);
check('QUEUE-A6',
    (int) $r2['position'] === 2 && (int) $r3['position'] === 3 && $avail($bookSingle) === 0,
    "คิว 3 คนเรียงถูก (1/2/3) · available ยังเป็น 0",
    "ลำดับคิวผิด: {$r2['position']}, {$r3['position']} · available=" . $avail($bookSingle));

check('QUEUE-A7', $brokenBooks() === 0,
    'invariant ถูกต้องทุกเล่มหลังมีคิว 3 คน',
    '🔴 invariant พัง ' . $brokenBooks() . ' เล่ม');

// A8 — คนที่ยืมเล่มนั้นอยู่ ต่อคิวไม่ได้
try {
    $resSvc->joinQueue($holder, $bookSingle);
    fail('QUEUE-A8', 'คนที่ถือหนังสืออยู่ต่อคิวเล่มเดิมได้');
} catch (Exception $e) {
    pass('QUEUE-A8', 'คนที่ยืมอยู่ต่อคิวไม่ได้: ' . $e->getMessage());
}

// ============================================================
// B. เลื่อนคิวตอนคืนหนังสือ
// ============================================================
echo "\n── B. เลื่อนคิวตอนคืนหนังสือ ──\n";

$ret = $borrowSvc->returnBook($bSingle, false, 1);

check('QUEUE-B1',
    $ret['promoted'] !== null && (int) $ret['promoted']['user_id'] === $u1,
    'คืนหนังสือแล้วคิวที่ 1 ถูกเลื่อนอัตโนมัติ',
    'ไม่ได้เลื่อนคิว หรือเลื่อนผิดคน: ' . json_encode($ret['promoted'] ?? null));

check('QUEUE-B2', $stat($resId1) === 'pending',
    'คิวที่ 1 กลายเป็น pending (ของพร้อม รอมารับ)',
    'สถานะผิด: ' . $stat($resId1));

// B3 — 🔴 available ต้องยังเป็น 0 เพราะเล่มถูกกันไว้ให้คนในคิว
check('QUEUE-B3', $avail($bookSingle) === 0,
    'available ยังเป็น 0 — เล่มถูกกันไว้ให้คนในคิว ไม่ได้ขึ้นชั้นให้ใครก็ได้',
    '🔴 available = ' . $avail($bookSingle) . ' — เล่มหลุดขึ้นชั้น คนนอกคิวจะยืมแซงได้');

// B4 — คนนอกคิวยืมเล่มนั้นทันทีไม่ได้
try {
    $borrowSvc->createBorrow($outsider, [$bookSingle]);
    fail('QUEUE-B4', '🔴 คนนอกคิวยืมแซงคนที่รอคิวได้');
} catch (Exception $e) {
    pass('QUEUE-B4', 'คนนอกคิวยืมแซงไม่ได้: ' . $e->getMessage());
}

// B5 — อีก 2 คนยังอยู่ในคิว
check('QUEUE-B5',
    $stat((int) $r2['reservation_id']) === 'waiting'
        && $stat((int) $r3['reservation_id']) === 'waiting',
    'คืน 1 เล่ม เลื่อนคนเดียว — อีก 2 คนยังอยู่ในคิว',
    'เลื่อนเกิน 1 คน: ' . $stat((int) $r2['reservation_id']) . ' / ' . $stat((int) $r3['reservation_id']));

check('QUEUE-B6', $brokenBooks() === 0,
    'invariant ถูกต้องหลังเลื่อนคิว',
    '🔴 invariant พัง ' . $brokenBooks() . ' เล่ม');

// B7 — ข้อความบอกเจ้าหน้าที่ว่าเล่มถูกกันไว้
check('QUEUE-B7', str_contains($ret['message'], 'ต่อคิว'),
    'ข้อความตอนคืนบอกว่ามีคนต่อคิวและกันเล่มไว้แล้ว',
    'ข้อความไม่บอกเรื่องคิว: ' . $ret['message']);

// ============================================================
// C. โควตา
// ============================================================
echo "\n── C. โควตา ──\n";

// C1 — 🔴 คิวรอต้องไม่กินโควตายืม
//    ⚠️ ต้องดันโควตาให้เกือบเต็มก่อน ไม่งั้นเคสนี้ผ่านแม้โค้ดจะนับคิวเข้าโควตา
//       (u2 ต่อคิว 1 + ยืม 0 = 1 ซึ่งยังห่างจากเพดาน 3 อยู่มาก)
//    สภาพที่ต้องการ: ยืมไปแล้ว MAX-1 เล่ม + ต่อคิวอยู่ 1 เล่ม → ต้องยืมเล่มสุดท้ายได้
$fillerBooks = [];
for ($i = 0; $i < MAX_BORROW_BOOKS - 1; $i++) {
    $fb = $mkBook("[QTEST] เล่มดันโควตา {$i}", 1);
    $fillerBooks[] = $fb;
    $borrowSvc->createBorrow($u2, [$fb]);
}
$u2Borrows = $borrowRepo->countActiveBorrows($u2);
$u2Waiting = $resRepo->countWaitingByUser($u2);

try {
    $borrowSvc->createBorrow($u2, [$bookFree]);
    check('QUEUE-C1',
        $u2Borrows === MAX_BORROW_BOOKS - 1 && $u2Waiting >= 1,
        "ยืมอยู่ {$u2Borrows} + ต่อคิว {$u2Waiting} → ยังยืมเล่มที่ " . MAX_BORROW_BOOKS . " ได้ — คิวไม่กินโควตา",
        "สภาพทดสอบไม่ตรง (ยืม {$u2Borrows} คิว {$u2Waiting}) เคสนี้จึงพิสูจน์อะไรไม่ได้");
} catch (Exception $e) {
    fail('QUEUE-C1', "🔴 คิวรอไปกินโควตายืม — ยืมอยู่ {$u2Borrows} ต่อคิว {$u2Waiting} แล้วยืมเพิ่มไม่ได้: " . $e->getMessage());
}

// C2 — จำกัดจำนวนคิวต่อคน = MAX_BORROW_BOOKS
$extraBooks = [];
$joined = 0;
for ($i = 0; $i < MAX_BORROW_BOOKS + 2; $i++) {
    $b = $mkBook("[QTEST] เล่มจำกัดคิว {$i}", 1);
    $extraBooks[] = $b;
    $mkBorrow($holder, $b);   // ทำให้ถูกยืมหมด
    try { $resSvc->joinQueue($outsider, $b); $joined++; } catch (Exception $e) { /* ครบโควตาคิว */ }
}
check('QUEUE-C2', $joined === MAX_BORROW_BOOKS,
    "ต่อคิวได้สูงสุด {$joined} เล่ม (= MAX_BORROW_BOOKS) แล้วถูกปฏิเสธ",
    "จำนวนคิวต่อคนผิด: ต่อได้ {$joined} ควรได้ " . MAX_BORROW_BOOKS);

check('QUEUE-C3', $brokenBooks() === 0,
    'invariant ถูกต้องหลังต่อคิวหลายเล่ม',
    '🔴 invariant พัง ' . $brokenBooks() . ' เล่ม');

// ============================================================
// D. หมดอายุ / ยกเลิก
// ============================================================
echo "\n── D. หมดอายุและยกเลิก ──\n";

// D1 — ยกเลิกคิวของตัวเองได้ (คิวไม่มีวันหมดอายุ ต้องออกเองได้)
$availBeforeCancel = $avail($bookSingle);
$cancelRes = $resSvc->cancelReservation((int) $r3['reservation_id'], $u3);
check('QUEUE-D1',
    $stat((int) $r3['reservation_id']) === 'cancelled'
        && $avail($bookSingle) === $availBeforeCancel,
    'ออกจากคิวได้ · available ไม่ขยับ (คิวไม่เคยกินสต็อก จึงไม่มีอะไรให้คืน)',
    '🔴 ยกเลิกคิวแล้ว available เปลี่ยน ' . $availBeforeCancel . ' → ' . $avail($bookSingle)
        . ' — หนังสืองอกขึ้นมาจากอากาศ');

check('QUEUE-D2', ($cancelRes['was_waiting'] ?? false) === true,
    'ระบบรู้ว่าเป็นการออกจากคิว ไม่ใช่ยกเลิกการจอง',
    'was_waiting ผิด');

// D3 — 🔴 คนแรกไม่มารับจนหมดอายุ → เลื่อนคนถัดไปอัตโนมัติ
//    (bookExpire: holder ยืมอยู่ → u1 ต่อคิว → คืน → u1 ได้ pending → ปล่อยหมดอายุ → u2 ต้องได้ต่อ)
$e1 = $resSvc->joinQueue($u1, $bookExpire);
$e2 = $resSvc->joinQueue($u3, $bookExpire);
$borrowSvc->returnBook($bExpire, false, 1);

$e1Id = (int) $e1['reservation_id'];
$e2Id = (int) $e2['reservation_id'];
check('QUEUE-D3',
    $stat($e1Id) === 'pending' && $stat($e2Id) === 'waiting',
    'คืนแล้วคนแรกได้ของ คนที่สองยังรอ',
    'สถานะผิด: ' . $stat($e1Id) . ' / ' . $stat($e2Id));

// ⏰ ดันให้หมดอายุ แล้วเรียก lazy expire
$pdo->exec("UPDATE reservations SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = $e1Id");
// 🧠 ต้องรีเซ็ต flag กัน expire ซ้ำใน request เดียว ไม่งั้น lazy expire จะข้าม
$fresh = new App\Repositories\ReservationRepository($pdo);
$fresh->markExpiredReservations();

check('QUEUE-D4',
    $stat($e1Id) === 'expired' && $stat($e2Id) === 'pending',
    '🔄 คนแรกไม่มารับ → หมดอายุ และคนที่ 2 ถูกเลื่อนขึ้นมาอัตโนมัติ',
    '🔴 คิวไม่ขยับหลังหมดอายุ: ' . $stat($e1Id) . ' / ' . $stat($e2Id)
        . ' — คนที่รอต่อจากคนที่ไม่มารับจะค้างตลอดกาล');

check('QUEUE-D5', $avail($bookExpire) === 0,
    'available ยังเป็น 0 — เล่มถูกกันต่อให้คิวถัดไป ไม่ได้หลุดขึ้นชั้น',
    '🔴 available = ' . $avail($bookExpire) . ' — เล่มหลุดตอนหมดอายุ');

check('QUEUE-D6', $brokenBooks() === 0,
    'invariant ถูกต้องหลังหมดอายุ + เลื่อนคิว',
    '🔴 invariant พัง ' . $brokenBooks() . ' เล่ม');

// D7 — ยกเลิกการจองที่กันเล่มไว้ (pending) → ต้องส่งต่อให้คิวถัดไป ไม่ใช่ขึ้นชั้น
$q1 = $resSvc->joinQueue($u2, $bookExpire);   // u3 ถือ pending อยู่ → u2 ต่อคิว
$q1Id = (int) $q1['reservation_id'];
$resSvc->cancelReservation($e2Id, $u3);       // u3 ยกเลิกการจองที่กันไว้

check('QUEUE-D7',
    $stat($q1Id) === 'pending' && $avail($bookExpire) === 0,
    'ยกเลิกการจองที่กันเล่มไว้ → เล่มตกไปที่คิวถัดไปทันที ไม่ขึ้นชั้น',
    '🔴 สถานะคิวถัดไป=' . $stat($q1Id) . ' available=' . $avail($bookExpire)
        . ' — เล่มควรถูกส่งต่อให้คนที่รอ');

check('QUEUE-D8', $brokenBooks() === 0,
    'invariant ถูกต้องหลังยกเลิกและส่งต่อคิว',
    '🔴 invariant พัง ' . $brokenBooks() . ' เล่ม');

// ============================================================
// E. ตัวเลขข้ามหน้า
// ============================================================
echo "\n── E. ความหมายของตัวเลขต้องไม่ปนกัน ──\n";

// E1 — สูตรสต็อกต้องไม่นับ waiting
$waitingTotal = (int) $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'waiting'")->fetchColumn();
check('QUEUE-E1', $waitingTotal > 0 && $brokenBooks() === 0,
    "มีคิวรออยู่ {$waitingTotal} รายการในระบบ แต่ invariant ยังถูกต้องทุกเล่ม",
    'ไม่มีคิวรอให้ทดสอบ หรือ invariant พัง');

// E2 — 🔴 ห้ามลบหนังสือที่มีคนต่อคิวรอ
//    ⚠️ ต้องใช้เล่มที่ **ไม่มีประวัติการยืมเลย** ไม่งั้นจะถูกบล็อกด้วยด่าน
//       "มีประวัติการยืม" แทน แล้วเคสนี้จะผ่านโดยไม่ได้พิสูจน์ด่านคิวเลย
$bookSvc = new App\Services\BookService($pdo);
$bookCleanDel = $mkBook('[QTEST] เล่มไม่มีประวัติยืม', 1);
// 📝 ใส่คิวรอตรง ๆ — เล่มนี้ยังว่างอยู่ joinQueue() จึงปฏิเสธ (ถูกต้องแล้ว)
//    แต่เราต้องการสภาพ "มีคิวค้าง + ไม่มีประวัติยืม" เพื่อทดสอบด่านนี้จุดเดียว
$pdo->prepare("
    INSERT INTO reservations (user_id, book_id, status, queued_at, expires_at)
    VALUES (?, ?, 'waiting', NOW(), NULL)
")->execute([$u1, $bookCleanDel]);

$historyRows = (int) $pdo->query("SELECT COUNT(*) FROM borrows WHERE book_id = $bookCleanDel")->fetchColumn();
try {
    $bookSvc->deleteBook($bookCleanDel);
    fail('QUEUE-E2', '🔴 ลบหนังสือที่มีคนต่อคิวรออยู่ได้ — คนที่รอจะหายไปพร้อมหนังสือ');
} catch (Exception $e) {
    check('QUEUE-E2',
        $historyRows === 0 && str_contains($e->getMessage(), 'จอง'),
        'ลบหนังสือที่มีคนต่อคิวรอไม่ได้ (เล่มนี้ไม่มีประวัติยืมเลย → ด่านคิวเป็นตัวกัน): '
            . mb_substr($e->getMessage(), 0, 45),
        'ถูกบล็อกด้วยเหตุผลอื่น ไม่ใช่ด่านคิว (ประวัติยืม ' . $historyRows . ' แถว): ' . $e->getMessage());
}

// E3 — ห้ามต่ออายุการยืมถ้ามีคนต่อคิวรอ
$bookRenew = $mkBook('[QTEST] เล่มทดสอบต่ออายุ', 1);
$bRenew    = $mkBorrow($holder, $bookRenew);
$resSvc->joinQueue($u1, $bookRenew);
try {
    $borrowSvc->renewBorrow($bRenew);
    fail('QUEUE-E3', '🔴 ต่ออายุได้ทั้งที่มีคนต่อคิวรอ — คนรอจะไม่มีวันได้หนังสือ');
} catch (Exception $e) {
    check('QUEUE-E3', str_contains($e->getMessage(), 'จอง'),
        'ต่ออายุไม่ได้เพราะมีคนต่อคิวรอ: ' . $e->getMessage(),
        'ถูกปฏิเสธแต่ด้วยเหตุผลอื่น: ' . $e->getMessage());
}

// E4 — การ์ด "รอรับของ" ต้องไม่นับคิวรอ
$dashPending = $resRepo->countPending();
$sqlPending  = (int) $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn();
check('QUEUE-E4', $dashPending === $sqlPending,
    "การ์ด \"รอรับของ\" นับ {$dashPending} = จำนวน pending จริง (ไม่ปนคิวรอ)",
    "🔴 ตัวเลขไม่ตรง: countPending()={$dashPending} pending จริง={$sqlPending}");

// ============================================================
// F. Race — คืนหนังสือพร้อมกับคนนอกคิวยิงยืม
// ============================================================
echo "\n── F. คืนหนังสือพร้อมกับคนนอกคิวยิงยืม ──\n";

$bRace = $mkBorrow($holder, $bookRace);
$raceRes = $resSvc->joinQueue($u1, $bookRace);
$raceResId = (int) $raceRes['reservation_id'];

$rootDir = str_replace('\\', '/', dirname(__DIR__));
$probe = <<<SUB
<?php
\$_SERVER["REQUEST_METHOD"]="GET"; \$_SERVER["PHP_SELF"]="sub.php"; \$_SERVER["REMOTE_ADDR"]="127.0.0.1";
define('PROBE_ROOT', '{$rootDir}');
require PROBE_ROOT . "/includes/config.php";
require PROBE_ROOT . "/includes/db.php";
require PROBE_ROOT . "/includes/functions.php";
require PROBE_ROOT . "/app/Services/BorrowService.php";
\$pdo = getDB();
\$svc = new App\Services\BorrowService(\$pdo);
\$mode = \$argv[1]; \$id = (int) \$argv[2]; \$startAt = (float) \$argv[3];
while (microtime(true) < \$startAt) usleep(500);
try {
    if (\$mode === 'return') { \$svc->returnBook(\$id, false, 1); echo "RETURNED"; }
    else { \$svc->createBorrow((int) \$argv[4], [\$id]); echo "BORROWED"; }
} catch (Exception \$e) { echo "BLOCKED"; }
SUB;
$probeFile = tempnam(sys_get_temp_dir(), 'bbqrace') . '.php';
file_put_contents($probeFile, $probe);

$startAt = microtime(true) + 1.5;
$php = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeFile);
$hReturn = popen("$php return $bRace $startAt 2>&1", 'r');
$hBorrow = popen("$php borrow $bookRace $startAt $outsider 2>&1", 'r');
$outReturn = trim((string) stream_get_contents($hReturn)); pclose($hReturn);
$outBorrow = trim((string) stream_get_contents($hBorrow)); pclose($hBorrow);
@unlink($probeFile);
@unlink(substr($probeFile, 0, -4));

$raceStatus  = $stat($raceResId);
$outsiderGot = (int) $pdo->query("
    SELECT COUNT(*) FROM borrows WHERE user_id = $outsider AND book_id = $bookRace AND status = 'borrowing'
")->fetchColumn();

// 🛡️ probe ต้องรันจนจบจริง ไม่ใช่ตายก่อนยิง — ไม่งั้นเคสนี้ผ่านแบบไม่มีความหมาย
$probeRan = str_contains($outReturn, 'RETURNED')
    && (str_contains($outBorrow, 'BORROWED') || str_contains($outBorrow, 'BLOCKED'));
check('QUEUE-F0', $probeRan,
    'ทั้ง 2 โปรเซสรันจนจบจริง (คืน: ' . mb_substr($outReturn, 0, 20) . ' / ยืม: ' . mb_substr($outBorrow, 0, 20) . ')',
    '🔴 probe ตายก่อนยิง เคส F1 จึงเชื่อไม่ได้ — คืน: ' . mb_substr($outReturn, 0, 80)
        . ' · ยืม: ' . mb_substr($outBorrow, 0, 80));

check('QUEUE-F1',
    $probeRan && str_contains($outBorrow, 'BLOCKED') && $raceStatus === 'pending' && $outsiderGot === 0,
    "คืนพร้อมกับคนนอกคิวยิงยืม → คนนอกคิวถูกปฏิเสธ คนที่รอคิวได้ของ",
    "🔴 คนนอกคิวแย่งไปได้ — คืน: {$outReturn} · ยืม: {$outBorrow} · คิว: {$raceStatus} · outsider ยืมได้ {$outsiderGot} รายการ");

check('QUEUE-F2', $brokenBooks() === 0,
    'invariant ถูกต้องหลังยิงพร้อมกัน',
    '🔴 invariant พัง ' . $brokenBooks() . ' เล่ม');

// ============================================================
// G. หน้าเว็บจริง
// ============================================================
echo "\n── G. หน้าเว็บจริง (HTTP) ──\n";

function http(string $method, string $url, array $fields = []): array
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $COOKIE,
        CURLOPT_COOKIEFILE     => $COOKIE,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'code' => $code];
}

function csrfFrom(string $html): string
{
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

// 🌐 ล็อกอินเป็นสมาชิกทดสอบ (ไม่ใช่ admin — ต้องทดสอบมุมสมาชิกจริง)
$memberEmail = (string) $pdo->query("SELECT email FROM users WHERE id = $u3")->fetchColumn();
$login = http('GET', "$BASE_URL/login.php");
$r = http('POST', "$BASE_URL/login.php", [
    'csrf_token' => csrfFrom($login['body']),
    'email' => $memberEmail, 'password' => 'Test12345',
]);

if (!str_contains($r['body'], 'ออกจากระบบ') && !str_contains($r['body'], 'logout')) {
    fail('QUEUE-G1', 'ล็อกอินสมาชิกทดสอบไม่สำเร็จ — ข้ามการทดสอบผ่านหน้าเว็บ');
} else {
    // G1 — หน้าหนังสือที่ถูกยืมหมดต้องมีปุ่มเข้าคิว
    $bookHttp = $mkBook('[QTEST] เล่มทดสอบหน้าเว็บ', 1);
    $mkBorrow($holder, $bookHttp);
    $page = http('GET', "$BASE_URL/book.php?id={$bookHttp}");
    check('QUEUE-G1',
        str_contains($page['body'], 'เข้าคิวรอ'),
        'หน้าหนังสือที่ถูกยืมหมดมีปุ่ม "เข้าคิวรอ" แล้ว (เดิมขึ้นแค่ "ถูกยืมหมดแล้ว")',
        'ไม่พบปุ่มเข้าคิวในหน้าหนังสือที่ถูกยืมหมด');

    // G2 — กดเข้าคิวผ่าน API จริง
    $api = http('POST', "$BASE_URL/api/reserve_book.php", [
        'book_id' => $bookHttp, 'mode' => 'queue', 'csrf_token' => csrfFrom($page['body']),
    ]);
    $json = json_decode($api['body'], true);
    $httpRes = $pdo->query("SELECT id, status, expires_at FROM reservations WHERE user_id = $u3 AND book_id = $bookHttp")->fetch();
    check('QUEUE-G2',
        ($json['success'] ?? false) && $httpRes && $httpRes['status'] === 'waiting'
            && $httpRes['expires_at'] === null && $avail($bookHttp) === 0,
        'เข้าคิวผ่านหน้าเว็บได้ · status=waiting · available ไม่ขยับ',
        'เข้าคิวผ่านเว็บไม่สำเร็จ: ' . mb_substr($api['body'], 0, 120));

    // G3 — หน้ารายละเอียดต้องบอกลำดับคิว
    $page2 = http('GET', "$BASE_URL/book.php?id={$bookHttp}");
    check('QUEUE-G3',
        str_contains($page2['body'], 'คุณอยู่คิวที่'),
        'หน้าหนังสือบอกว่าอยู่คิวที่เท่าไร',
        'ไม่แสดงลำดับคิวให้สมาชิกเห็น');

    // G4 — หน้า "การจองของฉัน" ต้องมีแท็บและปุ่มออกจากคิว
    $mine = http('GET', "$BASE_URL/my_reservations.php?status=waiting");
    check('QUEUE-G4',
        $mine['code'] === 200 && str_contains($mine['body'], 'ต่อคิวรอ')
            && str_contains($mine['body'], 'ออกจากคิว'),
        'หน้า "การจองของฉัน" มีแท็บต่อคิวรอ + ปุ่มออกจากคิว',
        'หน้าการจองของฉันยังไม่รองรับคิว (HTTP ' . $mine['code'] . ')');

    check('QUEUE-G5', $brokenBooks() === 0,
        'invariant ถูกต้องหลังทดสอบผ่านหน้าเว็บ',
        '🔴 invariant พัง ' . $brokenBooks() . ' เล่ม');
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
