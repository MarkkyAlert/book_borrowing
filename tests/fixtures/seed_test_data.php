<?php

/**
 * L1 Test Data Seeder — ข้อมูลทดสอบชั้น "ขอบ/กรณีพิเศษ"
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * สร้างชุดข้อมูลที่ "จงใจให้อยู่ที่ขอบของกฎธุรกิจ" เพื่อใช้ทดสอบทุก flow
 * เช่น หนังสือเล่มสุดท้าย, หนังสือหมดสต็อก, สมาชิกที่ยืมเต็มโควตา,
 * การจองที่หมดอายุแล้วแต่ยังไม่ถูก expire, ค่าปรับที่ขอบ 0 วัน/1 วัน
 *
 * ต่างจาก database/sample_data.sql (ชั้น L0) ที่เป็นข้อมูล "ห้องสมุดปกติ"
 * สำหรับเดโม — ไฟล์นี้ไม่แตะข้อมูลชุดนั้น และรันร่วมกันได้
 *
 * 🔑 ทุกอย่างที่ไฟล์นี้สร้างจะมีเครื่องหมายกำกับเสมอ:
 *    - หนังสือ/หมวดหมู่/ชื่อผู้ใช้ ขึ้นต้นด้วย "[TEST] "
 *    - อีเมลลงท้ายด้วย "@test.local"
 *    → --reset จึงลบเฉพาะของตัวเองได้ ไม่แตะข้อมูลลูกค้า
 *
 * 📌 การใช้งาน (CLI เท่านั้น):
 *    php tests/fixtures/seed_test_data.php           สร้างข้อมูล (ล้างของเดิมก่อนอัตโนมัติ)
 *    php tests/fixtures/seed_test_data.php --reset   ล้างข้อมูลทดสอบอย่างเดียว
 *    php tests/fixtures/seed_test_data.php --verify  ตรวจข้อมูลปัจจุบันโดยไม่สร้างใหม่
 *
 * ⚠️ ห้ามรันบน production — ไฟล์นี้เขียนข้อมูลจริงลงฐานข้อมูล
 *
 * ⏱️ ข้อควรรู้: สภาพ "การจองหมดอายุแล้วแต่ยัง pending" จะถูกใช้ไปทันทีที่มีคนเปิด
 *    หน้าแรก/หน้ารายการหนังสือ เพราะระบบมี lazy expire (ReservationRepository::markExpiredReservations)
 *    → ถ้าจะทดสอบ flow นั้น ให้รันสคริปต์นี้ใหม่ก่อนทดสอบทุกครั้ง
 *
 * 🧠 หลักการออกแบบที่สำคัญ:
 *    stock (available) ไม่ได้ hard-code — คำนวณท้ายสุดจาก borrows + reservations จริง
 *    โดยกำหนด quantity = (จำนวนที่ถูกยืม/จองจริง) + (จำนวนที่อยากให้เหลือ)
 *    → invariant `available = quantity − ยืมค้าง − จอง pending` ถูกต้องเสมอ
 *      และ "หนังสือหมดสต็อก" จะหมดเพราะมีคนยืมจริง ไม่ใช่เพราะพิมพ์เลข 0 ลงไป
 */

// 🛡️ [SECURITY] CLI เท่านั้น
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// 📝 Fake session/server ให้ bootstrap ทำงานได้บน CLI (แบบเดียวกับ tests/service_test.php)
$_SESSION = ['user_id' => 0, 'role' => 'admin', 'processed_actions' => []];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = 'tests/fixtures/seed_test_data.php';

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// ── เครื่องหมายกำกับข้อมูลทดสอบ ──
const T_TAG        = '[TEST] ';        // ขึ้นต้นชื่อหนังสือ/หมวดหมู่/ชื่อผู้ใช้
const T_MAIL       = '@test.local';    // ลงท้ายอีเมล
const T_PASSWORD   = '123456';         // รหัสผ่านทุกบัญชีทดสอบ (ตรงกับ convention ของโปรเจกต์)
const T_COVER_FILE = 'test_cover.png'; // ไฟล์รูปปกที่สร้างให้ทดสอบ

$pdo      = getDB();
$doReset  = in_array('--reset', $argv, true);
$doVerify = in_array('--verify', $argv, true);

// =====================================================
// Helpers
// =====================================================

/** วันที่แบบ offset จากวันนี้ — dayAt(-5) = 5 วันก่อน, dayAt(3) = อีก 3 วัน */
function dayAt(int $offset): string
{
    return date('Y-m-d', strtotime("{$offset} days"));
}

/** datetime แบบ offset — timeAt('-1 hour'), timeAt('+2 days') */
function timeAt(string $modifier): string
{
    return date('Y-m-d H:i:s', strtotime($modifier));
}

/** ค่าปรับตามสูตรจริงของระบบ: วันเกิน × FINE_PER_DAY (ดู BorrowService::calculateFine) */
function fineFor(string $dueDate, string $returnDate): float
{
    $due = new DateTime($dueDate);
    $ret = new DateTime($returnDate);
    return $ret > $due ? $ret->diff($due)->days * FINE_PER_DAY : 0;
}

function say(string $msg = ''): void
{
    echo $msg . "\n";
}

// =====================================================
// RESET — ลบเฉพาะข้อมูลที่ไฟล์นี้สร้าง
// =====================================================
/**
 * 🧠 ลำดับการลบต้องเคารพ FK: reservations → borrows (payments ตาม CASCADE) → books → categories → users
 *    ลบทั้ง "แถวของผู้ใช้ทดสอบ" และ "แถวที่อ้างถึงหนังสือทดสอบ" เผื่อมีใครทดลองยืมด้วยบัญชีจริง
 */
function resetSeed(PDO $pdo): array
{
    $userIds = $pdo->query("SELECT id FROM users WHERE email LIKE '%" . T_MAIL . "'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $bookIds = $pdo->query("SELECT id FROM books WHERE title LIKE '" . T_TAG . "%'")
        ->fetchAll(PDO::FETCH_COLUMN);

    $inUsers = $userIds ? implode(',', array_map('intval', $userIds)) : '0';
    $inBooks = $bookIds ? implode(',', array_map('intval', $bookIds)) : '0';

    $pdo->exec("DELETE FROM reservations WHERE user_id IN ($inUsers) OR book_id IN ($inBooks)");
    $pdo->exec("DELETE FROM borrows      WHERE user_id IN ($inUsers) OR book_id IN ($inBooks)");
    $pdo->exec("DELETE FROM books        WHERE id IN ($inBooks)");
    $pdo->exec("DELETE FROM categories   WHERE name LIKE '" . T_TAG . "%'");
    $pdo->exec("DELETE FROM password_resets WHERE email LIKE '%" . T_MAIL . "'");
    $pdo->exec("DELETE FROM users        WHERE id IN ($inUsers)");

    // 🧹 ลบไฟล์รูปปกที่สร้างไว้
    $cover = BASE_PATH_SEED . '/uploads/covers/' . T_COVER_FILE;
    if (is_file($cover)) {
        unlink($cover);
    }

    return ['users' => count($userIds), 'books' => count($bookIds)];
}

// 📁 root path (ไฟล์นี้ไม่ผ่าน bootstrap.php จึงไม่มี BASE_PATH)
define('BASE_PATH_SEED', dirname(__DIR__, 2));

// =====================================================
// นิยามข้อมูล
// =====================================================

// ── 1) หมวดหมู่ ──
$CATEGORIES = [
    'c_main'  => T_TAG . 'หมวดที่มีหนังสือ',
    'c_empty' => T_TAG . 'หมวดว่าง (ลบได้)',
];

// ── 2) ผู้ใช้ ──
//    want = สภาพที่ต้องการ (ใช้พิมพ์ในตารางสรุปท้ายสคริปต์)
$USERS = [
    'u_staff2'    => ['name' => T_TAG . 'เจ้าหน้าที่คนที่สอง', 'role' => 'staff',  'want' => 'staff คนที่ 2 — ทดสอบ 2 คนกดพร้อมกัน + staff ยืมได้เอง'],
    'u_clean'     => ['name' => T_TAG . 'สมาชิกไม่มีประวัติ',   'role' => 'member', 'want' => 'ไม่มีประวัติเลย → ต้อง "ลบสมาชิกสำเร็จ"'],
    'u_one'       => ['name' => T_TAG . 'สมาชิกยืม 1 เล่ม',     'role' => 'member', 'want' => 'ยืม 1 เล่ม + มีประวัติคืนก่อนกำหนด → ลบไม่ได้'],
    'u_quota'     => ['name' => T_TAG . 'สมาชิกยืมเต็มโควตา',   'role' => 'member', 'want' => 'ยืมครบ ' . MAX_BORROW_BOOKS . ' เล่ม → ยืม/จองเพิ่มไม่ได้'],
    'u_mixed'     => ['name' => T_TAG . 'สมาชิกยืม+จองเต็ม',    'role' => 'member', 'want' => 'ยืม ' . (MAX_BORROW_BOOKS - 1) . ' + จอง 1 → เต็มโควตาแบบผสม'],
    'u_unpaid'    => ['name' => T_TAG . 'สมาชิกค้างค่าปรับ',    'role' => 'member', 'want' => 'ค่าปรับค้างชำระ 2 รายการ'],
    'u_paid'      => ['name' => T_TAG . 'สมาชิกจ่ายค่าปรับแล้ว', 'role' => 'member', 'want' => 'ค่าปรับชำระแล้ว → ชำระซ้ำต้องไม่ได้'],
    'u_reserve'   => ['name' => T_TAG . 'สมาชิกมีแต่การจอง',    'role' => 'member', 'want' => 'มีแต่ pending reservation → ลบไม่ได้'],
    'u_overdue'   => ['name' => T_TAG . 'สมาชิกเกินกำหนด',      'role' => 'member', 'want' => 'เกินกำหนด 1 / 10 / 60 วัน'],
    'u_due_today' => ['name' => T_TAG . 'สมาชิกครบกำหนดวันนี้',  'role' => 'member', 'want' => 'ครบกำหนดวันนี้พอดี → ค่าปรับต้องเป็น 0'],
    'u_auth'      => ['name' => T_TAG . 'สมาชิกทดสอบล็อกอิน',   'role' => 'member', 'want' => 'มี token รีเซ็ตรหัส 3 แบบ (ใช้ได้/หมดอายุ/ใช้แล้ว)'],
    'u_history'   => ['name' => T_TAG . 'สมาชิกประวัติยาว',     'role' => 'member', 'want' => 'ประวัติยืมย้อนหลัง 7 เดือน → กราฟ/รายงานช่วงวันที่'],
    'u_filler1'   => ['name' => T_TAG . 'สมาชิกถือสต็อก 1',     'role' => 'member', 'want' => 'ถือ borrow เพื่อปั้นสภาพ stock ของหนังสือ'],
    'u_filler2'   => ['name' => T_TAG . 'สมาชิกถือสต็อก 2',     'role' => 'member', 'want' => 'ถือ borrow เพื่อปั้นสภาพ stock ของหนังสือ'],
    'u_filler3'   => ['name' => T_TAG . 'สมาชิกถือสต็อก 3',     'role' => 'member', 'want' => 'ถือ borrow เพื่อปั้นสภาพ stock ของหนังสือ'],
];

// ── 3) หนังสือ ──
//    keep = จำนวนที่ "อยากให้เหลือให้ยืม" หลังหักของที่ถูกยืม/จองจริง
//    quantity จะถูกคำนวณเป็น (ที่ออกไปจริง + keep) ท้ายสคริปต์
$LONG_TITLE = T_TAG . str_repeat('ชื่อยาว', 32);                       // ตัดให้เหลือ 200 ตัวพอดีด้านล่าง
$BOOKS = [
    'b_normal'   => ['title' => T_TAG . 'หนังสือปกติ',            'keep' => 3, 'want' => 'เล่มปกติ มีให้ยืม'],
    'b_last'     => ['title' => T_TAG . 'เหลือเล่มสุดท้าย',        'keep' => 1, 'want' => 'available = 1 → ทดสอบยืม/จองเล่มสุดท้าย + race condition'],
    'b_out'      => ['title' => T_TAG . 'หมดสต็อก',               'keep' => 0, 'want' => 'available = 0 (เพราะถูกยืมจริง) → ยืม/จองไม่ได้'],
    'b_zero'     => ['title' => T_TAG . 'จำนวนศูนย์ (หาย/ชำรุด)',  'keep' => 0, 'want' => 'quantity = 0 → หน้าไหนก็ต้องไม่พัง'],
    'b_low'      => ['title' => T_TAG . 'ใกล้หมด',                'keep' => 2, 'want' => 'available = 2 → filter low_stock + การ์ดใกล้หมด'],
    'b_hidden'   => ['title' => T_TAG . 'ซ่อนจากหน้าสาธารณะ',      'keep' => 3, 'visible' => 0, 'want' => 'is_visible = 0 → ห้ามโผล่หน้าแรก/ผลค้นหา/จองไม่ได้'],
    'b_hid_brw'  => ['title' => T_TAG . 'ซ่อนแต่มีคนยืมค้าง',      'keep' => 1, 'visible' => 0, 'want' => 'ซ่อนแล้วคนที่ยืมอยู่ต้องยังคืนได้'],
    'b_no_isbn'  => ['title' => T_TAG . 'ไม่มี ISBN',             'keep' => 2, 'isbn' => null, 'want' => 'ISBN NULL → ฉลาก barcode ต้องไม่พัง + UNIQUE ยอมให้ NULL ซ้ำ'],
    'b_isbn_num' => ['title' => T_TAG . 'ISBN ตัวเลขล้วน',        'keep' => 2, 'isbn' => '9781234567897', 'want' => 'สแกนบาร์โค้ดด้วยเครื่องอ่าน'],
    'b_isbn_dsh' => ['title' => T_TAG . 'ISBN มีขีดคั่น',         'keep' => 2, 'isbn' => '978-616-000-111', 'want' => 'เครื่องสแกนยิงค่ามีขีด → ต้องค้นเจอ'],
    'b_no_cat'   => ['title' => T_TAG . 'ไม่มีหมวดหมู่',           'keep' => 2, 'category' => null, 'want' => 'category_id NULL → LEFT JOIN ต้องไม่ตกหล่น'],
    'b_cover'    => ['title' => T_TAG . 'มีรูปปก',                'keep' => 2, 'cover' => T_COVER_FILE, 'want' => 'มีไฟล์ปกจริง → แสดงรูป + ลบไฟล์ตอนลบหนังสือ'],
    'b_history'  => ['title' => T_TAG . 'มีประวัติยืม (คืนหมดแล้ว)', 'keep' => 2, 'want' => 'ลบไม่ได้ — มีประวัติการยืม'],
    'b_borrowed' => ['title' => T_TAG . 'กำลังถูกยืมอยู่',         'keep' => 1, 'want' => 'ลบไม่ได้ — กำลังถูกยืม'],
    'b_reserved' => ['title' => T_TAG . 'มีคนจองค้าง',            'keep' => 1, 'want' => 'ลบไม่ได้ — มีการจองรอดำเนินการ'],
    'b_delete'   => ['title' => T_TAG . 'ลบได้ (ไม่มีอะไรผูก)',    'keep' => 1, 'want' => 'ไม่มี borrow/reservation → ต้องลบสำเร็จ'],
    'b_xss'      => ['title' => T_TAG . '<script>alert("xss")</script>', 'author' => 'O\'Brien & "Co"', 'keep' => 1, 'want' => 'ทดสอบ escape ทุกหน้า + CSV export'],
    'b_formula'  => ['title' => T_TAG . '=cmd|\' /C calc\'!A0',   'keep' => 1, 'want' => 'ทดสอบ CSV formula injection ตอน export'],
    'b_longname' => ['title' => mb_substr($LONG_TITLE, 0, 200),   'keep' => 1, 'want' => 'ชื่อยาว 200 ตัวพอดี → ขอบของ validateBookData'],
];

// ── 4) การยืม ──
//    [ผู้ยืม, หนังสือ, ยืมเมื่อ(วัน), ครบกำหนด(วัน), คืนเมื่อ(วัน|null), คำอธิบาย]
//    ตัวเลขเป็น offset จากวันนี้ (ลบ = อดีต)
$BORROWS = [
    // ── ยังไม่คืน (borrowing) ──
    ['u_one',       'b_normal',   -3,  +4,  null, 'ยืมปกติ ยังไม่ถึงกำหนด'],
    ['u_due_today', 'b_normal',   -7,   0,  null, '⭐ ครบกำหนดวันนี้พอดี → ค่าปรับต้อง 0'],
    ['u_overdue',   'b_normal',   -8,  -1,  null, '⭐ เกินกำหนด 1 วัน → ค่าปรับ ' . FINE_PER_DAY . ' บาทพอดี'],
    ['u_overdue',   'b_low',     -17, -10,  null, 'เกินกำหนด 10 วัน'],
    ['u_overdue',   'b_last',    -67, -60,  null, 'เกินกำหนด 60 วัน (ค่าปรับก้อนใหญ่)'],
    ['u_quota',     'b_low',      -2,  +5,  null, 'เล่มที่ 1 ของโควตา'],
    ['u_quota',     'b_last',     -2,  +5,  null, 'เล่มที่ 2 ของโควตา'],
    ['u_quota',     'b_hidden',   -2,  +5,  null, 'เล่มที่ 3 ของโควตา → เต็ม'],
    ['u_mixed',     'b_normal',   -1,  +6,  null, 'ยืม 1 (อีก 1 เป็นการจอง)'],
    ['u_mixed',     'b_low',      -1,  +6,  null, 'ยืม 2 → รวมกับจอง 1 = เต็มโควตา'],
    ['u_staff2',    'b_normal',   -1,  +7,  null, 'staff ยืมเองได้ (admin ยืมไม่ได้)'],
    ['u_filler1',   'b_out',      -4,  +3,  null, 'ปั้นสภาพ: ทำให้ b_out หมดสต็อก'],
    ['u_filler2',   'b_out',      -4,  +3,  null, 'ปั้นสภาพ: ทำให้ b_out หมดสต็อก'],
    ['u_filler2',   'b_borrowed', -4,  +3,  null, 'ปั้นสภาพ: ทำให้ b_borrowed ลบไม่ได้'],
    ['u_filler3',   'b_hid_brw',  -4,  +3,  null, 'ปั้นสภาพ: หนังสือซ่อนที่ยังมีคนยืมค้าง'],

    // ── คืนแล้ว (returned) ──
    ['u_one',       'b_normal',  -25, -11,  -13, 'คืนก่อนกำหนด 2 วัน → ค่าปรับ 0'],
    ['u_paid',      'b_normal',  -30, -16,  -16, '⭐ คืนวันครบกำหนดพอดี → ค่าปรับ 0'],
    ['u_paid',      'b_history', -60, -46,  -36, 'คืนสาย 10 วัน → ค่าปรับจ่ายแล้ว'],
    ['u_unpaid',    'b_history', -40, -26,  -14, 'คืนสาย 12 วัน → ค่าปรับค้างชำระ'],
    ['u_unpaid',    'b_normal',  -80, -66,  -36, 'คืนสาย 30 วัน → ค่าปรับค้างชำระก้อนใหญ่'],
    ['u_clean',     null,        null, null, null, 'ตัวยึด (ไม่สร้าง borrow) — u_clean ต้องไม่มีประวัติ'],

    // ── ประวัติย้อนหลัง 7 เดือน (สำหรับกราฟรายเดือน + รายงานช่วงวันที่) ──
    ['u_history',   'b_normal',   -30,  -16, -15, 'ประวัติเดือนที่ 1'],
    ['u_history',   'b_low',      -60,  -46, -40, 'ประวัติเดือนที่ 2 (คืนสาย)'],
    ['u_history',   'b_history',  -90,  -76, -76, 'ประวัติเดือนที่ 3'],
    ['u_history',   'b_cover',   -120, -106, -100, 'ประวัติเดือนที่ 4 (คืนสาย)'],
    ['u_history',   'b_no_cat',  -150, -136, -140, 'ประวัติเดือนที่ 5'],
    ['u_history',   'b_isbn_num', -180, -166, -160, 'ประวัติเดือนที่ 6 (คืนสาย)'],
    ['u_history',   'b_no_isbn', -210, -196, -196, 'ประวัติเดือนที่ 7'],
];

// ── 5) การจอง ──
//    [ผู้จอง, หนังสือ, สถานะ, หมดอายุเมื่อ(modifier), สร้างเมื่อ(modifier), คำอธิบาย]
$RESERVATIONS = [
    ['u_reserve', 'b_reserved', 'pending',   '+2 days',  '-1 day',   'จองปกติ รอรับหนังสือ'],
    ['u_reserve', 'b_normal',   'pending',   '-1 day',   '-3 days',  '⭐ หมดอายุแล้วแต่ยัง pending → ทดสอบ lazy expire + cron'],
    ['u_auth',    'b_reserved', 'pending',   '+1 day',   '-1 hour',  'คนที่ 2 จองเล่มเดียวกัน → stock ถูกหัก 2'],
    ['u_mixed',   'b_cover',    'pending',   '+1 hour',  '-1 day',   'ใกล้หมดอายุใน 1 ชม. → badge เตือน'],
    ['u_history', 'b_low',      'cancelled', '-5 days',  '-8 days',  'เคยยกเลิก'],
    ['u_history', 'b_last',     'expired',   '-3 days',  '-6 days',  'เคยหมดอายุ (ถูก expire ไปแล้ว)'],
];

// =====================================================
// เริ่มทำงาน
// =====================================================
say();
say('╔════════════════════════════════════════════════════════════╗');
say('║  L1 Test Data Seeder — ข้อมูลทดสอบชั้นขอบ/กรณีพิเศษ        ║');
say('╚════════════════════════════════════════════════════════════╝');
say('  ค่าคงที่ที่ใช้: MAX_BORROW_BOOKS=' . MAX_BORROW_BOOKS
    . ' | DEFAULT_BORROW_DAYS=' . DEFAULT_BORROW_DAYS
    . ' | FINE_PER_DAY=' . FINE_PER_DAY);
say();

// ── โหมด --verify: ตรวจอย่างเดียว ──
if ($doVerify) {
    verifyData($pdo);
    exit(0);
}

// ── ล้างข้อมูลเดิมของตัวเองเสมอ (ทำให้รันซ้ำได้ผลเหมือนเดิม) ──
try {
    $pdo->beginTransaction();
    $removed = resetSeed($pdo);
    $pdo->commit();
    say("🧹 ล้างข้อมูลทดสอบเดิม: ผู้ใช้ {$removed['users']} คน, หนังสือ {$removed['books']} เล่ม");
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    say('❌ ล้างข้อมูลไม่สำเร็จ: ' . $e->getMessage());
    exit(1);
}

if ($doReset) {
    say('✅ เสร็จสิ้น (โหมด --reset — ไม่สร้างข้อมูลใหม่)');
    say();
    exit(0);
}

// =====================================================
// สร้างข้อมูล
// =====================================================
try {
    $pdo->beginTransaction();

    // ── หมวดหมู่ ──
    $catId = [];
    $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
    foreach ($CATEGORIES as $key => $name) {
        $stmt->execute([$name]);
        $catId[$key] = (int) $pdo->lastInsertId();
    }

    // ── ผู้ใช้ ──
    $userId = [];
    $hash = hashPassword(T_PASSWORD);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, phone, role) VALUES (?, ?, ?, ?, ?)");
    $i = 0;
    foreach ($USERS as $key => $u) {
        $email = 't_' . substr($key, 2) . T_MAIL;
        $stmt->execute([$u['name'], $email, $hash, '08' . str_pad((string) (10000000 + $i++), 8, '0', STR_PAD_LEFT), $u['role']]);
        $userId[$key] = (int) $pdo->lastInsertId();
        $USERS[$key]['email'] = $email;
    }

    // ── หนังสือ (quantity ตั้งชั่วคราว — คำนวณจริงท้ายสคริปต์) ──
    $bookId = [];
    // 🔎 ต้องเติม search_tokens เองเพราะ INSERT ตรง ๆ ไม่ผ่าน BookRepository::create()
    //    ถ้าลืม หนังสือทดสอบจะค้นหาไม่เจอ แล้วเทสต์ค้นหาจะ fail แบบงง ๆ
    $stmt = $pdo->prepare(
        "INSERT INTO books (title, author, isbn, search_tokens, category_id, description, cover_image, quantity, available, is_visible)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($BOOKS as $key => $b) {
        $title  = $b['title'];
        $author = $b['author'] ?? T_TAG . 'ผู้แต่งทดสอบ';
        $isbn   = array_key_exists('isbn', $b) ? $b['isbn'] : 'T' . str_pad((string) count($bookId), 12, '0', STR_PAD_LEFT);
        $stmt->execute([
            $title,
            $author,
            $isbn,
            buildSearchTokens(trim("$title $author $isbn")),
            array_key_exists('category', $b) ? $b['category'] : $catId['c_main'],
            $b['want'],                       // ใส่คำอธิบายสภาพไว้ในช่อง description — เปิดหน้าเว็บแล้วรู้เลยว่าเล่มนี้ไว้ทดสอบอะไร
            $b['cover']   ?? null,
            0,
            0,
            $b['visible'] ?? 1,
        ]);
        $bookId[$key] = (int) $pdo->lastInsertId();
    }

    // ── การยืม ──
    $borrowRows = [];   // เก็บไว้ใช้ผูก payment + reservation fulfilled
    $stmt = $pdo->prepare(
        "INSERT INTO borrows (user_id, book_id, borrow_date, due_date, return_date, status, fine_amount, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($BORROWS as $row) {
        [$uKey, $bKey, $from, $due, $ret, $note] = $row;
        if ($bKey === null) continue;                     // แถวตัวยึด — ไม่สร้างจริง

        $dueDate    = dayAt($due);
        $returnDate = $ret === null ? null : dayAt($ret);
        $status     = $ret === null ? 'borrowing' : 'returned';
        $fine       = $returnDate === null ? 0 : fineFor($dueDate, $returnDate);

        $stmt->execute([
            $userId[$uKey], $bookId[$bKey], dayAt($from), $dueDate, $returnDate, $status, $fine, $note,
        ]);
        $borrowRows[] = [
            'id' => (int) $pdo->lastInsertId(), 'user' => $uKey, 'book' => $bKey,
            'status' => $status, 'fine' => $fine,
        ];
    }

    // ── ค่าปรับที่ชำระแล้ว ──
    //    u_paid จ่ายครบ / u_unpaid ไม่จ่ายเลย (เอาไว้ทดสอบยอดค้างชำระ)
    $stmt = $pdo->prepare("INSERT INTO payments (borrow_id, amount, recorded_by, created_at) VALUES (?, ?, ?, ?)");
    $paidCount = 0;
    foreach ($borrowRows as $b) {
        if ($b['user'] === 'u_paid' && $b['fine'] > 0) {
            // 🧠 payment แรกบันทึกโดย staff, ที่เหลือ recorded_by = NULL
            //    เพื่อทดสอบ FK ON DELETE SET NULL ตอนลบเจ้าหน้าที่
            $stmt->execute([$b['id'], $b['fine'], $paidCount === 0 ? $userId['u_staff2'] : null, timeAt('-30 days')]);
            $paidCount++;
        }
    }

    // ── การจอง ──
    $stmt = $pdo->prepare(
        "INSERT INTO reservations (user_id, book_id, borrow_id, status, expires_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($RESERVATIONS as [$uKey, $bKey, $status, $expires, $created, $note]) {
        $stmt->execute([$userId[$uKey], $bookId[$bKey], null, $status, timeAt($expires), timeAt($created)]);
    }

    // ── การจองที่อนุมัติแล้ว (fulfilled) — ต้องผูกกับ borrow จริง ──
    $fulfilledBorrow = null;
    foreach ($borrowRows as $b) {
        if ($b['user'] === 'u_history' && $b['status'] === 'returned') {
            $fulfilledBorrow = $b['id'];
            break;
        }
    }
    if ($fulfilledBorrow) {
        $stmt->execute([
            $userId['u_history'], $bookId['b_history'], $fulfilledBorrow,
            'fulfilled', timeAt('-80 days'), timeAt('-85 days'),
        ]);
    }

    // ── Token รีเซ็ตรหัสผ่าน ──
    //    u_auth: ใช้ได้ 1 / หมดอายุ 1 / ใช้แล้ว 1
    //    u_one : ขอ 3 ครั้งภายใน 1 ชม. → ชนเพดาน rate limit (AuthService จำกัด 3 ครั้ง/ชม.)
    $stmt = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at, used, created_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$USERS['u_auth']['email'], str_repeat('a', 64), timeAt('+1 hour'),  0, timeAt('-5 minutes')]);
    $stmt->execute([$USERS['u_auth']['email'], str_repeat('b', 64), timeAt('-1 hour'),  0, timeAt('-3 hours')]);
    $stmt->execute([$USERS['u_auth']['email'], str_repeat('c', 64), timeAt('+1 hour'),  1, timeAt('-5 hours')]);
    for ($n = 0; $n < 3; $n++) {
        $stmt->execute([$USERS['u_one']['email'], str_repeat((string) $n, 64), timeAt('+1 hour'), 0, timeAt('-' . ($n + 1) . ' minutes')]);
    }

    // ── รูปปกจริง (PNG 1×1) ──
    $coverDir = BASE_PATH_SEED . '/uploads/covers';
    if (!is_dir($coverDir)) {
        mkdir($coverDir, 0777, true);
    }
    file_put_contents(
        $coverDir . '/' . T_COVER_FILE,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
    );

    // ── คำนวณ stock ──
    // 🧠 quantity = (ยืมค้างจริง + จอง pending จริง) + keep
    //    available ตามมาโดยอัตโนมัติ → invariant ถูกต้องเสมอ ไม่มีทางพิมพ์เลขผิด
    $stmt = $pdo->prepare(
        "UPDATE books b
         SET b.quantity = ? + (
                 (SELECT COUNT(*) FROM borrows br WHERE br.book_id = b.id AND br.status = 'borrowing')
               + (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.id AND r.status = 'pending')
             ),
             b.available = ?
         WHERE b.id = ?"
    );
    foreach ($BOOKS as $key => $b) {
        $stmt->execute([$b['keep'], $b['keep'], $bookId[$key]]);
    }

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    say('❌ สร้างข้อมูลไม่สำเร็จ: ' . $e->getMessage());
    say('   (rollback แล้ว — ฐานข้อมูลไม่เปลี่ยนแปลง)');
    exit(1);
}

// =====================================================
// สรุปผล
// =====================================================
say('✅ สร้างข้อมูลทดสอบเรียบร้อย');
say();
say('── บัญชีทดสอบ (รหัสผ่านทุกบัญชี: ' . T_PASSWORD . ') ──');
printf("   %-34s %-8s %s\n", 'อีเมล', 'บทบาท', 'ใช้ทดสอบ');
say('   ' . str_repeat('─', 104));
foreach ($USERS as $u) {
    printf("   %-34s %-8s %s\n", $u['email'], $u['role'], $u['want']);
}
say();
verifyData($pdo);

// =====================================================
// ตรวจความถูกต้องของข้อมูลที่สร้าง
// =====================================================
/**
 * 🎯 ตรวจว่าข้อมูลที่ seed ไว้ "อยู่ในสภาพที่ตั้งใจ" จริงหรือไม่
 *    ถ้ามีข้อไหนไม่ผ่าน แปลว่าชุดข้อมูลใช้ทดสอบ flow นั้นไม่ได้
 */
function verifyData(PDO $pdo): void
{
    say('── ตรวจสภาพข้อมูล ──');

    $checks = [];

    // 1) invariant ของ stock
    $bad = (int) $pdo->query(
        "SELECT COUNT(*) FROM books b WHERE b.title LIKE '" . T_TAG . "%' AND b.available <> b.quantity
            - (SELECT COUNT(*) FROM borrows br WHERE br.book_id = b.id AND br.status = 'borrowing')
            - (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.id AND r.status = 'pending')"
    )->fetchColumn();
    $checks[] = [$bad === 0, "stock ตรงตาม invariant ทุกเล่ม (ผิด {$bad} เล่ม)"];

    // 2) CHECK constraint ของ DB
    $viol = (int) $pdo->query(
        "SELECT COUNT(*) FROM books WHERE title LIKE '" . T_TAG . "%' AND (available < 0 OR available > quantity)"
    )->fetchColumn();
    $checks[] = [$viol === 0, "ไม่มีเล่มที่ available ติดลบหรือเกิน quantity ({$viol} เล่ม)"];

    // 3) สภาพเฉพาะที่ต้องมี
    $one = function (string $sql) use ($pdo): int {
        return (int) $pdo->query($sql)->fetchColumn();
    };
    $t = "'" . T_TAG . "%'";
    $checks[] = [$one("SELECT COUNT(*) FROM books WHERE title LIKE $t AND available = 0 AND quantity > 0") >= 1, 'มีหนังสือหมดสต็อก (available = 0, quantity > 0)'];
    $checks[] = [$one("SELECT COUNT(*) FROM books WHERE title LIKE $t AND available = 1") >= 1, 'มีหนังสือเหลือเล่มสุดท้าย (available = 1)'];
    $checks[] = [$one("SELECT COUNT(*) FROM books WHERE title LIKE $t AND quantity = 0") >= 1, 'มีหนังสือ quantity = 0 (หาย/ชำรุด)'];
    $checks[] = [$one("SELECT COUNT(*) FROM books WHERE title LIKE $t AND is_visible = 0") >= 2, 'มีหนังสือที่ถูกซ่อนอย่างน้อย 2 เล่ม'];
    $checks[] = [$one("SELECT COUNT(*) FROM books WHERE title LIKE $t AND isbn IS NULL") >= 1, 'มีหนังสือที่ไม่มี ISBN'];
    $checks[] = [$one("SELECT COUNT(*) FROM books WHERE title LIKE $t AND category_id IS NULL") >= 1, 'มีหนังสือที่ไม่มีหมวดหมู่'];
    $checks[] = [$one("SELECT COUNT(*) FROM books WHERE title LIKE $t AND cover_image IS NOT NULL") >= 1, 'มีหนังสือที่มีรูปปก'];

    $checks[] = [$one("SELECT COUNT(*) FROM borrows br JOIN books b ON b.id = br.book_id WHERE b.title LIKE $t AND br.status = 'borrowing' AND br.due_date = CURDATE()") >= 1, '⭐ มีรายการยืมที่ครบกำหนดวันนี้พอดี (ค่าปรับต้อง = 0)'];
    $checks[] = [$one("SELECT COUNT(*) FROM borrows br JOIN books b ON b.id = br.book_id WHERE b.title LIKE $t AND br.status = 'borrowing' AND br.due_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)") >= 1, '⭐ มีรายการเกินกำหนด 1 วันพอดี (ค่าปรับต้อง = ' . FINE_PER_DAY . ')'];
    $checks[] = [$one("SELECT COUNT(*) FROM borrows br JOIN books b ON b.id = br.book_id WHERE b.title LIKE $t AND br.status = 'returned' AND br.return_date = br.due_date") >= 1, '⭐ มีรายการที่คืนวันครบกำหนดพอดี (ค่าปรับต้อง = 0)'];
    $checks[] = [$one("SELECT COUNT(*) FROM reservations r JOIN books b ON b.id = r.book_id WHERE b.title LIKE $t AND r.status = 'pending' AND r.expires_at < NOW()") >= 1, '⭐ มีการจองที่หมดอายุแล้วแต่ยัง pending (ทดสอบ lazy expire)'];

    $checks[] = [$one("SELECT COUNT(*) FROM borrows br JOIN users u ON u.id = br.user_id WHERE u.email LIKE '%" . T_MAIL . "' AND br.fine_amount > 0 AND br.id NOT IN (SELECT borrow_id FROM payments)") >= 2, 'มีค่าปรับค้างชำระอย่างน้อย 2 รายการ'];
    $checks[] = [$one("SELECT COUNT(*) FROM payments p JOIN borrows br ON br.id = p.borrow_id JOIN users u ON u.id = br.user_id WHERE u.email LIKE '%" . T_MAIL . "'") >= 1, 'มีค่าปรับที่ชำระแล้ว (ทดสอบชำระซ้ำไม่ได้)'];

    // สมาชิกตามโควตา
    $quota = $one("SELECT COUNT(*) FROM (
        SELECT u.id FROM users u
        WHERE u.email LIKE '%" . T_MAIL . "'
        GROUP BY u.id
        HAVING (SELECT COUNT(*) FROM borrows br WHERE br.user_id = u.id AND br.status='borrowing')
             + (SELECT COUNT(*) FROM reservations r WHERE r.user_id = u.id AND r.status='pending') >= " . MAX_BORROW_BOOKS . "
    ) x");
    $checks[] = [$quota >= 2, "มีสมาชิกที่ยืม/จองเต็มโควตาอย่างน้อย 2 คน (พบ {$quota} คน)"];

    $clean = $one("SELECT COUNT(*) FROM users u WHERE u.email LIKE '%" . T_MAIL . "'
        AND NOT EXISTS (SELECT 1 FROM borrows br WHERE br.user_id = u.id)
        AND NOT EXISTS (SELECT 1 FROM reservations r WHERE r.user_id = u.id)");
    $checks[] = [$clean >= 1, "มีสมาชิกที่ไม่มีประวัติเลย (ลบได้) — พบ {$clean} คน"];

    // ประวัติย้อนหลังสำหรับกราฟ
    $months = $one("SELECT COUNT(DISTINCT DATE_FORMAT(br.borrow_date, '%Y-%m')) FROM borrows br
        JOIN users u ON u.id = br.user_id WHERE u.email LIKE '%" . T_MAIL . "'");
    $checks[] = [$months >= 6, "ประวัติการยืมกระจายอย่างน้อย 6 เดือน (พบ {$months} เดือน) → กราฟรายเดือนมีข้อมูลจริง"];

    // password reset
    $checks[] = [$one("SELECT COUNT(*) FROM password_resets WHERE email LIKE '%" . T_MAIL . "' AND used = 0 AND expires_at > NOW()") >= 1, 'มี token รีเซ็ตรหัสที่ยังใช้ได้'];
    $checks[] = [$one("SELECT COUNT(*) FROM password_resets WHERE email LIKE '%" . T_MAIL . "' AND expires_at < NOW()") >= 1, 'มี token ที่หมดอายุแล้ว'];
    $checks[] = [$one("SELECT COUNT(*) FROM password_resets WHERE email LIKE '%" . T_MAIL . "' AND used = 1") >= 1, 'มี token ที่ถูกใช้ไปแล้ว'];

    $pass = 0;
    foreach ($checks as [$ok, $label]) {
        say(($ok ? '   ✅ ' : '   ❌ ') . $label);
        $pass += $ok ? 1 : 0;
    }
    say();
    say("   ผลรวม: {$pass}/" . count($checks) . ' ผ่าน');

    // 🧠 ข้อที่ "หมดอายุได้เอง" — เตือนให้รู้ว่าไม่ใช่ข้อมูลเสีย แค่ถูกใช้ไปแล้ว
    if ($pass < count($checks)) {
        say();
        say('   💡 ข้อที่ไม่ผ่านมักเกิดจากสภาพข้อมูลถูก "ใช้ไป" ระหว่างทดสอบ');
        say('      เช่น การจองที่หมดอายุจะถูก expire ทันทีที่มีคนเปิดหน้าแรก (lazy expire)');
        say('      → รัน `php tests/fixtures/seed_test_data.php` ใหม่เพื่อคืนสภาพทั้งหมด');
    }
    say();
}
