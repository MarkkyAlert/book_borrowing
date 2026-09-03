<?php

/**
 * เลขเรียกหนังสือ — "ที่อยู่" ของหนังสือบนชั้น
 *
 * ==========================================================================
 * 🔴 ปัญหาเดิม
 * ==========================================================================
 * ตาราง `books` ไม่มีฟิลด์บอกตำแหน่งบนชั้นเลย
 * ผลคือค้นเจอว่า "มีเล่มนี้ · ว่าง 2 เล่ม" แล้ว **เดินไปหยิบไม่ถูก**
 * ห้องสมุดเกินพันเล่ม บรรณารักษ์ต้องจำเองหรือเดินไล่ดูทีละชั้น
 *
 * 🧠 คนละเรื่องกับ ISBN:
 *    ISBN     → นี่คือหนังสือเรื่องอะไร (ทั้งโลกใช้เลขเดียวกัน)
 *    เลขเรียก → อยู่ชั้นไหนใน **ห้องสมุดนี้** (แต่ละแห่งกำหนดเอง)
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. บันทึก/แก้/ล้างค่าได้ครบวงจร — 🔴 รวมกรณี "แก้แล้วค่าหาย" ที่เจอจริงตอนทำ
 * B. 🔴 รูปแบบอิสระ — ดิวอี้ / ก-01-03 / A12 ต้องใช้ได้หมด ห้ามบังคับรูปแบบ
 * C. ค้นหาด้วยเลขเรียกได้ · เล่มที่ไม่มีเลขเรียกต้องไม่พัง
 * D. แสดงบนหน้าจอที่คนใช้เดินไปหยิบหนังสือ
 * E. 🔴 ไฟล์นำเข้าเดิมของลูกค้า (6 คอลัมน์) ต้องยังทำงานเหมือนเดิม
 * F. ฉลากบาร์โค้ด — มีเลขเรียก และ **บาร์โค้ดต้องไม่หายไป**
 *
 * 🧹 ลบหนังสือที่สร้างขึ้นทั้งหมด
 *
 * 📌 การใช้งาน: php tests/test_call_number.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/BookService.php';

$ROOT           = dirname(__DIR__);
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
$COOKIE = tempnam(sys_get_temp_dir(), 'bbcn');

$madeBooks = [];
$cleanupDone = false;
$cleanup = function () use (&$madeBooks, &$cleanupDone, $pdo, $COOKIE) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    try {
        if ($pdo->inTransaction()) $pdo->rollBack();
    } catch (Throwable $e) {
        echo '  ⚠️ rollback ไม่สำเร็จ: ' . $e->getMessage() . "\n";
    }
    $failed = [];
    foreach ($madeBooks as $bid) {
        try { $pdo->prepare("DELETE FROM books WHERE id = ?")->execute([(int) $bid]); }
        catch (Throwable $e) { $failed[] = "book#{$bid}"; }
    }
    // 🧹 กวาดตามป้าย — แถวที่เกิดจากการนำเข้าไฟล์ไม่ได้ถูกจำไว้ทีละตัว
    //    (บทเรียนจาก test_closed_days: แถวที่สร้างผ่าน HTTP ค้างในฐานข้อมูล)
    try {
        $swept = $pdo->exec("DELETE FROM books WHERE title LIKE '%[CNTEST]%'");
        if ($swept > 0) echo "  🧹 กวาดหนังสือที่ติดป้าย [CNTEST] เพิ่มอีก {$swept} เล่ม\n";
    } catch (Throwable $e) { $failed[] = 'กวาด [CNTEST]'; }

    echo '  ลบหนังสือ ' . count($madeBooks) . " เล่ม\n";
    if ($failed) echo '  🔴 ลบไม่สำเร็จ: ' . implode(' · ', $failed) . "\n";

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
echo "║  เลขเรียกหนังสือ — ที่อยู่ของหนังสือบนชั้น                 ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

function http(string $method, string $url, array $fields = [], array $files = []): string
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
        if ($files) {
            foreach ($files as $k => $path) $fields[$k] = new CURLFile($path, 'text/csv', basename($path));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
    }
    $body = (string) curl_exec($ch);
    curl_close($ch);
    return $body;
}

function csrfFrom(string $html): string
{
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

$bookService = new \App\Services\BookService($pdo);
$catId = (int) $pdo->query("SELECT id FROM categories LIMIT 1")->fetchColumn();
$uniq  = substr((string) getmypid(), -4) . mt_rand(100, 999);

$mkBook = function (string $tag, ?string $call) use ($bookService, $catId, $uniq, &$madeBooks): int {
    $id = (int) $bookService->createBook([
        'title' => "[CNTEST] {$tag} {$uniq}", 'author' => 'ผู้แต่งทดสอบ',
        'category_id' => $catId, 'quantity' => 1, 'isbn' => null,
        'call_number' => $call,
    ]);
    $madeBooks[] = $id;
    return $id;
};
$callOf = fn(int $id) => $pdo->query("SELECT call_number FROM books WHERE id = {$id}")->fetchColumn();

// ============================================================
// A. บันทึก / แก้ / ล้างค่า
// ============================================================
echo "── A. บันทึก แก้ไข ล้างค่า ──\n";

$bookA = $mkBook('บันทึก', '371.3 ส236ค');
check('CN-A1', $callOf($bookA) === '371.3 ส236ค',
    'บันทึกเลขเรียกตอนสร้างหนังสือได้',
    '🔴 ได้ค่า: ' . var_export($callOf($bookA), true));

// A2 — 🔴 เจอจริงตอนทำ: Service สร้าง array ใหม่ทีละ key
//      คีย์ที่ไม่ได้ระบุจะตกหล่นเงียบ ๆ → แก้ไขแล้วเลขเรียกหายทุกครั้ง
$bookService->updateBook($bookA, [
    'title' => "[CNTEST] บันทึก {$uniq}", 'author' => 'ผู้แต่งทดสอบ',
    'category_id' => $catId, 'quantity' => 1, 'isbn' => null,
    'call_number' => 'ก-01-03',
]);
check('CN-A2', $callOf($bookA) === 'ก-01-03',
    '🔴 แก้ไขหนังสือแล้วเลขเรียกไม่หาย (Service ส่งค่าต่อครบ)',
    '🔴 แก้ไขแล้วได้: ' . var_export($callOf($bookA), true)
        . ' — Service ไม่ได้ส่ง call_number ต่อไป Repository');

// A3 — ล้างค่าแล้วต้องเป็น NULL ไม่ใช่ ''
$bookService->updateBook($bookA, [
    'title' => "[CNTEST] บันทึก {$uniq}", 'author' => 'ผู้แต่งทดสอบ',
    'category_id' => $catId, 'quantity' => 1, 'isbn' => null,
    'call_number' => null,
]);
check('CN-A3', $callOf($bookA) === null,
    'ล้างเลขเรียกแล้วเป็น NULL ไม่ใช่สตริงว่าง',
    '🔴 ได้: ' . var_export($callOf($bookA), true)
        . ' — ถ้ามีทั้ง "" และ NULL ปนกัน การค้นหา/กรองจะได้ผลไม่ครบ (บทเรียนจาก F-48)');

// A4 — 🔴 ล้างค่า **ผ่านฟอร์ม** (ส่งช่องว่าง) ก็ต้องได้ NULL ไม่ใช่ ''
//      ทางฟอร์มเป็นคนละเส้นกับทาง Service — ฟอร์มส่ง '' มา ต้องมีคนแปลงเป็น null
//      ถ้าไม่แปลง จะมีทั้งแถวที่เป็น '' และ NULL ปนกัน (บทเรียนจาก F-48 กับ ISBN)
$formPathBook = $mkBook('ล้างผ่านฟอร์ม', 'ZZ-99');
$__loginA4 = http('GET', "$BASE_URL/login.php");
http('POST', "$BASE_URL/login.php", [
    'csrf_token' => csrfFrom($__loginA4), 'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD,
]);
$editPage = http('GET', "$BASE_URL/admin/book_form.php?id={$formPathBook}");
http('POST', "$BASE_URL/admin/book_form.php", [
    'csrf_token'  => csrfFrom($editPage),
    'id'          => $formPathBook,
    'title'       => "[CNTEST] ล้างผ่านฟอร์ม {$uniq}",
    'author'      => 'ผู้แต่งทดสอบ',
    'isbn'        => '',
    'call_number' => '',            // ← ผู้ใช้ลบข้อความในช่องแล้วกดบันทึก
    'category_id' => $catId,
    'quantity'    => 1,
    'price'       => '',
    'is_visible'  => 1,
]);
$afterForm = $callOf($formPathBook);
check('CN-A4', $afterForm === null,
    'ล้างเลขเรียกผ่านฟอร์มแล้วได้ NULL (ฟอร์มแปลง "" เป็น null ให้)',
    '🔴 ได้: ' . var_export($afterForm, true)
        . ' — จะมีทั้ง "" และ NULL ปนกันในตาราง ค้นหา/กรองแล้วได้ผลไม่ครบ');

// ============================================================
// B. 🔴 รูปแบบอิสระ
// ============================================================
echo "\n── B. รูปแบบอิสระ ──\n";

// 🧠 ห้องสมุดเล็กจำนวนมากไม่ใช้ดิวอี้ ใช้รหัสของตัวเอง
//    บังคับรูปแบบ = ลูกค้าครึ่งหนึ่งใช้ไม่ได้
$formats = [
    'ดิวอี้เต็มรูป'   => '371.3 ส236ค 2565',
    'ตู้-ชั้น-ช่อง'   => 'ก-01-03',
    'รหัสสั้น'        => 'A12',
    'ไทยล้วน'         => 'นิยาย-045',
    'มีจุดหลายชั้น'   => '895.911 ก123ก',
];
$rejected = [];
foreach ($formats as $label => $code) {
    $id = $mkBook("รูปแบบ {$label}", $code);
    if ($callOf($id) !== $code) $rejected[] = "{$label} ({$code}) → " . var_export($callOf($id), true);
}
check('CN-B1', $rejected === [],
    'รับได้ทุกรูปแบบที่ห้องสมุดจริงใช้ ' . count($formats) . ' แบบ (' . implode(' · ', array_keys($formats)) . ')',
    '🔴 ถูกปฏิเสธ/เพี้ยน: ' . implode(' · ', $rejected));

// B2 — ความยาวเกินต้องไม่ทำให้ข้อมูลพัง
$longCode = str_repeat('9', 80);
$idLong = $mkBook('เลขยาวเกิน', mb_substr($longCode, 0, 50));
check('CN-B2', mb_strlen((string) $callOf($idLong)) <= 50,
    'ความยาวถูกจำกัดที่ 50 ตัวอักษร ไม่ล้นคอลัมน์',
    '🔴 เก็บได้ ' . mb_strlen((string) $callOf($idLong)) . ' ตัวอักษร');

// ============================================================
// C. ค้นหา
// ============================================================
echo "\n── C. ค้นหาด้วยเลขเรียก ──\n";

$login = http('GET', "$BASE_URL/login.php");
$in = http('POST', "$BASE_URL/login.php", [
    'csrf_token' => csrfFrom($login), 'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD,
]);
$loggedIn = str_contains($in, 'ออกจากระบบ') || str_contains($in, 'logout');
if (!$loggedIn) {
    fail('CN-C0', 'ล็อกอินไม่สำเร็จ — ข้ามหมวด C/D/E/F');
} else {
    /**
     * 🔴 ต้องทดสอบ **ทุกรูปแบบเลขเรียก** ไม่ใช่แค่ตัวเลขล้วน
     *
     * 🧠 เดิมข้อนี้ทดสอบด้วย '999.99' อย่างเดียวแล้วเขียวมาตลอด
     *    แต่ '999.99' เป็นทางที่รอดพอดี — buildSearchBooleanQuery() คืน null
     *    เมื่อสัดส่วนตัวเลข ≥ 70% ระบบจึงตกไปใช้ LIKE ล้วนซึ่งมี call_number อยู่
     *    ส่วนเลขแบบ LC ('PZ7.R79' ตัวเลข ~43%) ถูกบังคับให้ MATCH กับ search_tokens
     *    ที่ตอนนั้นยังไม่มี call_number → ได้ 0 แถว → AND ตัดทิ้ง → ค้นไม่เจอ
     *    ห้องสมุดที่ใช้ LC หรือดิวอี้ผสมอักษรผู้แต่งจะเจอปัญหานี้ทั้งระบบ
     */
    $findIds = function (string $term) use ($BASE_URL) {
        $html = http('GET', "$BASE_URL/admin/books.php?search=" . urlencode($term));
        preg_match_all('/book_form\.php\?[^"]*\bid=(\d+)/', $html, $mm);
        return $mm[1] ?? [];
    };

    $callFormats = [
        'ตัวเลขล้วน (ดิวอี้)'   => '999.99',
        'LC (อักษรละตินปน)'     => 'PZ7.R79',
        'ดิวอี้ + อักษรผู้แต่ง' => '823.914 R79',
        'เลขไทย'                => 'ก123 น62',
    ];
    $notFound   = [];
    $idDewey    = 0;    // 📌 ข้อ D ใช้เล่มนี้ต่อ — เก็บของแบบแรกไว้
    $deweyCode  = '';
    $html       = '';
    foreach ($callFormats as $label => $code) {
        $bid = $mkBook('ค้นเลขเรียก ' . $label, $code);
        $ids = $findIds($code);
        if ($idDewey === 0) {
            $idDewey   = $bid;
            $deweyCode = $code;
            $html      = http('GET', "$BASE_URL/admin/books.php?search=" . urlencode($code));
        }
        if (!in_array((string) $bid, $ids, true)) {
            $notFound[] = "{$label} ({$code})";
        }
    }
    check('CN-C1', $notFound === [],
        'ค้นเจอทุกรูปแบบเลขเรียกที่ห้องสมุดจริงใช้ ' . count($callFormats) . ' แบบ '
            . '— บรรณารักษ์ที่ยืนหน้าชั้นมักถือเลขเรียกมา',
        '🔴 ค้นไม่เจอ: ' . implode(' · ', $notFound)
            . "\n       (ถ้าล้มเฉพาะแบบที่มีอักษรละติน ให้ดู makeSearchTokens() ว่ารวม call_number หรือยัง"
            . "\n        และรัน database/rebuild_search_index.php --all)");

    // C2 — เล่มที่ไม่มีเลขเรียกต้องไม่หายจากรายการ
    $idNone = $mkBook('ไม่มีเลขเรียก', null);
    $htmlAll = http('GET', "$BASE_URL/admin/books.php?search=" . urlencode('CNTEST'));
    preg_match_all('/book_form\.php\?[^"]*\bid=(\d+)/', $htmlAll, $m2);
    check('CN-C2', in_array((string) $idNone, $m2[1] ?? [], true),
        'เล่มที่ยังไม่ได้ลงเลขเรียกยังอยู่ในรายการตามปกติ',
        '🔴 เล่มที่ไม่มีเลขเรียกหายไปจากรายการ');

    /**
     * 🔴 กัน regression — การค้นภาษาไทยต้องไม่กว้างขึ้น
     *
     * 🧠 ทางแก้ที่ง่ายกว่าคือเปลี่ยน AND เป็น OR ใน buildListQuery()
     *    ซึ่งจะทำให้ค้นเลขเรียกเจอเหมือนกัน แต่ผลการค้น **ทั้งระบบ** จะกว้างขึ้น
     *    กลับไปเหมือนก่อนมี FULLTEXT (trigram ถูกใส่มาเพื่อความแม่นของภาษาไทย)
     *    ข้อนี้เฝ้าไว้ว่าถ้าใครแก้เป็น OR ในอนาคต จะเห็นทันที
     */
    $idThai   = $mkBook('หนังสือชื่อไทยเฉพาะ ' . $uniq, null);
    $hitExact = $findIds('หนังสือชื่อไทยเฉพาะ ' . $uniq);

    /**
     * 🔴 ส่วนนี้ตรวจที่ **ซอร์สโค้ด** ไม่ใช่พฤติกรรม — จงใจ
     *
     * 🧠 ลองทำเป็นเทสต์เชิงพฤติกรรมก่อนแล้วใช้ไม่ได้จริง: เปลี่ยน AND เป็น OR
     *    แล้วรันด้วยคำไทยจริง (ประวัติ · วิทยา · ความรัก) ได้จำนวนผลลัพธ์ **เท่ากันทุกคำ**
     *    เพราะบนข้อมูลชุดนี้ trigram กับ substring บังเอิญเห็นตรงกัน
     *    เทสต์ที่ผ่านทั้งสองแบบคือเทสต์ที่ไม่ได้ตรวจอะไร จึงเปลี่ยนมาตรวจโครงสร้างแทน
     *    (ผลต่างจะโผล่ก็ต่อเมื่อข้อมูลลูกค้าโตกว่านี้ — ตอนนั้นสายเกินไปแล้ว)
     */
    $repoSrc  = (string) file_get_contents(__DIR__ . '/../app/Repositories/BookRepository.php');
    $joinsAnd = (bool) preg_match("/implode\(\s*' AND '\s*,\s*\\\$where\s*\)/", $repoSrc);

    check('CN-C3', in_array((string) $idThai, $hitExact, true) && $joinsAnd,
        'ค้นไทยคำเต็มยังเจอ (' . count($hitExact) . ' รายการ) '
            . 'และ buildListQuery() ยังรวมเงื่อนไขด้วย AND — ความแม่นของการค้นไทยไม่ถูกลดทอน',
        '🔴 ' . (!$joinsAnd
            ? "buildListQuery() ไม่ได้รวม \$where ด้วย AND แล้ว\n"
              . "       ถ้าเปลี่ยนเป็น OR เพื่อให้ค้นเลขเรียกเจอ = แก้บั๊กเล็กแล้วทำของใหญ่พัง\n"
              . "       ผลการค้นภาษาไทยทั้งระบบจะกว้างขึ้นกลับไปเหมือนก่อนมี FULLTEXT\n"
              . "       ทางที่ถูกคือใส่ call_number ลง makeSearchTokens() แล้ว rebuild"
            : 'ค้นไทยคำเต็มไม่เจอ — ได้ ' . count($hitExact) . ' รายการ'));

    /**
     * 🔴 ทุกเล่มที่มีเลขเรียก ต้องมี trigram ของเลขนั้นใน search_tokens
     *
     * 🧠 จับกรณีลูกค้าอัปเกรดโค้ดแล้ว migration ไม่ได้รัน (หรือรันไม่ครบ)
     *    search_tokens เป็นค่าที่คำนวณไว้ตอนบันทึก ไม่ได้คำนวณใหม่ตอนค้นหา
     *    ถ้าไม่ rebuild หนังสือเก่าจะยังค้นด้วยเลขเรียกไม่เจอ ทั้งที่โค้ดถูกแล้ว
     */
    $stale = (int) $pdo->query("
        SELECT COUNT(*) FROM books
        WHERE call_number IS NOT NULL AND call_number <> ''
          AND (search_tokens IS NULL OR search_tokens = '')
    ")->fetchColumn();
    $sample = $pdo->query("
        SELECT call_number, search_tokens FROM books
        WHERE call_number IS NOT NULL AND call_number <> '' LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);
    $missingTok = 0;
    foreach ($sample as $row) {
        $first = explode(' ', buildSearchTokens($row['call_number']))[0] ?? '';
        if ($first !== '' && !str_contains((string) $row['search_tokens'], $first)) {
            $missingTok++;
        }
    }
    check('CN-C4', $stale === 0 && $missingTok === 0,
        'หนังสือที่มีเลขเรียกทุกเล่มมี trigram ของเลขนั้นใน search_tokens แล้ว '
            . '(สุ่มตรวจ ' . count($sample) . ' เล่ม)',
        "🔴 tokens ยังไม่ได้สร้างใหม่: ว่างเปล่า {$stale} เล่ม · ขาด trigram ของเลขเรียก {$missingTok} เล่ม\n"
            . '       ต้องรัน `php database/migrate.php` หรือ `php database/rebuild_search_index.php --all`');

    // ============================================================
    // D. หน้าจอที่คนใช้เดินไปหยิบหนังสือ
    // ============================================================
    echo "\n── D. แสดงบนหน้าจอ ──\n";

    $shown = [];
    $missing = [];

    // 🖥️ หน้ารายละเอียดหนังสือ — จุดที่สมาชิกเห็นก่อนเดินไปชั้น
    $detail = http('GET', "$BASE_URL/book.php?id={$idDewey}");
    str_contains($detail, $deweyCode) ? $shown[] = 'หน้ารายละเอียด' : $missing[] = 'หน้ารายละเอียด';

    // 🖥️ ตารางจัดการหนังสือ
    str_contains($html, $deweyCode) ? $shown[] = 'ตารางจัดการ' : $missing[] = 'ตารางจัดการ';

    // 🖥️ หน้าพิมพ์ฉลาก
    $labels = http('GET', "$BASE_URL/admin/book_labels.php");
    str_contains($labels, $deweyCode) ? $shown[] = 'หน้าพิมพ์ฉลาก' : $missing[] = 'หน้าพิมพ์ฉลาก';

    check('CN-D1', $missing === [],
        'แสดงเลขเรียกครบทุกหน้าที่ต้องใช้ (' . implode(' · ', $shown) . ')',
        '🔴 ไม่แสดงที่: ' . implode(' · ', $missing));

    // D2 — 🔴 หน้ารายละเอียดต้องบอกว่านี่คือ "ที่อยู่บนชั้น" ไม่ใช่โชว์ตัวเลขลอย ๆ
    //      สมาชิกทั่วไปไม่รู้จักคำว่า "เลขเรียกหนังสือ" ถ้าไม่อธิบาย
    check('CN-D2', str_contains($detail, 'อยู่ที่ชั้น'),
        'หน้ารายละเอียดบอกว่าเลขนี้คือตำแหน่งบนชั้น ไม่ใช่โชว์ตัวเลขเฉย ๆ',
        '🔴 โชว์เลขโดยไม่บอกว่าคืออะไร — สมาชิกทั่วไปไม่รู้จักคำว่า "เลขเรียกหนังสือ"');

    // ============================================================
    // E. 🔴 ไฟล์นำเข้าเดิมต้องยังทำงาน
    // ============================================================
    echo "\n── E. ไฟล์นำเข้า ──\n";

    $importPage = http('GET', "$BASE_URL/admin/import_books.php");

    // E1 — ไฟล์รูปแบบ **เดิม** 6 คอลัมน์ (ไม่มี CallNumber)
    $oldCsv = tempnam(sys_get_temp_dir(), 'cnold') . '.csv';
    file_put_contents($oldCsv,
        "Title,Author,ISBN,Category,Quantity,Reference\n"
        . "[CNTEST] ไฟล์เก่า {$uniq},ผู้แต่ง,,ทั่วไป,2,0\n");
    http('POST', "$BASE_URL/admin/import_books.php",
        ['csrf_token' => csrfFrom($importPage)], ['csv_file' => $oldCsv]);
    @unlink($oldCsv);

    $oldRow = $pdo->query("
        SELECT quantity, call_number FROM books WHERE title LIKE '%ไฟล์เก่า {$uniq}%' LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    check('CN-E1', $oldRow && (int) $oldRow['quantity'] === 2 && $oldRow['call_number'] === null,
        '🔴 ไฟล์นำเข้ารูปแบบเดิม (6 คอลัมน์) ยังทำงานเหมือนเดิม — ลูกค้าที่มีไฟล์อยู่แล้วไม่ต้องแก้',
        '🔴 ไฟล์เดิมพัง: ' . json_encode($oldRow, JSON_UNESCAPED_UNICODE));

    // E2 — ไฟล์รูปแบบใหม่ 7 คอลัมน์
    $newCsv = tempnam(sys_get_temp_dir(), 'cnnew') . '.csv';
    file_put_contents($newCsv,
        "Title,Author,ISBN,Category,Quantity,Reference,CallNumber\n"
        . "[CNTEST] ไฟล์ใหม่ {$uniq},ผู้แต่ง,,ทั่วไป,1,0,808.8 ก111ก\n");
    $importPage2 = http('GET', "$BASE_URL/admin/import_books.php");
    http('POST', "$BASE_URL/admin/import_books.php",
        ['csrf_token' => csrfFrom($importPage2)], ['csv_file' => $newCsv]);
    @unlink($newCsv);

    $newCall = $pdo->query("
        SELECT call_number FROM books WHERE title LIKE '%ไฟล์ใหม่ {$uniq}%' LIMIT 1
    ")->fetchColumn();
    check('CN-E2', $newCall === '808.8 ก111ก',
        'ไฟล์นำเข้ารูปแบบใหม่บันทึกเลขเรียกได้',
        '🔴 ได้: ' . var_export($newCall, true));

    // E3 — ไฟล์ตัวอย่างที่ให้ดาวน์โหลดต้องตรงกับที่ระบบอ่าน
    check('CN-E3',
        str_contains($importPage, 'CallNumber'),
        'หน้านำเข้าบอกรูปแบบคอลัมน์ใหม่ให้ลูกค้าทราบ',
        '🔴 หน้านำเข้ายังบอกรูปแบบเดิม — ลูกค้าจะไม่รู้ว่าใส่เลขเรียกได้');

    // ============================================================
    // F. ฉลากบาร์โค้ด
    // ============================================================
    echo "\n── F. ฉลากบาร์โค้ด ──\n";

    // F1 — ข้อมูลเลขเรียกถูกส่งไปให้ตัวสร้างฉลาก
    check('CN-F1', preg_match('/data-call="999\.99[^"]*"/', $labels) === 1,
        'ฉลากได้รับเลขเรียกไปด้วย (data-call)',
        '🔴 ตัวสร้างฉลากไม่ได้รับเลขเรียก — สติกเกอร์ที่ติดสันหนังสือจะไม่มีเลขเรียก '
            . 'ซึ่งเป็นที่ที่มันต้องอยู่ที่สุด');

    // F2 — 🔴 บาร์โค้ดต้องไม่หายไป
    //      ฉลากมีพื้นที่จำกัด (60×30mm) ถ้าใส่เลขเรียกแล้วดันบาร์โค้ดหลุด
    //      = ทำพังของที่ใช้งานได้อยู่ สแกนไม่ติดทั้งห้องสมุด
    check('CN-F2',
        str_contains($labels, 'JsBarcode') && str_contains($labels, "svgEl"),
        'ตัวสร้างบาร์โค้ดยังอยู่ครบ',
        '🔴 บาร์โค้ดหายไปจากฉลาก');

    // F3 — เลขเรียกต้องอยู่ก่อนบาร์โค้ด (อ่านง่ายตอนมองสันหนังสือ)
    $posCall = strpos($labels, "callEl.className = 'call'");
    $posBar  = strpos($labels, "svgEl.setAttribute");
    check('CN-F3', $posCall !== false && $posBar !== false && $posCall < $posBar,
        'เลขเรียกถูกวางก่อนบาร์โค้ดบนฉลาก',
        '🔴 ลำดับบนฉลากไม่ถูก');
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
