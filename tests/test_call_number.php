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
    $idDewey = $mkBook('ค้นด้วยเลขเรียก', '999.99 ค้นเจอ');
    $html = http('GET', "$BASE_URL/admin/books.php?search=" . urlencode('999.99'));
    preg_match_all('/book_form\.php\?[^"]*\bid=(\d+)/', $html, $m);
    check('CN-C1', in_array((string) $idDewey, $m[1] ?? [], true),
        'พิมพ์เลขเรียกในช่องค้นหาแล้วเจอ — บรรณารักษ์ที่ยืนหน้าชั้นมักถือเลขเรียกมา',
        '🔴 ค้นด้วยเลขเรียกไม่เจอ');

    // C2 — เล่มที่ไม่มีเลขเรียกต้องไม่หายจากรายการ
    $idNone = $mkBook('ไม่มีเลขเรียก', null);
    $htmlAll = http('GET', "$BASE_URL/admin/books.php?search=" . urlencode('CNTEST'));
    preg_match_all('/book_form\.php\?[^"]*\bid=(\d+)/', $htmlAll, $m2);
    check('CN-C2', in_array((string) $idNone, $m2[1] ?? [], true),
        'เล่มที่ยังไม่ได้ลงเลขเรียกยังอยู่ในรายการตามปกติ',
        '🔴 เล่มที่ไม่มีเลขเรียกหายไปจากรายการ');

    // ============================================================
    // D. หน้าจอที่คนใช้เดินไปหยิบหนังสือ
    // ============================================================
    echo "\n── D. แสดงบนหน้าจอ ──\n";

    $shown = [];
    $missing = [];

    // 🖥️ หน้ารายละเอียดหนังสือ — จุดที่สมาชิกเห็นก่อนเดินไปชั้น
    $detail = http('GET', "$BASE_URL/book.php?id={$idDewey}");
    str_contains($detail, '999.99 ค้นเจอ') ? $shown[] = 'หน้ารายละเอียด' : $missing[] = 'หน้ารายละเอียด';

    // 🖥️ ตารางจัดการหนังสือ
    str_contains($html, '999.99 ค้นเจอ') ? $shown[] = 'ตารางจัดการ' : $missing[] = 'ตารางจัดการ';

    // 🖥️ หน้าพิมพ์ฉลาก
    $labels = http('GET', "$BASE_URL/admin/book_labels.php");
    str_contains($labels, '999.99 ค้นเจอ') ? $shown[] = 'หน้าพิมพ์ฉลาก' : $missing[] = 'หน้าพิมพ์ฉลาก';

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
