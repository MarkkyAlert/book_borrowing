<?php

/**
 * Search Index Tests — FULLTEXT trigram สำหรับค้นหาหนังสือ
 *
 * ==========================================================================
 * 🎯 ชุดนี้กันอะไร
 * ==========================================================================
 * การเพิ่ม FULLTEXT ทำให้การค้นหามีทางพังแบบ "เงียบ" ได้หลายทาง —
 * ระบบไม่ error แต่หาหนังสือไม่เจอ ซึ่งแย่กว่า error เพราะไม่มีใครรู้ตัว:
 *
 *  1. FULLTEXT ปกติตัดคำด้วยช่องว่าง → ภาษาไทยกลายเป็น token เดียว ค้นคำกลางไม่เจอ
 *  2. คำค้นสั้นกว่า innodb_ft_min_token_size → คืน 0 ผลลัพธ์เงียบ ๆ
 *  3. trigram มี false positive (มีชิ้นส่วนครบแต่ไม่ได้เรียงติดกัน)
 *  4. หนังสือที่ถูก INSERT ตรง ๆ ไม่ผ่าน Repository → ไม่มี token → ค้นไม่เจอตลอดกาล
 *  5. แก้ชื่อหนังสือแล้วลืมสร้าง token ใหม่ → ค้นด้วยชื่อใหม่ไม่เจอ
 *
 * ทุกข้อข้างบนมีเทสต์คุมอยู่ที่นี่
 *
 * 🧠 หลักการวัดผล: ผลลัพธ์ต้อง **ตรงกับ LIKE '%คำ%'** เป๊ะ ๆ
 *    เพราะ LIKE คือพฤติกรรมเดิมที่ผู้ใช้คุ้นอยู่แล้ว การทำ index ต้องเร็วขึ้นเฉย ๆ
 *    ห้ามเปลี่ยนว่า "ค้นแล้วเจออะไร"
 *
 * Usage: php tests/test_search_index.php
 * ⚠️ รันบน CLI เท่านั้น — สร้างหนังสือทดสอบแล้วลบทิ้งเองเมื่อจบ
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$_SESSION = [];
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF']       = 'tests/test_search_index.php';

require_once __DIR__ . '/../bootstrap.php';

use App\Repositories\BookRepository;

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

$pdo      = getDB();
$bookRepo = new BookRepository($pdo);
$TAG      = '[SIDX]';

echo "\n╔════════════════════════════════════════════════════════════╗\n";
echo "║  Search Index Tests — FULLTEXT trigram (ภาษาไทย)          ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// ── เตรียมข้อมูล ──
// 🧠 สร้างผ่าน BookRepository::create() ตั้งใจ — จะได้ทดสอบว่า repo เติม token ให้จริง
$pdo->prepare("DELETE FROM books WHERE title LIKE ?")->execute(["$TAG%"]);
$made = [];
$fixtures = [
    ['title' => "$TAG การเขียนโปรแกรมภาษาไพทอน", 'author' => 'สมชาย ใจดี',   'isbn' => 'SIDX000000001'],
    ['title' => "$TAG คู่มือ PHP ฉบับสมบูรณ์",     'author' => 'วีระ ทองดี',    'isbn' => 'SIDX000000002'],
    ['title' => "$TAG ฐานข้อมูลกันชนรถยนต์",      'author' => 'มานี รักเรียน', 'isbn' => 'SIDX000000003'],
    ['title' => "$TAG Clean Code",                'author' => 'Robert Martin', 'isbn' => 'SIDX000000004'],
];
foreach ($fixtures as $f) {
    $made[] = $bookRepo->create($f + ['quantity' => 1, 'is_visible' => 1]);
}

/** 🎯 ค้นด้วย repo (ผ่าน FULLTEXT) แล้วนับเฉพาะเล่มทดสอบ */
function searchViaRepo(BookRepository $repo, string $term, string $tag): array
{
    $rows = $repo->findAll(['search' => $term]);
    return array_values(array_filter($rows, fn($r) => str_starts_with($r['title'], $tag)));
}
/** 🎯 ค้นแบบ LIKE ล้วน = พฤติกรรมเดิมที่ต้องเทียบให้ตรง */
function searchViaLike(PDO $pdo, string $term, string $tag): array
{
    $stmt = $pdo->prepare("SELECT id FROM books
        WHERE (title LIKE ? OR author LIKE ? OR isbn LIKE ?) AND title LIKE ?");
    $stmt->execute(["%$term%", "%$term%", "%$term%", "$tag%"]);
    return $stmt->fetchAll();
}

try {
    // ═══════════════════════════════════════════════
    // SI-01: คำกลางชื่อเรื่องภาษาไทย — เคสที่ FULLTEXT ปกติพัง
    // ═══════════════════════════════════════════════
    echo "── ภาษาไทย: ค้นคำที่อยู่กลางชื่อเรื่อง ──\n";
    $n = count(searchViaRepo($bookRepo, 'โปรแกรม', $TAG));
    $n === 1
        ? pass('SI-01', 'ค้น "โปรแกรม" เจอ "การเขียนโปรแกรมภาษาไพทอน" (FULLTEXT ธรรมดาจะไม่เจอ)')
        : fail('SI-01', "ควรเจอ 1 เล่ม แต่เจอ $n — FULLTEXT อาจตัดคำแบบช่องว่าง");

    $n = count(searchViaRepo($bookRepo, 'ข้อมูล', $TAG));
    $n === 1 ? pass('SI-02', 'ค้น "ข้อมูล" เจอ "ฐานข้อมูล…"')
             : fail('SI-02', "ควรเจอ 1 เล่ม แต่เจอ $n");

    // ═══════════════════════════════════════════════
    // SI-03: สระ/วรรณยุกต์ต้องไม่ถูกทิ้ง
    // ═══════════════════════════════════════════════
    echo "\n── สระและวรรณยุกต์ ──\n";
    $withVowel = count(searchViaRepo($bookRepo, 'กัน', $TAG));
    $noVowel   = count(searchViaRepo($bookRepo, 'กน', $TAG));
    ($withVowel === 1 && $noVowel === 0)
        ? pass('SI-03', '"กัน" เจอ · "กน" ไม่เจอ — ไม้หันอากาศไม่ถูกตัดทิ้ง')
        : fail('SI-03', "กัน=$withVowel (ควร 1) · กน=$noVowel (ควร 0)");

    // ═══════════════════════════════════════════════
    // SI-04: คำค้นสั้น — ต้อง fallback ไม่ใช่คืน 0 เงียบ ๆ
    // ═══════════════════════════════════════════════
    echo "\n── คำค้นสั้นกว่า " . SEARCH_TOKEN_SIZE . " ตัวอักษร ──\n";
    $short = ['ก', 'ph', 'โค'];
    $ok = true; $detail = [];
    foreach ($short as $term) {
        $viaRepo = count(searchViaRepo($bookRepo, $term, $TAG));
        $viaLike = count(searchViaLike($pdo, $term, $TAG));
        $detail[] = "\"$term\"=$viaRepo/$viaLike";
        if ($viaRepo !== $viaLike) $ok = false;
    }
    $ok ? pass('SI-04', 'คำสั้นตกไปใช้ LIKE ได้ผลเท่าเดิม (' . implode(' · ', $detail) . ')')
        : fail('SI-04', 'คำสั้นได้ผลไม่ตรงกับ LIKE: ' . implode(' · ', $detail));

    // ═══════════════════════════════════════════════
    // SI-05: false positive ของ trigram ต้องถูกกรองทิ้ง
    // ═══════════════════════════════════════════════
    echo "\n── ความแม่นยำ (false positive) ──\n";
    // 🧠 "โปรแกรมภาษา" มีครบทุกชิ้นใน "การเขียนโปรแกรมภาษาไพทอน" และเรียงติดกันจริง → ต้องเจอ
    //    ส่วน "ไพทอนโปรแกรม" มีชิ้นส่วนอยู่ในเล่มเดียวกันแต่สลับที่ → ต้องไม่เจอ
    $hit  = count(searchViaRepo($bookRepo, 'โปรแกรมภาษา', $TAG));
    $miss = count(searchViaRepo($bookRepo, 'ไพทอนโปรแกรม', $TAG));
    ($hit === 1 && $miss === 0)
        ? pass('SI-05', 'เรียงติดกันจริง=เจอ · ชิ้นส่วนสลับที่=ไม่เจอ (LIKE กรอง false positive ให้)')
        : fail('SI-05', "เรียงติดกัน=$hit (ควร 1) · สลับที่=$miss (ควร 0)");

    // ═══════════════════════════════════════════════
    // SI-06: ผลต้องตรงกับ LIKE ทุกคำค้น
    // ═══════════════════════════════════════════════
    echo "\n── ผลลัพธ์ต้องตรงกับพฤติกรรมเดิม (LIKE) ──\n";
    $terms = ['โปรแกรม','ข้อมูล','ไพทอน','สมชาย','PHP','clean','code','martin','SIDX000000003','ไม่มีคำนี้แน่นอน'];
    $diff = [];
    foreach ($terms as $term) {
        $a = count(searchViaRepo($bookRepo, $term, $TAG));
        $b = count(searchViaLike($pdo, $term, $TAG));
        if ($a !== $b) $diff[] = "\"$term\" FT=$a LIKE=$b";
    }
    empty($diff)
        ? pass('SI-06', 'ตรงกันทั้ง ' . count($terms) . ' คำค้น (ไทย/อังกฤษ/ISBN/ไม่เจอ)')
        : fail('SI-06', 'ต่างกัน: ' . implode(' · ', $diff));

    // ═══════════════════════════════════════════════
    // SI-07: แก้ชื่อหนังสือแล้ว index ต้องตามไปด้วย
    // ═══════════════════════════════════════════════
    echo "\n── แก้ข้อมูลแล้ว index ต้องอัปเดตตาม ──\n";
    $target = $made[3];  // Clean Code
    $bookRepo->update($target, [
        'title' => "$TAG หนังสือชื่อใหม่เอี่ยม", 'author' => 'ผู้แต่งคนใหม่',
        'isbn' => 'SIDX000000004', 'quantity' => 1, 'available' => 1, 'is_visible' => 1,
    ]);
    $newFound = count(searchViaRepo($bookRepo, 'ชื่อใหม่', $TAG));
    $oldFound = count(searchViaRepo($bookRepo, 'clean', $TAG));
    ($newFound === 1 && $oldFound === 0)
        ? pass('SI-07', 'ค้นชื่อใหม่เจอ · ชื่อเก่าไม่เจอ — update() สร้าง token ใหม่ให้จริง')
        : fail('SI-07', "ชื่อใหม่=$newFound (ควร 1) · ชื่อเก่า=$oldFound (ควร 0)");

    // ═══════════════════════════════════════════════
    // SI-08: ไม่มีหนังสือเล่มไหนตกหล่นจาก index
    // ═══════════════════════════════════════════════
    echo "\n── ไม่มีเล่มไหนตกหล่น (กันข้อมูลที่ INSERT ตรง ๆ) ──\n";
    $missing = (int) $pdo->query("SELECT COUNT(*) FROM books WHERE search_tokens IS NULL OR search_tokens = ''")->fetchColumn();
    $total   = (int) $pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
    $missing === 0
        ? pass('SI-08', "หนังสือทั้ง $total เล่มมี index ค้นหาครบ")
        : fail('SI-08', "มี $missing เล่มจาก $total ที่ไม่มี index → ค้นหาไม่เจอ · แก้ด้วย php database/rebuild_search_index.php");

} finally {
    // ── TEARDOWN ── ลบหนังสือทดสอบทิ้งเสมอ แม้เทสต์จะพัง
    $pdo->prepare("DELETE FROM books WHERE title LIKE ?")->execute(["$TAG%"]);
}

$pct = $results['total'] > 0 ? round($results['passed'] / $results['total'] * 100, 1) : 0;
echo "\n══════════════════════════════════════\n";
echo " RESULTS: {$results['passed']}/{$results['total']} passed ($pct%)";
if ($results['failed'] > 0) echo " | {$results['failed']} FAILED";
echo "\n══════════════════════════════════════\n\n";

exit($results['failed'] > 0 ? 1 : 0);
