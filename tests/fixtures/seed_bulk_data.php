<?php

/**
 * L3 Bulk Data Seeder — ข้อมูลปริมาณมากสำหรับวัดประสิทธิภาพ
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * สร้างหนังสือ/สมาชิก/การยืมจำนวนมาก เพื่อตอบคำถามที่เอกสารเดิมตอบแบบคาดเดาไว้ว่า
 * "ระบบนี้รับได้กี่เล่ม" — จะได้วัดเป็นตัวเลขจริงแทนการเดา
 *
 * ต่างจากชั้นอื่น:
 *   L0 database/sample_data.sql        → ห้องสมุดปกติ ไว้เดโม
 *   L1 tests/fixtures/seed_test_data.php → สภาพที่ขอบของกฎธุรกิจ ไว้ทดสอบ flow
 *   L3 ไฟล์นี้                          → ปริมาณล้วน ไม่สนใจความสมจริงของเนื้อหา
 *
 * 📌 การใช้งาน (CLI เท่านั้น):
 *   php tests/fixtures/seed_bulk_data.php --books=500 --members=200
 *   php tests/fixtures/seed_bulk_data.php --reset      ล้างข้อมูล bulk ทั้งหมด
 *
 * 🔑 ทุกแถวกำกับด้วย "[BULK]" และอีเมล bulk_*@test.local → --reset ลบเฉพาะของตัวเอง
 * ⚠️ ห้ามรันบน production
 *
 * 🧠 ใช้ multi-row INSERT เป็นชุด ไม่ยิงทีละแถว — สร้าง 2,000 เล่มจบในไม่กี่วินาที
 *    และคำนวณ stock จาก borrows จริงท้ายสุด เหมือน seed ชั้นอื่น
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$_SESSION = [];
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF']       = 'tests/fixtures/seed_bulk_data.php';

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

const B_TAG  = '[BULK] ';
const B_MAIL = '@test.local';

$pdo = getDB();

// ── อ่าน argument ──
$opts     = getopt('', ['books::', 'members::', 'reset']);
$doReset  = isset($opts['reset']);
$nBooks   = (int) ($opts['books'] ?? 500);
$nMembers = (int) ($opts['members'] ?? 200);

function say(string $m = ''): void
{
    echo $m . "\n";
}

// ============================================================
// RESET
// ============================================================
/**
 * 🧠 ลบตามลำดับ FK: reservations → borrows → books → users
 *    ลบทั้งแถวของ "สมาชิก bulk" และแถวที่อ้าง "หนังสือ bulk"
 */
function resetBulk(PDO $pdo): array
{
    $userIds = $pdo->query("SELECT id FROM users WHERE email LIKE 'bulk\\_%" . B_MAIL . "'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $bookIds = $pdo->query("SELECT id FROM books WHERE title LIKE '" . B_TAG . "%'")
        ->fetchAll(PDO::FETCH_COLUMN);

    $inU = $userIds ? implode(',', array_map('intval', $userIds)) : '0';
    $inB = $bookIds ? implode(',', array_map('intval', $bookIds)) : '0';

    $pdo->exec("DELETE FROM reservations WHERE user_id IN ($inU) OR book_id IN ($inB)");
    $pdo->exec("DELETE FROM borrows      WHERE user_id IN ($inU) OR book_id IN ($inB)");
    $pdo->exec("DELETE FROM books        WHERE id IN ($inB)");
    $pdo->exec("DELETE FROM users        WHERE id IN ($inU)");
    $pdo->exec("DELETE FROM categories   WHERE name LIKE '" . B_TAG . "%'");

    return ['users' => count($userIds), 'books' => count($bookIds)];
}

say();
say('╔════════════════════════════════════════════════════════════╗');
say('║  L3 Bulk Data Seeder — ข้อมูลปริมาณมากสำหรับวัด perf       ║');
say('╚════════════════════════════════════════════════════════════╝');

$t0 = microtime(true);
$pdo->beginTransaction();
$removed = resetBulk($pdo);
$pdo->commit();
say("🧹 ล้างข้อมูล bulk เดิม: ผู้ใช้ {$removed['users']} คน, หนังสือ {$removed['books']} เล่ม");

if ($doReset) {
    say('✅ เสร็จสิ้น (โหมด --reset)');
    say();
    exit(0);
}

// ============================================================
// สร้างข้อมูล
// ============================================================
say("📚 กำลังสร้าง: หนังสือ {$nBooks} เล่ม, สมาชิก {$nMembers} คน");

$pdo->beginTransaction();

// ── หมวดหมู่ 10 หมวด ──
$catIds = [];
$stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
for ($i = 1; $i <= 10; $i++) {
    $stmt->execute([B_TAG . "หมวด $i"]);
    $catIds[] = (int) $pdo->lastInsertId();
}

// ── หนังสือ (insert ทีละ 500 แถว) ──
// 📝 quantity/available ใส่ค่าชั่วคราวก่อน แล้วคำนวณใหม่ท้ายสคริปต์จาก borrows จริง
$authors = ['สมชาย', 'สมหญิง', 'วิชัย', 'ประเสริฐ', 'มานี', 'ปิติ', 'ชูใจ', 'วีระ'];
$batch   = [];
$made    = 0;
for ($i = 1; $i <= $nBooks; $i++) {
    $batch[] = [
        B_TAG . "หนังสือเล่มที่ $i",
        $authors[$i % count($authors)] . ' ' . B_TAG,
        'B' . str_pad((string) $i, 12, '0', STR_PAD_LEFT),
        $catIds[$i % count($catIds)],
        "หนังสือสำหรับวัดประสิทธิภาพ ลำดับที่ $i",
        ($i % 5) + 1,
    ];
    if (count($batch) >= 500 || $i === $nBooks) {
        $ph  = implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?,?,1)'));
        $sql = "INSERT INTO books (title, author, isbn, category_id, description, quantity, available, is_visible) VALUES $ph";
        $vals = [];
        foreach ($batch as $b) {
            array_push($vals, $b[0], $b[1], $b[2], $b[3], $b[4], $b[5], $b[5]);
        }
        $pdo->prepare($sql)->execute($vals);
        $made += count($batch);
        $batch = [];
    }
}
say("   ✅ หนังสือ $made เล่ม");

// ── สมาชิก ──
$hash  = hashPassword('123456');
$batch = [];
$madeU = 0;
for ($i = 1; $i <= $nMembers; $i++) {
    $batch[] = [B_TAG . "สมาชิก $i", "bulk_$i" . B_MAIL, $hash, '08' . str_pad((string) $i, 8, '0', STR_PAD_LEFT)];
    if (count($batch) >= 500 || $i === $nMembers) {
        $ph  = implode(',', array_fill(0, count($batch), "(?,?,?,?,'member')"));
        $vals = [];
        foreach ($batch as $b) {
            array_push($vals, $b[0], $b[1], $b[2], $b[3]);
        }
        $pdo->prepare("INSERT INTO users (name, email, password, phone, role) VALUES $ph")->execute($vals);
        $madeU += count($batch);
        $batch = [];
    }
}
say("   ✅ สมาชิก $madeU คน");

$bookIds = $pdo->query("SELECT id FROM books WHERE title LIKE '" . B_TAG . "%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$userIds = $pdo->query("SELECT id FROM users WHERE email LIKE 'bulk\\_%" . B_MAIL . "' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

// ── การยืม: กระจายย้อนหลัง 12 เดือน ──
// 🧠 ให้สมาชิกแต่ละคนมีประวัติ ~6 รายการ โดยรายการล่าสุดบางส่วนยังไม่คืน
//    ระวังไม่ให้เกินโควตา: active ต่อคนไม่เกิน MAX_BORROW_BOOKS
$batch    = [];
$madeB    = 0;
$activeOf = [];
foreach ($userIds as $idx => $uid) {
    for ($k = 0; $k < 6; $k++) {
        $bookId = $bookIds[($idx * 7 + $k * 13) % count($bookIds)];
        $daysAgo = 360 - ($k * 55) - ($idx % 30);
        $borrowDate = date('Y-m-d', strtotime("-$daysAgo days"));
        $dueDate    = date('Y-m-d', strtotime("-" . ($daysAgo - 14) . " days"));

        // รายการล่าสุด (k = 0) ของบางคน ปล่อยค้างไว้ ไม่เกินโควตา
        $keepActive = ($k === 0 && ($idx % 3 === 0) && (($activeOf[$uid] ?? 0) < MAX_BORROW_BOOKS));
        if ($keepActive) {
            $activeOf[$uid] = ($activeOf[$uid] ?? 0) + 1;
            $batch[] = [$uid, $bookId, date('Y-m-d', strtotime('-5 days')), date('Y-m-d', strtotime('+9 days')), null, 'borrowing', 0];
        } else {
            $returnDate = date('Y-m-d', strtotime("-" . max(0, $daysAgo - 20) . " days"));
            $late = (int) ((strtotime($returnDate) - strtotime($dueDate)) / 86400);
            $batch[] = [$uid, $bookId, $borrowDate, $dueDate, $returnDate, 'returned', max(0, $late) * FINE_PER_DAY];
        }

        if (count($batch) >= 500) {
            $ph = implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?,?)'));
            $vals = [];
            foreach ($batch as $b) {
                array_push($vals, ...$b);
            }
            $pdo->prepare("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, return_date, status, fine_amount) VALUES $ph")->execute($vals);
            $madeB += count($batch);
            $batch = [];
        }
    }
}
if ($batch) {
    $ph = implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?,?)'));
    $vals = [];
    foreach ($batch as $b) {
        array_push($vals, ...$b);
    }
    $pdo->prepare("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, return_date, status, fine_amount) VALUES $ph")->execute($vals);
    $madeB += count($batch);
}
say("   ✅ การยืม $madeB รายการ (กระจาย 12 เดือน)");

// ── ชำระค่าปรับบางส่วน (ครึ่งหนึ่งของรายการที่มีค่าปรับ) ──
$pdo->exec("INSERT INTO payments (borrow_id, amount, recorded_by)
            SELECT b.id, b.fine_amount, NULL FROM borrows b
            LEFT JOIN payments p ON p.borrow_id = b.id
            JOIN books bk ON bk.id = b.book_id
            WHERE bk.title LIKE '" . B_TAG . "%' AND b.fine_amount > 0 AND p.id IS NULL AND b.id % 2 = 0");
$madeP = (int) $pdo->query("SELECT COUNT(*) FROM payments p JOIN borrows b ON b.id=p.borrow_id JOIN books bk ON bk.id=b.book_id WHERE bk.title LIKE '" . B_TAG . "%'")->fetchColumn();
say("   ✅ การชำระค่าปรับ $madeP รายการ");

// ── คำนวณ stock ใหม่จากข้อมูลจริง ──
// 🧠 หลักการเดียวกับ seed ชั้นอื่น — invariant ต้องถูกเสมอ ไม่ hard-code
$pdo->exec("UPDATE books b
            SET b.available = GREATEST(0, b.quantity
                - (SELECT COUNT(*) FROM borrows br WHERE br.book_id = b.id AND br.status = 'borrowing')
                - (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.id AND r.status = 'pending'))
            WHERE b.title LIKE '" . B_TAG . "%'");

$pdo->commit();

// ============================================================
// สรุป
// ============================================================
$elapsed = round(microtime(true) - $t0, 1);
say();
say("✅ เสร็จใน {$elapsed} วินาที");

$row = $pdo->query("SELECT (SELECT COUNT(*) FROM books) b, (SELECT COUNT(*) FROM users) u,
                           (SELECT COUNT(*) FROM borrows) br, (SELECT COUNT(*) FROM payments) p")->fetch();
say("   ขนาดข้อมูลรวมทั้งระบบ: หนังสือ {$row['b']} · สมาชิก {$row['u']} · การยืม {$row['br']} · การชำระ {$row['p']}");

$bad = (int) $pdo->query("SELECT COUNT(*) FROM books b WHERE b.available <> b.quantity
    - (SELECT COUNT(*) FROM borrows br WHERE br.book_id=b.id AND br.status='borrowing')
    - (SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id AND r.status='pending')")->fetchColumn();
say($bad === 0 ? '   ✅ stock invariant ถูกต้องทุกเล่ม' : "   ❌ stock ไม่ตรง $bad เล่ม");
say();
