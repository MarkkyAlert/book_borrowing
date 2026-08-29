<?php

/**
 * L2 School Library Seeder — ห้องสมุดโรงเรียนมัธยมขนาดจริง
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * ปั้นข้อมูลให้เหมือน "ห้องสมุดโรงเรียนที่เปิดใช้มา 3 เดือน" เพื่อทดสอบ UX จริง
 * — ไม่ใช่ข้อมูลขอบเขต และไม่ใช่ข้อมูลปริมาณล้วน
 *
 * ต่างจากชั้นอื่น:
 *   L0 database/sample_data.sql          → ห้องสมุดจิ๋ว ไว้เดโม (10 เล่ม)
 *   L1 tests/fixtures/seed_test_data.php → สภาพที่ขอบของกฎธุรกิจ ไว้ทดสอบ flow
 *   L2 ไฟล์นี้                            → ขนาดและหน้าตาเหมือนของจริง ไว้ทดสอบ "หาของเจอไหม"
 *   L3 tests/fixtures/seed_bulk_data.php → ปริมาณล้วน ไว้วัด performance
 *
 * 🔑 ต่างจาก L1/L3 ตรงที่ "ห้ามมีแท็กโผล่บนหน้าจอ" — ชื่อหนังสือและชื่อนักเรียน
 *    ต้องอ่านแล้วเหมือนของจริง เพราะไฟล์นี้ใช้ประเมินคำศัพท์และความสมจริงของ UI
 *    → จึงจดทะเบียน id ที่สร้างไว้ในไฟล์ manifest แทนการเติมแท็กในชื่อ
 *
 * 📌 การใช้งาน (CLI เท่านั้น):
 *    php tests/fixtures/seed_school_library.php                สร้างข้อมูล (ล้างของเดิมก่อน)
 *    php tests/fixtures/seed_school_library.php --reset        ล้างข้อมูลของไฟล์นี้อย่างเดียว
 *    php tests/fixtures/seed_school_library.php --verify       ตรวจข้อมูลปัจจุบัน ไม่สร้างใหม่
 *    php tests/fixtures/seed_school_library.php --expired-only  ปั้นเฉพาะ "การจองหมดอายุค้าง" ใหม่
 *
 * ⏱️ เรื่องที่ต้องรู้ก่อนทดสอบ:
 *    ระบบมี lazy expire — ทันทีที่มีคนเปิดหน้าแรก/หน้ารายการหนังสือ/หน้าจอง
 *    การจองที่หมดอายุจะถูกเคลียร์ทิ้งทันที (HomeService, BookService, ReservationService)
 *    → สภาพ "จองหมดอายุแต่ยังค้าง" อยู่ได้ไม่นาน ถ้าจะทดสอบให้รัน --expired-only ก่อนเสมอ
 *
 * ⚠️ ห้ามรันบน production — ไฟล์นี้เขียนข้อมูลจริงลงฐานข้อมูล
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$_SESSION = ['user_id' => 0, 'role' => 'admin', 'processed_actions' => []];
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF']       = 'tests/fixtures/seed_school_library.php';

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// ── เป้าหมาย (ยอดรวมทั้งฐานข้อมูล ไม่ใช่จำนวนที่ไฟล์นี้สร้าง) ──
const G_BOOKS        = 405;
const G_CATEGORIES   = 12;
const G_MEMBERS      = 204;
const G_BORROWS      = 2000;
const G_ACTIVE       = 154;
const G_OVERDUE      = 24;
const G_UNPAID_FINES = 400;
const G_PAID_FINES   = 120;
const G_RESERVATIONS = 31;
const G_EXPIRED_RES  = 5;
const G_HISTORY_DAYS = 90;

const S_PASSWORD = '123456';
const S_MAIL     = '@sk.local';           // อีเมลนักเรียนที่ไฟล์นี้สร้าง
const S_MANIFEST = __DIR__ . '/.school_seed.json';

mt_srand(20260828);                        // 🔒 สุ่มแบบเดิมทุกครั้ง — ผลทดสอบเทียบกันได้

$pdo = getDB();

function say(string $m = ''): void { echo $m . "\n"; }

function dayAt(int $offset): string { return date('Y-m-d', strtotime("{$offset} days")); }

function pick(array $a) { return $a[mt_rand(0, count($a) - 1)]; }

/** โหลด/บันทึกทะเบียน id ที่ไฟล์นี้สร้าง */
function manifestLoad(): array
{
    if (!is_file(S_MANIFEST)) return ['books' => [], 'users' => [], 'categories' => []];
    $j = json_decode((string) file_get_contents(S_MANIFEST), true);
    return is_array($j) ? $j + ['books' => [], 'users' => [], 'categories' => []] : ['books' => [], 'users' => [], 'categories' => []];
}

function manifestSave(array $m): void
{
    file_put_contents(S_MANIFEST, json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/** ลบข้อมูลของ seeder ตัวอื่นที่มีแท็กโผล่บนหน้าจอ ([TEST] / [BULK]) */
function wipeTaggedSeeds(PDO $pdo): array
{
    $before = [
        'books' => (int) $pdo->query("SELECT COUNT(*) FROM books WHERE title LIKE '[TEST]%' OR title LIKE '[BULK]%'")->fetchColumn(),
        'users' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE '%@test.local'")->fetchColumn(),
        'cats'  => (int) $pdo->query("SELECT COUNT(*) FROM categories WHERE name LIKE '[TEST]%' OR name LIKE '[BULK]%'")->fetchColumn(),
    ];

    $uIds = $pdo->query("SELECT id FROM users WHERE email LIKE '%@test.local'")->fetchAll(PDO::FETCH_COLUMN);
    $bIds = $pdo->query("SELECT id FROM books WHERE title LIKE '[TEST]%' OR title LIKE '[BULK]%'")->fetchAll(PDO::FETCH_COLUMN);
    deleteEntities($pdo, $uIds, $bIds);
    $pdo->exec("DELETE FROM categories WHERE name LIKE '[TEST]%' OR name LIKE '[BULK]%'");

    return $before;
}

/** ลบสมาชิก/หนังสือพร้อมสิ่งที่อ้างถึง — เรียงตาม FK */
function deleteEntities(PDO $pdo, array $userIds, array $bookIds): void
{
    $inU = $userIds ? implode(',', array_map('intval', $userIds)) : '0';
    $inB = $bookIds ? implode(',', array_map('intval', $bookIds)) : '0';

    // payments ผูกกับ borrows แบบ ON DELETE CASCADE → ลบ borrows พอ
    $pdo->exec("DELETE FROM reservations WHERE user_id IN ($inU) OR book_id IN ($inB)");
    $pdo->exec("DELETE FROM borrows      WHERE user_id IN ($inU) OR book_id IN ($inB)");
    $pdo->exec("DELETE FROM books        WHERE id IN ($inB)");
    $pdo->exec("DELETE FROM password_resets WHERE email IN (SELECT email FROM users WHERE id IN ($inU))");
    $pdo->exec("DELETE FROM users        WHERE id IN ($inU) AND role <> 'admin'");
}

/** ล้างเฉพาะของที่ไฟล์นี้สร้าง */
function resetSchoolSeed(PDO $pdo): array
{
    $m = manifestLoad();
    // fallback: หาเพิ่มจากอีเมลนักเรียน เผื่อ manifest หาย
    $extraU = $pdo->query("SELECT id FROM users WHERE email LIKE '%" . S_MAIL . "'")->fetchAll(PDO::FETCH_COLUMN);
    $users  = array_unique(array_merge($m['users'], $extraU));
    $books  = $m['books'];

    $n = ['users' => count($users), 'books' => count($books), 'categories' => count($m['categories'])];
    deleteEntities($pdo, $users, $books);

    if ($m['categories']) {
        $inC = implode(',', array_map('intval', $m['categories']));
        $pdo->exec("DELETE FROM categories WHERE id IN ($inC)");
    }
    @unlink(S_MANIFEST);
    return $n;
}

// ============================================================
// คลังคำสำหรับปั้นเนื้อหาให้เหมือนห้องสมุดโรงเรียนไทย
// ============================================================

// 📝 12 หมวดตามที่โรงเรียนมัธยมใช้จริง — "หนังสืออ้างอิง" ใส่ไว้เพื่อทดสอบว่าระบบ
//    แยกหนังสือห้ามยืมออกได้ไหม (ปัจจุบันยังไม่มีกลไกนั้น เป็นข้อค้นพบที่ตั้งใจให้เจอ)
$CATEGORIES = [
    'นวนิยาย'            => ['ปลายฝนต้นหนาว', 'บ้านริมคลอง', 'จดหมายถึงเธอ', 'ลมหายใจของฤดูกาล', 'เงาในสายหมอก', 'ทางกลับบ้าน', 'ดอกไม้ในกำแพง', 'คืนที่ดาวไม่ยอมหลับ', 'ระหว่างบรรทัด', 'ฝนตกที่ปลายซอย', 'เพลงของแม่น้ำ', 'ก่อนตะวันจะลับ'],
    'เรื่องสั้น'          => ['คนแปลกหน้าบนรถเมล์', 'ร้านชำปากซอย', 'สิบเจ็ดนาที', 'เก้าอี้ตัวเดิม', 'กระเป๋าใบเล็ก', 'เสียงจากห้องข้าง ๆ', 'วันพุธสีเทา', 'ของหายในห้องเรียน', 'นาฬิกาที่เดินช้า', 'ต้นไม้หน้าบ้าน', 'จดหมายไม่ถึงมือ', 'คำที่ยังไม่ได้พูด'],
    'การ์ตูนความรู้'      => ['ร่างกายมนุษย์', 'ระบบสุริยะ', 'ไดโนเสาร์', 'สัตว์ใต้ทะเลลึก', 'ภูเขาไฟ', 'สมองของเรา', 'แมลงตัวจิ๋ว', 'พลังงานไฟฟ้า', 'สภาพอากาศ', 'เมล็ดพันธุ์', 'กระดูกและกล้ามเนื้อ', 'น้ำและวัฏจักร'],
    'วิทยาศาสตร์'        => ['เคมีในครัว', 'แรงและการเคลื่อนที่', 'เซลล์สิ่งมีชีวิต', 'ดาราศาสตร์เบื้องต้น', 'ระบบนิเวศ', 'พันธุกรรม', 'แสงและเสียง', 'ธาตุและสารประกอบ', 'น้ำในธรรมชาติ', 'ถ้ำและหินงอกหินย้อย', 'ปฏิกิริยาเคมี', 'พลังงานทดแทน'],
    'คณิตศาสตร์'         => ['เรขาคณิต', 'สมการเชิงเส้น', 'ความน่าจะเป็น', 'จำนวนเต็ม', 'ตรีโกณมิติ', 'สถิติเบื้องต้น', 'เศษส่วนและทศนิยม', 'ฟังก์ชัน', 'ลำดับและอนุกรม', 'พีชคณิต', 'การให้เหตุผล', 'เมทริกซ์'],
    'ภาษาไทย'           => ['หลักภาษาไทย', 'คำราชาศัพท์', 'วรรณคดีไทย', 'การเขียนเรียงความ', 'สำนวนไทย', 'อักษรสามหมู่', 'ร้อยกรองไทย', 'คำยืมภาษาต่างประเทศ', 'การอ่านจับใจความ', 'ลำนำและกลอนแปด', 'วรรณยุกต์และเสียง', 'การเขียนย่อความ'],
    'ภาษาอังกฤษ'        => ['Tenses', 'Vocabulary', 'Reading Skills', 'Grammar', 'Conversation', 'Phrasal Verbs', 'Writing Practice', 'Listening', 'Idioms', 'Pronunciation', 'Business English', 'Exam Practice'],
    'สังคมศึกษา'         => ['อาเซียน', 'เศรษฐกิจพอเพียง', 'ภูมิศาสตร์ไทย', 'ประชาธิปไตย', 'ศาสนาในโลก', 'สิทธิมนุษยชน', 'แผนที่และการเดินทาง', 'วัฒนธรรมท้องถิ่น', 'กฎหมายใกล้ตัว', 'ประชากรและเมือง', 'ทรัพยากรธรรมชาติ', 'หน้าที่พลเมือง'],
    'ประวัติศาสตร์'       => ['กรุงศรีอยุธยา', 'สุโขทัย', 'รัตนโกสินทร์', 'สงครามโลก', 'อารยธรรมอียิปต์', 'จีนโบราณ', 'กรีกและโรมัน', 'การปฏิวัติอุตสาหกรรม', 'ล้านนา', 'อาณาจักรขอม', 'เส้นทางสายไหม', 'ประวัติศาสตร์ท้องถิ่น'],
    'ศิลปะและดนตรี'      => ['ดนตรีไทย', 'จิตรกรรมไทย', 'การวาดเส้น', 'นาฏศิลป์', 'ดนตรีสากล', 'ประติมากรรม', 'สีน้ำและสีโปสเตอร์', 'การออกแบบ', 'ภาพพิมพ์', 'เครื่องดนตรีพื้นบ้าน', 'การถ่ายภาพ', 'ศิลปะร่วมสมัย'],
    'สุขศึกษาและกีฬา'    => ['โภชนาการ', 'ฟุตบอล', 'วอลเลย์บอล', 'การปฐมพยาบาล', 'กรีฑา', 'ว่ายน้ำ', 'สุขภาพจิต', 'แบดมินตัน', 'การออกกำลังกาย', 'ยาและสารเสพติด', 'กายวิภาคเบื้องต้น', 'มวยไทย'],
    'หนังสืออ้างอิง'      => ['พจนานุกรมไทย', 'สารานุกรมเยาวชน', 'แผนที่โลก', 'อภิธานศัพท์วิทยาศาสตร์', 'พจนานุกรมอังกฤษ-ไทย', 'สารานุกรมสัตว์', 'ปฏิทินร้อยปี', 'ตารางธาตุ', 'สารานุกรมประวัติศาสตร์', 'คู่มือการเขียนบรรณานุกรม', 'พจนานุกรมคำพ้อง', 'สารานุกรมพืชสมุนไพร'],
];

// 📝 แพตเทิร์นชื่อเรื่อง — ตั้งใจให้มีสระอำ วรรณยุกต์ซ้อน และไม้ทัณฑฆาต ปนอยู่
//    ('มหัศจรรย์' = ั + ์, 'ล้ำ' = ้ + ำ) เพื่อทดสอบการแสดงผลภาษาไทย
$TITLE_PATTERNS = [
    '%s',
    'โลกของ%s',
    '%sแสนมหัศจรรย์',
    'เรื่องเล่าจาก%s',
    'คู่มือ%sฉบับนักเรียน',
    'สนุกกับ%s',
    '%sที่เราไม่เคยรู้',
    'บันทึก%s',
    '%sรอบตัวเรา',
    'ไขความลับ%s',
    '%sก้าวล้ำ',
    'ก้าวแรกสู่%s',
    '%sฉบับเข้าใจง่าย',
    'ถามตอบเรื่อง%s',
];

$AUTHOR_FIRST = ['สมชาย', 'วิไลวรรณ', 'ประเสริฐ', 'กาญจนา', 'ธีรพงษ์', 'นภาพร', 'อนุชา', 'ศิริพร', 'ชัยวัฒน์', 'ปรียานุช', 'ณัฐพล', 'มณีรัตน์', 'สุทธิพงศ์', 'จันทร์เพ็ญ', 'วรรณา', 'อดิศักดิ์'];
$AUTHOR_LAST  = ['ศรีสุวรรณ', 'ทองใบ', 'บุญมาก', 'แก้วมณี', 'พรหมมา', 'ชูเกียรติ', 'สายทอง', 'ใจดี', 'วงศ์คำ', 'รุ่งเรือง', 'อินทร์จันทร์', 'ภักดี', 'ประเสริฐศรี', 'ธนบดี', 'คำแหง', 'สุขสวัสดิ์'];

$NAME_FIRST = ['กิตติพงษ์', 'ณัฐวุฒิ', 'ธนกฤต', 'ปิยะพงศ์', 'ภูมิพัฒน์', 'ศุภกร', 'อนันดา', 'จิรายุ', 'พีรพัฒน์', 'วรเมธ', 'ธีรภัทร', 'ชยุตม์', 'ปัณณวิชญ์', 'สิรวิชญ์', 'กันตพงศ์', 'ณภัทร',
               'กนกวรรณ', 'ณัฏฐณิชา', 'ธัญชนก', 'ปาริฉัตร', 'พิมพ์ลภัส', 'ศิรประภา', 'อารียา', 'จิดาภา', 'พัชราภา', 'วรินทร', 'ธนภรณ์', 'ชาลิสา', 'ปุณยนุช', 'สุพิชญา', 'กัญญาณัฐ', 'ณิชากร'];
$NAME_LAST  = ['ศรีสมบัติ', 'ทองสุข', 'บุญเรือง', 'แก้วประเสริฐ', 'พรมสุวรรณ', 'ชูศรี', 'สายสุนทร', 'ใจงาม', 'วงศ์สถิตย์', 'รุ่งสว่าง', 'อินทรีย์', 'ภักดีวงศ์', 'มั่นคง', 'ธนวัฒน์', 'คำมูล', 'สุขเกษม',
               'จันทร์ฉาย', 'เพชรรัตน์', 'นิลกำแหง', 'อ่อนละมุน', 'สมบูรณ์ทรัพย์', 'ยิ่งยง', 'ดวงแก้ว', 'ปิ่นทอง'];

$ROOMS = ['ม.1/1', 'ม.1/2', 'ม.2/1', 'ม.2/2', 'ม.3/1', 'ม.3/2', 'ม.4/1', 'ม.4/2', 'ม.5/1', 'ม.5/2', 'ม.6/1', 'ม.6/2'];

// ============================================================
// โหมดการทำงาน
// ============================================================
$opts        = getopt('', ['reset', 'verify', 'expired-only']);
$doReset     = isset($opts['reset']);
$doVerify    = isset($opts['verify']);
$doExpOnly   = isset($opts['expired-only']);

if ($doVerify) { verifyData($pdo); exit(0); }

if ($doReset) {
    say('🧹 ล้างข้อมูลของ seeder นี้...');
    $n = resetSchoolSeed($pdo);
    say("   ลบแล้ว — หนังสือ {$n['books']} / สมาชิก {$n['users']} / หมวดหมู่ {$n['categories']}");
    recomputeStock($pdo);
    exit(0);
}

if ($doExpOnly) { makeExpiredReservations($pdo, G_EXPIRED_RES); exit(0); }

// ============================================================
// สร้างข้อมูล
// ============================================================
$t0 = microtime(true);
say('════════════════════════════════════════════════');
say('  L2 School Library Seeder');
say('════════════════════════════════════════════════');

// ── ขั้นที่ 1: ล้างของเดิม ──
$wiped = wipeTaggedSeeds($pdo);
say("🧹 ล้างข้อมูลที่มีแท็กโผล่หน้าจอ — หนังสือ [TEST]/[BULK] {$wiped['books']} เล่ม / สมาชิก @test.local {$wiped['users']} คน / หมวดหมู่ {$wiped['cats']} หมวด");
$old = resetSchoolSeed($pdo);
if ($old['books'] || $old['users']) {
    say("🧹 ล้างข้อมูลรอบก่อนของไฟล์นี้ — หนังสือ {$old['books']} / สมาชิก {$old['users']}");
}

$keptBooks   = (int) $pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
$keptMembers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='member'")->fetchColumn();
$keptBorrows = (int) $pdo->query("SELECT COUNT(*) FROM borrows")->fetchColumn();
say("📊 ของเดิมที่เก็บไว้ (จาก sample_data.sql) — หนังสือ {$keptBooks} / นักเรียน {$keptMembers} / การยืม {$keptBorrows}");
say('');

$manifest = ['books' => [], 'users' => [], 'categories' => []];

// ── ขั้นที่ 2: หมวดหมู่ ──
// 📝 ใช้หมวดเดิมถ้าชื่อตรงกันอยู่แล้ว จะได้ไม่มีหมวดซ้ำให้บรรณารักษ์สับสน
$catIds = [];
$findCat = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
$addCat  = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
foreach (array_keys($CATEGORIES) as $cname) {
    $findCat->execute([$cname]);
    $existing = $findCat->fetchColumn();
    if ($existing) {
        $catIds[$cname] = (int) $existing;
    } else {
        $addCat->execute([$cname]);
        $catIds[$cname] = (int) $pdo->lastInsertId();
        $manifest['categories'][] = $catIds[$cname];
    }
}
say('📚 หมวดหมู่: ' . count($catIds) . ' หมวด (' . implode(', ', array_slice(array_keys($catIds), 0, 4)) . ', ...)');

// 📝 รวมหมวดเก่าจาก sample_data ที่ซ้ำซ้อนเข้ากับ 12 หมวดใหม่ แล้วลบหมวดเก่าทิ้ง
//    ไม่งั้นบรรณารักษ์จะเห็นทั้ง "นิยาย" และ "นวนิยาย" ในดรอปดาวน์เดียวกัน
$merged = normalizeCategories($pdo, $catIds);
if ($merged['moved'] || $merged['dropped']) {
    say("   รวมหมวดเก่า: ย้ายหนังสือ {$merged['moved']} เล่ม / ลบหมวดซ้ำ {$merged['dropped']} หมวด");
}

// ── ขั้นที่ 3: หนังสือ ──
$needBooks = max(0, G_BOOKS - $keptBooks);
$rows      = [];
$isbnSeq   = 1000000;
$usedTitle = [];

// 🎯 เคสพิเศษที่โจทย์ทดสอบต้องใช้ — สร้างก่อน แล้วค่อยเติมเล่มธรรมดาให้ครบ
$specialBooks = [
    // ชื่อยาวจนเต็มช่อง (ทดสอบตารางเบี้ยว/ฉลากล้น)
    ['title' => 'การศึกษาเปรียบเทียบวรรณกรรมเยาวชนไทยกับวรรณกรรมเยาวชนญี่ปุ่นในช่วงสองทศวรรษที่ผ่านมา พร้อมบทวิเคราะห์แนวคิดและคุณค่าทางสังคมสำหรับครูผู้สอนระดับมัธยมศึกษา', 'cat' => 'ภาษาไทย',    'isbn' => null, 'tag' => 'ชื่อยาวเต็มช่อง + ไม่มี ISBN'],
    ['title' => 'คู่มือเตรียมสอบเข้ามหาวิทยาลัยฉบับสมบูรณ์ รวมแนวข้อสอบคณิตศาสตร์ วิทยาศาสตร์ ภาษาไทย และภาษาอังกฤษ พร้อมเฉลยละเอียดทุกข้อ ฉบับปรับปรุงใหม่ล่าสุด', 'cat' => 'คณิตศาสตร์', 'isbn' => 'auto', 'tag' => 'ชื่อยาวเต็มช่อง'],
    // วรรณยุกต์ซ้อน + สระอำ (ทดสอบการแสดงผลและการค้นหา)
    ['title' => 'น้ำ ถ้ำ และลำน้ำ: มหัศจรรย์แหล่งน้ำใต้ดินของไทย',                'cat' => 'วิทยาศาสตร์', 'isbn' => 'auto', 'tag' => 'สระอำ + วรรณยุกต์ซ้อน'],
    ['title' => 'ค่ำคืนที่ดาวพร่างพราว บันทึกนักดูดาวรุ่นเยาว์',                    'cat' => 'วิทยาศาสตร์', 'isbn' => 'auto', 'tag' => 'วรรณยุกต์ซ้อน'],
    ['title' => 'ณัฏฐ์กับกุญแจล้ำค่า',                                          'cat' => 'นวนิยาย',    'isbn' => 'auto', 'tag' => 'ไม้ทัณฑฆาต + สระอำ'],
    // ไม่มี ISBN (ทดสอบพิมพ์ฉลาก barcode)
    ['title' => 'รวมบทกลอนนักเรียนโรงเรียนของเรา เล่ม 3',                       'cat' => 'ภาษาไทย',   'isbn' => null,  'tag' => 'ไม่มี ISBN (ทำเล่มเอง)'],
    ['title' => 'ประวัติโรงเรียนและชุมชนรอบรั้ว',                                'cat' => 'ประวัติศาสตร์', 'isbn' => null, 'tag' => 'ไม่มี ISBN (ทำเล่มเอง)'],
    // ซ่อนจากนักเรียน
    ['title' => 'คู่มือครูประจำชั้น: แนวทางดูแลนักเรียนรายบุคคล',                  'cat' => 'หนังสืออ้างอิง', 'isbn' => 'auto', 'tag' => 'ซ่อนจากนักเรียน', 'hidden' => true],
    ['title' => 'เฉลยแบบฝึกหัดคณิตศาสตร์ ม.3 (สำหรับครู)',                      'cat' => 'คณิตศาสตร์', 'isbn' => 'auto', 'tag' => 'ซ่อนจากนักเรียน', 'hidden' => true],
];

foreach ($specialBooks as $sb) {
    $isbn = $sb['isbn'] === 'auto' ? '978616' . (++$isbnSeq) : null;
    $rows[] = [
        'title'    => $sb['title'],
        'author'   => pick($AUTHOR_FIRST) . ' ' . pick($AUTHOR_LAST),
        'isbn'     => $isbn,
        'cat'      => $catIds[$sb['cat']],
        'hidden'   => !empty($sb['hidden']) ? 0 : 1,
        'ref'      => 0,
        'note'     => $sb['tag'],
    ];
    $usedTitle[$sb['title']] = true;
}

// เล่มธรรมดา — วนหมวด × หัวข้อ × แพตเทิร์น จนครบจำนวน
$catNames = array_keys($CATEGORIES);
$guard = 0;
while (count($rows) < $needBooks && $guard++ < 20000) {
    $cname = $catNames[count($rows) % count($catNames)];
    $topic = pick($CATEGORIES[$cname]);
    $title = sprintf(pick($TITLE_PATTERNS), $topic);
    if (isset($usedTitle[$title])) continue;
    $usedTitle[$title] = true;
    // 📚 หนังสืออ้างอิงจริง — พจนานุกรม/สารานุกรม/แผนที่/ตารางธาตุ ในหมวดอ้างอิง
    //    ยืมออกและจองไม่ได้ แต่ยังค้นเจอตามปกติ (ดู ROADMAP ข้อ 1)
    //    🧠 ดูจาก "ชื่อเรื่อง" ไม่ใช่ "หมวดหมู่" — หมวดอ้างอิงมีหนังสือที่ยืมออกได้ปนอยู่ด้วย
    //       เช่น "คู่มือการเขียนบรรณานุกรม" ซึ่งควรยืมกลับบ้านไปอ่านได้
    $isReference = (int) (bool) preg_match('/^(พจนานุกรม|สารานุกรม|แผนที่|ตารางธาตุ|ปฏิทินร้อยปี|อภิธานศัพท์)/u', $title);

    $rows[] = [
        'title'  => $title,
        'author' => pick($AUTHOR_FIRST) . ' ' . pick($AUTHOR_LAST),
        'isbn'   => '978616' . (++$isbnSeq),
        'cat'    => $catIds[$cname],
        'hidden' => 1,
        'ref'    => $isReference,
        'note'   => $isReference ? 'หนังสืออ้างอิง (ยืม/จองไม่ได้)' : '',
    ];
}

// insert หนังสือทีละชุด 200 แถว
$bookIds = [];
$notes   = [];      // id → หมายเหตุเคสพิเศษ (ไว้พิมพ์สรุปท้ายสคริปต์)
foreach (array_chunk($rows, 200) as $chunk) {
    $ph = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?)'));
    $vals = [];
    foreach ($chunk as $r) {
        // 🔎 ต้องเติม search_tokens เองเพราะ INSERT ตรง ๆ ไม่ผ่าน BookRepository::create()
        //    ถ้าลืม การค้นหาภาษาไทยจะไม่เจอเล่มพวกนี้เลย (ดู FINDINGS F-24)
        $tokens = buildSearchTokens(trim($r['title'] . ' ' . $r['author'] . ' ' . ($r['isbn'] ?? '')));
        array_push($vals, $r['title'], $r['author'], $r['isbn'], $tokens, $r['cat'],
                   'หนังสือในความดูแลของห้องสมุดโรงเรียน — ' . $r['title'], 1, 1, $r['hidden'], $r['ref']);
    }
    $sql = "INSERT INTO books (title, author, isbn, search_tokens, category_id, description, quantity, available, is_visible, is_reference) VALUES $ph";
    $pdo->prepare($sql)->execute($vals);
    $first = (int) $pdo->lastInsertId();
    foreach ($chunk as $i => $r) {
        $bookIds[] = $first + $i;
        if ($r['note'] !== '') $notes[$first + $i] = $r['note'];
    }
}
$manifest['books'] = $bookIds;
say('📚 หนังสือ: สร้างใหม่ ' . count($bookIds) . ' เล่ม → รวมทั้งหมด ' . ($keptBooks + count($bookIds)) . ' เล่ม');

// ── ขั้นที่ 4: นักเรียน ──
$needMembers = max(0, G_MEMBERS - $keptMembers);
$hash = hashPassword(S_PASSWORD);
$memberRows = [];
$usedMail   = [];

// 🎯 นักเรียนชื่อยาวผิดปกติ — ทดสอบตารางสมาชิก + บัตรสมาชิก
$LONG_NAME = 'เด็กหญิงพิมพ์ณดาภรณ์ชนกนันท์ ศรีสมบัติวัฒนโรจน์ประเสริฐ';
$memberRows[] = ['name' => $LONG_NAME, 'mail' => 'long.name' . S_MAIL, 'note' => 'ชื่อ-นามสกุลยาวผิดปกติ (' . mb_strlen($LONG_NAME) . ' ตัวอักษร)'];
$usedMail['long.name' . S_MAIL] = true;

$seq = 0;
while (count($memberRows) < $needMembers) {
    $name = pick($NAME_FIRST) . ' ' . pick($NAME_LAST);
    $mail = 'std' . str_pad((string) (++$seq), 4, '0', STR_PAD_LEFT) . S_MAIL;
    if (isset($usedMail[$mail])) continue;
    $usedMail[$mail] = true;
    $memberRows[] = ['name' => $name, 'mail' => $mail, 'note' => ''];
}

$memberIds = [];
$mNotes    = [];
foreach (array_chunk($memberRows, 200) as $chunk) {
    // 🔑 [F-53] must_change_password = 0 โดยตั้งใจ — ระบุให้ชัด ไม่พึ่ง DEFAULT ของคอลัมน์
    //    ชุดนี้จำลอง "สมาชิกที่ใช้งานระบบอยู่แล้ว" (ตั้งรหัสของตัวเองไปนานแล้ว)
    //    รหัสในไฟล์นี้เหมือนกันทุกคนเพื่อให้เทสต์ล็อกอินได้เท่านั้น
    //    เส้นทาง "เพิ่งนำเข้า ยังไม่เปลี่ยนรหัส" มีชุดทดสอบของตัวเองที่สร้าง fixture เอง
    //    (tests/test_must_change_password.php) — ถ้าติดธงตรงนี้ เทสต์ที่ล็อกอินเป็นสมาชิกจะเด้งออกหมด
    $ph = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,0)'));
    $vals = [];
    foreach ($chunk as $m) {
        array_push($vals, $m['name'], $m['mail'], $hash, '08' . mt_rand(10000000, 99999999), 'member');
    }
    $pdo->prepare("INSERT INTO users (name, email, password, phone, role, must_change_password) VALUES $ph")->execute($vals);
    $first = (int) $pdo->lastInsertId();
    foreach ($chunk as $i => $m) {
        $memberIds[] = $first + $i;
        if ($m['note'] !== '') $mNotes[$first + $i] = $m['note'];
    }
}
$manifest['users'] = $memberIds;
say('👦 นักเรียน: สร้างใหม่ ' . count($memberIds) . ' คน → รวมทั้งหมด ' . ($keptMembers + count($memberIds)) . ' คน');
manifestSave($manifest);

// ── ขั้นที่ 5: เลือกหนังสือที่จะปั้นสภาพสต็อกพิเศษ ──
// 🧠 หลักการเดียวกับ seeder ชั้นอื่น — ไม่ hard-code เลข available
//    แต่กำหนด "จำนวนที่อยากให้เหลือบนชั้น" (spare) แล้วให้ quantity = ยืมค้าง + จอง + spare
$pool      = $bookIds;
$zeroQty   = array_pop($pool);                      // 1 เล่ม: จำนวน 0 (จำหน่ายออก/ยังไม่ได้ซื้อเข้า)
$soldOut   = array_splice($pool, -6);               // 6 เล่ม: ถูกยืมออกหมด (spare 0)
$lastCopy  = array_splice($pool, -4);               // 4 เล่ม: เหลือเล่มสุดท้าย (spare 1)
$notes[$zeroQty] = 'จำนวนในระบบ = 0 เล่ม';
foreach ($soldOut  as $b) $notes[$b] = 'ถูกยืมออกหมดทุกเล่ม';
foreach ($lastCopy as $b) $notes[$b] = 'เหลือให้ยืมเล่มสุดท้าย (ใช้ทดสอบกดพร้อมกัน)';

$borrowable = array_merge($pool, $soldOut, $lastCopy);   // ทุกเล่มยกเว้น zeroQty

// 📚 ตัดหนังสืออ้างอิงออกจากกองที่จะสุ่มให้มีคนยืม/จอง — ของจริงยืมไม่ได้อยู่แล้ว
$referenceIds = $pdo->query("SELECT id FROM books WHERE is_reference = 1")->fetchAll(PDO::FETCH_COLUMN);
if ($referenceIds) {
    $refSet = array_flip(array_map('intval', $referenceIds));
    $borrowable = array_values(array_filter($borrowable, fn($id) => !isset($refSet[$id])));
}

$spare = [];
foreach ($bookIds as $b) $spare[$b] = mt_rand(1, 4);
foreach ($soldOut  as $b) $spare[$b] = 0;
foreach ($lastCopy as $b) $spare[$b] = 1;
$spare[$zeroQty] = 0;

// ── ขั้นที่ 6: การยืมที่ยังไม่คืน ──
$keptActive   = (int) $pdo->query("SELECT COUNT(*) FROM borrows WHERE status='borrowing'")->fetchColumn();
$needActive   = max(0, G_ACTIVE - $keptActive);
$activeByBook = [];
$loadByMember = [];                                  // ยืมค้าง + จอง pending (ใช้คุมโควตา)
$activeRows   = [];

$addActive = function (int $uid, int $bid, bool $overdue) use (&$activeRows, &$activeByBook, &$loadByMember) {
    $daysAgo = $overdue ? mt_rand(9, 45) : mt_rand(0, 6);
    $bDate   = dayAt(-$daysAgo);
    $dDate   = date('Y-m-d', strtotime($bDate . ' +' . DEFAULT_BORROW_DAYS . ' days'));
    $activeRows[] = [$uid, $bid, $bDate, $dDate];
    $activeByBook[$bid] = ($activeByBook[$bid] ?? 0) + 1;
    $loadByMember[$uid] = ($loadByMember[$uid] ?? 0) + 1;
};

// 🎯 นักเรียนเคสพิเศษ (ใช้ index 1..10 — index 0 คือคนชื่อยาว ให้เป็นนักเรียนธรรมดา)
$mFullBorrow = [$memberIds[1], $memberIds[2]];                   // ยืมเต็ม 3 เล่ม
$mFullMixed  = [$memberIds[3], $memberIds[4]];                   // ยืม 2 + จอง 1 = เต็มแบบผสม
$mOneSlot    = [$memberIds[5], $memberIds[6], $memberIds[7]];    // เหลือโควตา 1 ช่อง
$mManyFines  = [$memberIds[8], $memberIds[9], $memberIds[10]];   // ค้างค่าปรับหลายรายการ

$freeBooks = $borrowable;
shuffle($freeBooks);
$cursor = 0;
$nextBook = function () use (&$cursor, $freeBooks, &$activeByBook) {
    $n = count($freeBooks);
    for ($i = 0; $i < $n; $i++) {
        $b = $freeBooks[($cursor + $i) % $n];
        if (($activeByBook[$b] ?? 0) < 3) { $cursor = ($cursor + $i + 1) % $n; return $b; }
    }
    return $freeBooks[0];
};

foreach ($mFullBorrow as $uid) for ($i = 0; $i < MAX_BORROW_BOOKS; $i++)     $addActive($uid, $nextBook(), $i === 0 && mt_rand(0, 1) === 1);
foreach ($mFullMixed  as $uid) for ($i = 0; $i < MAX_BORROW_BOOKS - 1; $i++) $addActive($uid, $nextBook(), false);
foreach ($mOneSlot    as $uid) for ($i = 0; $i < MAX_BORROW_BOOKS - 1; $i++) $addActive($uid, $nextBook(), false);
foreach ($soldOut     as $bid) {                                  // เล่มที่ต้อง "ยืมออกหมด" ต้องมีคนยืมจริง
    for ($i = 0; $i < mt_rand(1, 2); $i++) {
        $uid = $memberIds[mt_rand(11, count($memberIds) - 1)];
        if (($loadByMember[$uid] ?? 0) >= MAX_BORROW_BOOKS) continue;
        $addActive($uid, $bid, false);
    }
}
foreach ($lastCopy as $bid) {
    $uid = $memberIds[mt_rand(11, count($memberIds) - 1)];
    if (($loadByMember[$uid] ?? 0) < MAX_BORROW_BOOKS) $addActive($uid, $bid, false);
}

// เติมให้ครบเป้า — และปั้นให้เกินกำหนดคืนตามจำนวนที่ต้องการ
$overdueSoFar = 0;
foreach ($activeRows as $r) if ($r[3] < date('Y-m-d')) $overdueSoFar++;
$guard = 0;
while (count($activeRows) < $needActive && $guard++ < 100000) {
    $uid = $memberIds[mt_rand(11, count($memberIds) - 1)];
    if (($loadByMember[$uid] ?? 0) >= MAX_BORROW_BOOKS) continue;
    $wantOverdue = $overdueSoFar < G_OVERDUE;
    $addActive($uid, $nextBook(), $wantOverdue);
    if ($wantOverdue) $overdueSoFar++;
}
say('📖 การยืมที่ยังไม่คืน: ' . count($activeRows) . " รายการ (เกินกำหนดคืน {$overdueSoFar} รายการ)");

// ── ขั้นที่ 7: ประวัติการยืมย้อนหลัง 90 วัน (คืนแล้วทั้งหมด) ──
$needHistory = max(0, G_BORROWS - $keptBorrows - count($activeRows));
$lateTarget  = G_UNPAID_FINES + G_PAID_FINES;
$historyRows = [];                                   // [uid, bid, bDate, dDate, rDate, fine]

$makeHistory = function (int $uid, int $bid, bool $late) {
    // ยืมย้อนหลังไม่เกิน 90 วัน และต้องคืนไปแล้ว (return_date <= วันนี้)
    $daysAgo = mt_rand(DEFAULT_BORROW_DAYS + 2, G_HISTORY_DAYS);
    $bDate   = dayAt(-$daysAgo);
    $dDate   = date('Y-m-d', strtotime($bDate . ' +' . DEFAULT_BORROW_DAYS . ' days'));
    if ($late) {
        $maxLate = min(30, max(1, (int) floor((time() - strtotime($dDate)) / 86400)));
        $daysLate = mt_rand(1, $maxLate);
        $rDate = date('Y-m-d', strtotime($dDate . " +{$daysLate} days"));
        $fine  = $daysLate * FINE_PER_DAY;
    } else {
        $rDate = date('Y-m-d', strtotime($dDate . ' -' . mt_rand(0, 5) . ' days'));
        $fine  = 0;
    }
    return [$uid, $bid, $bDate, $dDate, $rDate, $fine];
};

// 🎯 นักเรียนที่ค้างค่าปรับหลายรายการพร้อมกัน — บังคับให้มีคนละ 4 รายการ
foreach ($mManyFines as $uid) {
    for ($i = 0; $i < 4; $i++) $historyRows[] = $makeHistory($uid, pick($borrowable), true);
}
$forcedLate = count($historyRows);

for ($i = count($historyRows); $i < $needHistory; $i++) {
    $uid  = $memberIds[mt_rand(0, count($memberIds) - 1)];
    $late = $i < $lateTarget;
    $historyRows[] = $makeHistory($uid, pick($borrowable), $late);
}

// ── ขั้นที่ 8: เขียน borrows ลงฐานข้อมูล ──
$allRows = [];
foreach ($activeRows  as $r) $allRows[] = [$r[0], $r[1], $r[2], $r[3], null, 'borrowing', 0];
foreach ($historyRows as $r) $allRows[] = [$r[0], $r[1], $r[2], $r[3], $r[4], 'returned', $r[5]];

$borrowIds = [];
foreach (array_chunk($allRows, 300) as $chunk) {
    $ph   = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?)'));
    $vals = [];
    foreach ($chunk as $r) array_push($vals, ...$r);
    $pdo->prepare("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, return_date, status, fine_amount) VALUES $ph")->execute($vals);
    $first = (int) $pdo->lastInsertId();
    foreach ($chunk as $i => $r) $borrowIds[] = ['id' => $first + $i, 'fine' => $r[6]];
}
say('🗂  ประวัติการยืม: เขียนทั้งหมด ' . count($allRows) . ' รายการ → รวมในระบบ ' . ($keptBorrows + count($allRows)) . ' รายการ');

// ── ขั้นที่ 9: การชำระค่าปรับ — จ่ายแล้วบางส่วน ที่เหลือค้างชำระ ──
$fined = array_values(array_filter($borrowIds, fn($b) => $b['fine'] > 0));
$staffId = (int) ($pdo->query("SELECT id FROM users WHERE role IN ('admin','staff') ORDER BY id LIMIT 1")->fetchColumn() ?: 1);
$toPay   = array_slice($fined, 0, G_PAID_FINES);
foreach (array_chunk($toPay, 200) as $chunk) {
    $ph = implode(',', array_fill(0, count($chunk), '(?,?,?)'));
    $vals = [];
    foreach ($chunk as $b) array_push($vals, $b['id'], $b['fine'], $staffId);
    $pdo->prepare("INSERT INTO payments (borrow_id, amount, recorded_by) VALUES $ph")->execute($vals);
}
say('💰 ค่าปรับ: มีค่าปรับ ' . count($fined) . ' รายการ — ชำระแล้ว ' . count($toPay) . ' / ค้างชำระ ' . (count($fined) - count($toPay)));

// ── ขั้นที่ 10: การจอง ──
$resRows = [];
$addRes = function (int $uid, int $bid, string $expires) use (&$resRows, &$loadByMember) {
    $resRows[] = [$uid, $bid, $expires];
    $loadByMember[$uid] = ($loadByMember[$uid] ?? 0) + 1;
};

// 🎯 นักเรียนที่ "เต็มโควตาเพราะจองไว้ 1 เล่ม" — เคสที่ข้อความแจ้งเตือนต้องอธิบายให้ชัด
foreach ($mFullMixed as $uid) $addRes($uid, pick($borrowable), date('Y-m-d H:i:s', strtotime('+2 days')));

$keptPending = (int) $pdo->query("SELECT COUNT(*) FROM reservations WHERE status='pending'")->fetchColumn();
$needFuture  = max(0, G_RESERVATIONS - $keptPending - G_EXPIRED_RES);
$guard = 0;
while (count($resRows) < $needFuture && $guard++ < 100000) {
    $uid = $memberIds[mt_rand(11, count($memberIds) - 1)];
    if (($loadByMember[$uid] ?? 0) >= MAX_BORROW_BOOKS) continue;
    $addRes($uid, pick($borrowable), date('Y-m-d H:i:s', strtotime('+' . mt_rand(1, 2) . ' days -' . mt_rand(0, 20) . ' hours')));
}

// 🎯 การจองที่เลยวันหมดอายุแล้วแต่ยังค้างในระบบ
//    ⚠️ อยู่ได้ไม่นาน — lazy expire จะเคลียร์ทิ้งทันทีที่มีคนเปิดหน้าแรก/หน้าหนังสือ
$guard = 0;
$expiredWanted = G_EXPIRED_RES;
while ($expiredWanted > 0 && $guard++ < 100000) {
    $uid = $memberIds[mt_rand(11, count($memberIds) - 1)];
    if (($loadByMember[$uid] ?? 0) >= MAX_BORROW_BOOKS) continue;
    $addRes($uid, pick($borrowable), date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 6) . ' days')));
    $expiredWanted--;
}

$pendingByBook = [];
$ph = implode(',', array_fill(0, count($resRows), '(?,?,?,?)'));
$vals = [];
foreach ($resRows as $r) {
    array_push($vals, $r[0], $r[1], 'pending', $r[2]);
    $pendingByBook[$r[1]] = ($pendingByBook[$r[1]] ?? 0) + 1;
}
$pdo->prepare("INSERT INTO reservations (user_id, book_id, status, expires_at) VALUES $ph")->execute($vals);
say('🔖 การจอง: สร้างใหม่ ' . count($resRows) . ' รายการ → รอรับรวม ' . ($keptPending + count($resRows)) . ' รายการ (เลยวันหมดอายุ ' . G_EXPIRED_RES . ')');

// ── ขั้นที่ 11: คำนวณสต็อกจากข้อมูลจริง ──
// quantity = ยืมค้าง + จอง pending + จำนวนที่อยากให้เหลือบนชั้น
$upd = $pdo->prepare("UPDATE books SET quantity = ?, available = ? WHERE id = ?");
foreach ($bookIds as $bid) {
    $active  = $activeByBook[$bid]  ?? 0;
    $pending = $pendingByBook[$bid] ?? 0;
    $sp      = $spare[$bid];
    $upd->execute([$active + $pending + $sp, $sp, $bid]);
}
// 🕐 created_at ต้องไล่ตามวันที่ยืมจริง ไม่ใช่เวลาที่สคริปต์เขียนลงฐานข้อมูล
//    ไม่งั้นหน้า "การยืมล่าสุด" และการเรียงลำดับในตารางจะเพี้ยนทั้งระบบ
$pdo->exec("UPDATE borrows SET created_at = TIMESTAMP(borrow_date, SEC_TO_TIME(FLOOR(RAND()*28800)+28800)),
                               updated_at = TIMESTAMP(COALESCE(return_date, borrow_date), SEC_TO_TIME(FLOOR(RAND()*28800)+28800))");
$pdo->exec("UPDATE payments p JOIN borrows b ON b.id = p.borrow_id
            SET p.created_at = TIMESTAMP(COALESCE(b.return_date, b.borrow_date), SEC_TO_TIME(FLOOR(RAND()*28800)+28800))");
$pdo->exec("UPDATE reservations SET created_at = DATE_SUB(expires_at, INTERVAL 2 DAY)");

recomputeStock($pdo);   // เล่มเดิมจาก sample_data ก็ให้ invariant ถูกด้วย
say('📦 คำนวณสต็อกใหม่จาก borrows + reservations จริงแล้ว');

say('');
say('════════════════════════════════════════════════');
verifyData($pdo);
say('');
say('รหัสผ่านนักเรียนทุกบัญชี: ' . S_PASSWORD . '  (อีเมล std0001' . S_MAIL . ' ... )');
say('⏱  ใช้เวลา ' . round(microtime(true) - $t0, 1) . ' วินาที');
say('');
say('⚠️  การจองหมดอายุจะถูก lazy expire เคลียร์ทันทีที่เปิดหน้าเว็บ');
say('    ก่อนทดสอบข้อนั้นให้รัน: php tests/fixtures/seed_school_library.php --expired-only');

// ============================================================
// ฟังก์ชันช่วย (PHP hoist ไว้ให้แล้ว เรียกจากข้างบนได้)
// ============================================================

/** ทำให้ invariant `available = quantity − ยืมค้าง − จอง pending` ถูกต้องทุกเล่ม */
function recomputeStock(PDO $pdo): void
{
    $pdo->exec("
        UPDATE books b
        SET b.available = GREATEST(0, b.quantity
            - (SELECT COUNT(*) FROM borrows      x WHERE x.book_id = b.id AND x.status = 'borrowing')
            - (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.id AND r.status = 'pending'))
    ");
}

/**
 * ปั้นสภาพ "การจองหมดอายุแล้วแต่ยังค้างในระบบ" ขึ้นมาใหม่
 * 📝 ต้องรันซ้ำก่อนทดสอบทุกครั้ง เพราะ lazy expire กินสภาพนี้ทิ้งเร็วมาก
 */
function makeExpiredReservations(PDO $pdo, int $want): void
{
    $have = (int) $pdo->query("SELECT COUNT(*) FROM reservations WHERE status='pending' AND expires_at < NOW()")->fetchColumn();
    if ($have >= $want) {
        say("✅ มีการจองหมดอายุค้างอยู่แล้ว {$have} รายการ — ไม่ต้องสร้างเพิ่ม");
        return;
    }

    $need = $want - $have;
    // เลือกนักเรียนที่ยังมีโควตาเหลือ
    $rows = $pdo->query("
        SELECT u.id FROM users u
        WHERE u.role = 'member'
          AND (SELECT COUNT(*) FROM borrows      b WHERE b.user_id = u.id AND b.status = 'borrowing')
            + (SELECT COUNT(*) FROM reservations r WHERE r.user_id = u.id AND r.status = 'pending') < " . MAX_BORROW_BOOKS . "
        ORDER BY u.id DESC LIMIT {$need}
    ")->fetchAll(PDO::FETCH_COLUMN);

    $books = $pdo->query("SELECT id FROM books WHERE quantity > 0 ORDER BY RAND() LIMIT {$need}")->fetchAll(PDO::FETCH_COLUMN);
    if (count($rows) < $need || count($books) < $need) {
        say('⚠️  หานักเรียนหรือหนังสือที่ว่างพอไม่ได้ — สร้างได้ ' . min(count($rows), count($books)) . ' รายการ');
    }

    $ins = $pdo->prepare("INSERT INTO reservations (user_id, book_id, status, expires_at) VALUES (?,?,'pending',?)");
    $made = 0;
    foreach ($rows as $i => $uid) {
        if (!isset($books[$i])) break;
        $ins->execute([$uid, $books[$i], date('Y-m-d H:i:s', strtotime('-' . (($i % 6) + 1) . ' days'))]);
        $made++;
    }
    recomputeStock($pdo);
    say("🔖 สร้างการจองหมดอายุค้างเพิ่ม {$made} รายการ (รวมเป็น " . ($have + $made) . ')');
    say('⚠️  รีบทดสอบทันที — เปิดหน้าแรกหรือหน้ารายการหนังสือเมื่อไหร่ ระบบจะเคลียร์ทิ้งทันที');
}

/** ตรวจว่าข้อมูลตรงเป้าหมายไหม + แสดงเคสพิเศษที่ปั้นไว้ */
function verifyData(PDO $pdo): void
{
    $q = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();

    $checks = [
        ['หนังสือ',                G_BOOKS,        $q("SELECT COUNT(*) FROM books")],
        ['หมวดหมู่',               G_CATEGORIES,   $q("SELECT COUNT(*) FROM categories")],
        ['นักเรียน',               G_MEMBERS,      $q("SELECT COUNT(*) FROM users WHERE role='member'")],
        ['ประวัติการยืมทั้งหมด',    G_BORROWS,      $q("SELECT COUNT(*) FROM borrows")],
        ['กำลังยืมอยู่',            G_ACTIVE,       $q("SELECT COUNT(*) FROM borrows WHERE status='borrowing'")],
        ['เกินกำหนดคืน',           G_OVERDUE,      $q("SELECT COUNT(*) FROM borrows WHERE status='borrowing' AND due_date < CURDATE()")],
        ['ค่าปรับค้างชำระ',        G_UNPAID_FINES, $q("SELECT COUNT(*) FROM borrows b WHERE b.fine_amount>0 AND NOT EXISTS(SELECT 1 FROM payments p WHERE p.borrow_id=b.id)")],
        ['ชำระค่าปรับแล้ว',        G_PAID_FINES,   $q("SELECT COUNT(*) FROM payments")],
        ['การจองรอรับ',            G_RESERVATIONS, $q("SELECT COUNT(*) FROM reservations WHERE status='pending'")],
    ];

    say('📋 ตรวจยอดเทียบเป้าหมาย');
    foreach ($checks as [$label, $target, $actual]) {
        $diff = abs($actual - $target);
        $ok   = $diff <= max(3, (int) round($target * 0.03));
        printf("   %-24s เป้า %6d   จริง %6d   %s\n", $label, $target, $actual, $ok ? '✅' : '⚠️');
    }

    say('');
    say('📋 เคสพิเศษที่ต้องมีในข้อมูล');
    $cases = [
        ['นักเรียนยืม+จองเต็มโควตา',   "SELECT COUNT(*) FROM (SELECT u.id FROM users u WHERE u.role='member' AND (SELECT COUNT(*) FROM borrows WHERE user_id=u.id AND status='borrowing')+(SELECT COUNT(*) FROM reservations WHERE user_id=u.id AND status='pending') >= " . MAX_BORROW_BOOKS . ") x", 2],
        ['นักเรียนเหลือโควตา 1 ช่อง',  "SELECT COUNT(*) FROM (SELECT u.id FROM users u WHERE u.role='member' AND (SELECT COUNT(*) FROM borrows WHERE user_id=u.id AND status='borrowing')+(SELECT COUNT(*) FROM reservations WHERE user_id=u.id AND status='pending') = " . (MAX_BORROW_BOOKS - 1) . ") y", 2],
        ['นักเรียนค้างค่าปรับ ≥3 ใบ',  "SELECT COUNT(*) FROM (SELECT b.user_id FROM borrows b WHERE b.fine_amount>0 AND NOT EXISTS(SELECT 1 FROM payments p WHERE p.borrow_id=b.id) GROUP BY b.user_id HAVING COUNT(*)>=3) z", 3],
        ['นักเรียนชื่อยาว ≥40 ตัว',    "SELECT COUNT(*) FROM users WHERE role='member' AND CHAR_LENGTH(name)>=40", 1],
        ['หนังสือไม่มี ISBN',          "SELECT COUNT(*) FROM books WHERE isbn IS NULL OR isbn=''", 3],
        ['หนังสือชื่อยาว ≥60 ตัว',     "SELECT COUNT(*) FROM books WHERE CHAR_LENGTH(title)>=60", 2],
        ['หนังสือมีสระอำ/วรรณยุกต์ซ้อน', "SELECT COUNT(*) FROM books WHERE title REGEXP 'ำ|ั้|ั่|ิ้|ฏฐ'", 5],
        ['หนังสือที่ถูกซ่อน',          "SELECT COUNT(*) FROM books WHERE is_visible=0", 2],
        ['หนังสือจำนวน = 0',          "SELECT COUNT(*) FROM books WHERE quantity=0", 1],
        ['หนังสือถูกยืมออกหมด',        "SELECT COUNT(*) FROM books WHERE available=0 AND quantity>0", 5],
        ['หนังสือเหลือเล่มสุดท้าย',     "SELECT COUNT(*) FROM books WHERE available=1", 4],
        ['การจองหมดอายุแต่ยังค้าง',    "SELECT COUNT(*) FROM reservations WHERE status='pending' AND expires_at<NOW()", 1],
        ['หนังสืออ้างอิง (ยืมไม่ได้)',  "SELECT COUNT(*) FROM books WHERE is_reference=1", 3],
        // $min = 0 หมายถึง "ต้องไม่มีเลย" — นับตัวที่ละเมิดตรง ๆ ไม่ใช่เขียนเป็น boolean
        // (เคยเขียนเป็น `SELECT (...)=0` แล้วจอแสดง "1 รายการ ✅" ซึ่งอ่านแล้วเข้าใจกลับด้าน)
        ['อ้างอิงที่ถูกยืมอยู่',        "SELECT COUNT(*) FROM borrows b JOIN books k ON k.id=b.book_id WHERE k.is_reference=1 AND b.status='borrowing'", 0],
    ];
    foreach ($cases as [$label, $sql, $min]) {
        $n = (int) $pdo->query($sql)->fetchColumn();
        if ($min === 0) {
            printf("   %-28s %4d รายการ   %s\n", $label, $n, $n === 0 ? '✅ (ต้องเป็น 0)' : '❌ ต้องเป็น 0');
        } else {
            printf("   %-28s %4d รายการ   %s\n", $label, $n, $n >= $min ? '✅' : '⚠️  ต้องมีอย่างน้อย ' . $min);
        }
    }

    // 🛡️ invariant ต้องไม่พังหลัง seed
    $bad = (int) $pdo->query("
        SELECT COUNT(*) FROM books b WHERE b.available <> GREATEST(0, b.quantity
            - (SELECT COUNT(*) FROM borrows      x WHERE x.book_id=b.id AND x.status='borrowing')
            - (SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id AND r.status='pending'))
    ")->fetchColumn();
    say('');
    say($bad === 0 ? '✅ invariant สต็อกถูกต้องทุกเล่ม' : "❌ สต็อกเพี้ยน {$bad} เล่ม");
}


/**
 * รวมหมวดหมู่เก่าที่ซ้ำกับ 12 หมวดมาตรฐาน แล้วลบหมวดที่ไม่ได้ใช้ออก
 * ⚠️ แตะข้อมูลนอก manifest (หมวดจาก sample_data) — --reset จะไม่คืนหมวดเหล่านี้กลับมา
 */
function normalizeCategories(PDO $pdo, array $catIds): array
{
    $map = [
        'นิยาย'        => 'นวนิยาย',
        'วรรณกรรม'     => 'นวนิยาย',
        'ทั่วไป'        => 'นวนิยาย',
        'การ์ตูน'       => 'การ์ตูนความรู้',
        'วิชาการ'       => 'หนังสืออ้างอิง',
        'จิตวิทยา'      => 'สุขศึกษาและกีฬา',
        'ธุรกิจ'        => 'สังคมศึกษา',
        'เทคโนโลยี'     => 'วิทยาศาสตร์',
    ];

    $moved = 0;
    $move = $pdo->prepare("UPDATE books SET category_id = ? WHERE category_id = ?");
    $find = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
    foreach ($map as $from => $to) {
        $find->execute([$from]);
        $fromId = $find->fetchColumn();
        if (!$fromId || !isset($catIds[$to])) continue;
        $move->execute([$catIds[$to], (int) $fromId]);
        $moved += $move->rowCount();
    }

    // ลบหมวดที่ไม่อยู่ใน 12 หมวด และไม่มีหนังสือค้างอยู่
    $keep = implode(',', array_map('intval', $catIds));
    $stmt = $pdo->query("SELECT id FROM categories WHERE id NOT IN ($keep)
                         AND NOT EXISTS (SELECT 1 FROM books WHERE category_id = categories.id)");
    $drop = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($drop) {
        $pdo->exec("DELETE FROM categories WHERE id IN (" . implode(',', array_map('intval', $drop)) . ")");
    }
    return ['moved' => $moved, 'dropped' => count($drop)];
}
