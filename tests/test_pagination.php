<?php

/**
 * Pagination Tests — ตรรกะแบ่งหน้า (F-21)
 *
 * ทดสอบว่าการเพิ่ม LIMIT/OFFSET ไม่ทำให้ข้อมูลเพี้ยน:
 * - ยอดรวมจาก countXxx() ต้องตรงกับจำนวนแถวจริงของ findXxx() แบบไม่แบ่งหน้า
 * - เดินทุกหน้าแล้วต้องได้ id ครบ ไม่ซ้ำ ไม่ตกหล่น (ตัวชี้วัดว่าเรียงลำดับคงที่)
 * - filter ต้องมีผลกับทั้งยอดนับและรายการ (ไม่งั้นจะบอกจำนวนหน้าผิด)
 * - 🛡️ visible_only ต้องไม่หลุดตอนแบ่งหน้า (F-01 เคยหลุดมาแล้ว)
 * - helper paginate() ต้อง clamp ค่าเพี้ยนจาก $_GET ได้ทุกแบบ
 *
 * Usage: php tests/test_pagination.php
 * ⚠️ รันบน CLI เท่านั้น — อ่านอย่างเดียว ไม่แก้ไขข้อมูลใด ๆ
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// 📝 functions.php เรียก startSession() ท้ายไฟล์ — ต้องมี superglobal ให้ครบก่อน
$_SESSION = [];
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF']       = 'tests/test_pagination.php';

require_once __DIR__ . '/../bootstrap.php';

use App\Repositories\BookRepository;
use App\Repositories\BorrowRepository;
use App\Repositories\UserRepository;
use App\Services\HomeService;

$results = ['passed' => 0, 'failed' => 0, 'total' => 0];

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
    echo "  \033[31m❌ $id\033[0m: $msg\n";
}

/**
 * 🎯 เดินทุกหน้าแล้วรวบ id มาตรวจว่าครบ/ไม่ซ้ำ
 * 🧠 นี่คือการทดสอบที่จับ bug เรื่องการเรียงลำดับไม่คงที่ได้จริง
 *    ถ้า ORDER BY ไม่มีตัวตัดสินเมื่อค่าเท่ากัน จะเจอ id ซ้ำข้ามหน้า
 */
function walkAllPages(callable $fetchPage, int $total, int $perPage): array
{
    $seen = [];
    $dupes = [];
    $pages = max(1, (int) ceil($total / $perPage));

    for ($p = 1; $p <= $pages; $p++) {
        foreach ($fetchPage($perPage, ($p - 1) * $perPage) as $row) {
            $id = (int) $row['id'];
            if (isset($seen[$id])) {
                $dupes[] = $id;
            }
            $seen[$id] = true;
        }
    }
    return ['ids' => array_keys($seen), 'dupes' => $dupes, 'pages' => $pages];
}

$pdo = getDB();

echo "\n╔════════════════════════════════════════════════════════════╗\n";
echo "║  Pagination Tests — LIMIT/OFFSET ไม่ทำให้ข้อมูลเพี้ยน      ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// ═══════════════════════════════════════════════════
// PG-01…PG-04: helper paginate() — ตรรกะล้วน
// ═══════════════════════════════════════════════════
echo "── helper paginate() ──\n";

$m = paginate(137, 3, 20);
($m['page'] === 3 && $m['offset'] === 40 && $m['total_pages'] === 7 && $m['from'] === 41 && $m['to'] === 60)
    ? pass('PG-01', "137 รายการ หน้า 3 → ข้าม 40 แถว แสดง 41–60 จาก 7 หน้า")
    : fail('PG-01', 'คำนวณผิด: ' . json_encode($m));

// 🛡️ ค่าเพี้ยนจาก $_GET ต้องไม่ทำให้ SQL พังหรือได้ offset ติดลบ
$bad = [
    ['abc', 1, 'ตัวอักษร'],
    [-5, 1, 'ติดลบ'],
    [0, 1, 'ศูนย์'],
    [9999, 7, 'เกินจำนวนหน้าจริง'],
];
$allOk = true;
$detail = [];
foreach ($bad as [$in, $want, $label]) {
    $got = paginate(137, $in, 20);
    $detail[] = "$label→{$got['page']}";
    if ($got['page'] !== $want || $got['offset'] < 0) {
        $allOk = false;
    }
}
$allOk ? pass('PG-02', 'clamp ค่าเพี้ยนได้ครบ (' . implode(' · ', $detail) . ')')
       : fail('PG-02', 'clamp ไม่ครบ: ' . implode(' · ', $detail));

$empty = paginate(0, 1, 20);
($empty['total_pages'] === 1 && $empty['from'] === 0 && $empty['to'] === 0)
    ? pass('PG-03', 'ไม่มีข้อมูลเลย → 1 หน้า แสดง 0–0 (ไม่หารด้วยศูนย์)')
    : fail('PG-03', 'ผลไม่ตรง: ' . json_encode($empty));

// 📝 ย่อเลขหน้าเมื่อมีหลายหน้า
$nums = paginationPageNumbers(6, 10);
($nums[0] === 1 && end($nums) === 10 && in_array(null, $nums, true) && in_array(6, $nums, true))
    ? pass('PG-04', '10 หน้า อยู่หน้า 6 → [' . implode(',', array_map(fn($n) => $n ?? '…', $nums)) . ']')
    : fail('PG-04', 'ย่อเลขหน้าผิด: ' . json_encode($nums));

// ═══════════════════════════════════════════════════
// PG-05…PG-07: BookRepository
// ═══════════════════════════════════════════════════
echo "\n── หนังสือ (BookRepository) ──\n";
$bookRepo = new BookRepository($pdo);

$allBooks  = $bookRepo->findAll();
$countAll  = $bookRepo->countAll();
(count($allBooks) === $countAll)
    ? pass('PG-05', "countAll() = findAll() = $countAll เล่ม")
    : fail('PG-05', "ยอดไม่ตรง: countAll=$countAll findAll=" . count($allBooks));

$walk = walkAllPages(
    fn($l, $o) => $bookRepo->findAll(['limit' => $l, 'offset' => $o]),
    $countAll,
    5
);
if ($walk['dupes']) {
    fail('PG-06', 'เจอ id ซ้ำข้ามหน้า (การเรียงไม่คงที่): ' . implode(',', array_slice($walk['dupes'], 0, 5)));
} elseif (count($walk['ids']) !== $countAll) {
    fail('PG-06', "เดินครบ {$walk['pages']} หน้าได้ " . count($walk['ids']) . " เล่ม แต่ควรได้ $countAll");
} else {
    pass('PG-06', "เดินครบ {$walk['pages']} หน้า (หน้าละ 5) ได้ $countAll เล่ม ไม่ซ้ำไม่ตกหล่น");
}

// 📝 filter ต้องมีผลกับทั้ง count และ list
$f = ['status' => 'available'];
$cf = $bookRepo->countAll($f);
$lf = $bookRepo->findAll($f);
$page1 = $bookRepo->findAll($f + ['limit' => 3, 'offset' => 0]);
($cf === count($lf) && count($page1) === min(3, $cf) && $cf <= $countAll)
    ? pass('PG-07', "filter available → นับได้ $cf ตรงกับรายการ · หน้าแรกได้ " . count($page1) . " เล่ม")
    : fail('PG-07', "count=$cf list=" . count($lf) . " page1=" . count($page1));

// ═══════════════════════════════════════════════════
// PG-08: 🛡️ visible_only ต้องไม่หลุดตอนแบ่งหน้า (F-01)
// ═══════════════════════════════════════════════════
echo "\n── 🛡️ ความปลอดภัย: หนังสือที่ซ่อนต้องไม่โผล่ (F-01) ──\n";
$hiddenIds = $pdo->query("SELECT id FROM books WHERE is_visible = 0")->fetchAll(PDO::FETCH_COLUMN);

$homeService = new HomeService($pdo);
$leaked = [];
$publicTotal = $homeService->getBooks(['page' => 1])['pagination']['total'];
$pagesToWalk = max(1, (int) ceil($publicTotal / BOOKS_PER_PAGE));
for ($p = 1; $p <= $pagesToWalk; $p++) {
    foreach ($homeService->getBooks(['page' => $p])['books'] as $b) {
        if (in_array((string) $b['id'], array_map('strval', $hiddenIds), true)) {
            $leaked[] = $b['id'];
        }
    }
}
$visibleCount = (int) $pdo->query("SELECT COUNT(*) FROM books WHERE is_visible = 1")->fetchColumn();
if ($leaked) {
    fail('PG-08', 'หนังสือที่ซ่อนหลุดออกหน้า public: ' . implode(',', $leaked));
} elseif ($publicTotal !== $visibleCount) {
    fail('PG-08', "ยอดรวมหน้า public = $publicTotal แต่หนังสือที่เปิดแสดงมี $visibleCount เล่ม");
} else {
    $hidden = count($hiddenIds);
    pass('PG-08', "เดินครบ $pagesToWalk หน้า ไม่มีเล่มที่ซ่อน ($hidden เล่ม) หลุด · ยอดรวมนับเฉพาะที่เปิดแสดง ($publicTotal)");
}

// ═══════════════════════════════════════════════════
// PG-09…PG-10: BorrowRepository
// ═══════════════════════════════════════════════════
echo "\n── รายการยืม (BorrowRepository) ──\n";
$borrowRepo = new BorrowRepository($pdo);

$countBorrows = $borrowRepo->countAll();
(count($borrowRepo->findAll()) === $countBorrows)
    ? pass('PG-09', "countAll() = findAll() = $countBorrows รายการ")
    : fail('PG-09', 'ยอดไม่ตรงกับรายการ');

$walk = walkAllPages(
    fn($l, $o) => $borrowRepo->findAll(['limit' => $l, 'offset' => $o]),
    $countBorrows,
    5
);
(!$walk['dupes'] && count($walk['ids']) === $countBorrows)
    ? pass('PG-10', "เดินครบ {$walk['pages']} หน้าได้ $countBorrows รายการ ไม่ซ้ำไม่ตกหล่น")
    : fail('PG-10', 'ซ้ำ ' . count($walk['dupes']) . ' · ได้ ' . count($walk['ids']) . "/$countBorrows");

// ═══════════════════════════════════════════════════
// PG-11…PG-12: UserRepository (มี HAVING → ยอดนับเสี่ยงผิด)
// ═══════════════════════════════════════════════════
echo "\n── สมาชิก (UserRepository — มี HAVING) ──\n";
$userRepo = new UserRepository($pdo);

$countMembers = $userRepo->countFilteredMembers();
(count($userRepo->findMembers()) === $countMembers)
    ? pass('PG-11', "countFilteredMembers() = findMembers() = $countMembers คน")
    : fail('PG-11', 'ยอดไม่ตรง: นับได้ ' . $countMembers . ' รายการจริง ' . count($userRepo->findMembers()));

// 🧠 เคสสำคัญ: filter ที่ใช้ HAVING บน subquery
//    ถ้าเขียน COUNT(*) ... HAVING ตรง ๆ จะได้ 0 หรือ 1 แทนจำนวนจริง
$hf = ['status' => 'has_borrow'];
$hCount = $userRepo->countFilteredMembers($hf);
$hList  = count($userRepo->findMembers($hf));
($hCount === $hList)
    ? pass('PG-12', "filter 'กำลังยืมอยู่' (HAVING) → นับได้ $hCount ตรงกับรายการจริง")
    : fail('PG-12', "HAVING ทำให้ยอดนับผิด: count=$hCount list=$hList");

// ── SUMMARY ──
$pct = $results['total'] > 0 ? round($results['passed'] / $results['total'] * 100, 1) : 0;
echo "\n══════════════════════════════════════\n";
echo " RESULTS: {$results['passed']}/{$results['total']} passed ($pct%)";
if ($results['failed'] > 0) {
    echo " | {$results['failed']} FAILED";
}
echo "\n══════════════════════════════════════\n\n";

exit($results['failed'] > 0 ? 1 : 0);
