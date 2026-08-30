<?php

/**
 * หมายเหตุรายเล่ม — สมุดจดของเจ้าหน้าที่
 *
 * ==========================================================================
 * 🔴 ที่มา: ทางเลือกแทน "เลขทะเบียนรายเล่ม" ที่ประเมินแล้วว่าไม่คุ้ม
 * ==========================================================================
 * ระบบเก็บหนังสือเป็น "ชื่อเรื่อง + จำนวนนับ" ตามรายเล่มไม่ได้
 * ของจริงต้องมีตาราง `book_copies` + เปลี่ยน invariant สต็อกทั้งระบบ
 * ซึ่งประเมินแล้วว่า **ข้อมูลเก่ากู้ไม่ได้** (borrows 2,000 แถวไม่รู้ว่าเล่มไหน)
 * เจ้าของจึงเลือกทางที่ใช้แรง 5% — ช่องจดข้อความอิสระ ไม่แตะสต็อกเลย
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. บันทึก / แก้ / ล้างค่าได้ครบวงจร (รวมข้อความหลายบรรทัด)
 * B. 🔴 **ห้ามรั่วออกหน้าสาธารณะ** — เป็นบันทึกภายในของเจ้าหน้าที่
 * C. ฝั่งผู้ดูแลต้องเห็น และต้องมีคำเตือนว่านี่ไม่ใช่ระบบตามรายเล่ม
 * D. 🔴 ต้องไม่แตะสต็อก — เขียนหมายเหตุแล้ว available/quantity ต้องเท่าเดิม
 *
 * 🧹 ลบหนังสือที่สร้างขึ้นทั้งหมด
 *
 * 📌 การใช้งาน: php tests/test_copy_notes.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
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

$pdo       = getDB();
$COOKIE    = tempnam(sys_get_temp_dir(), 'bbcn2');
$madeBooks = [];
$cleanupDone = false;

$cleanup = function () use (&$madeBooks, &$cleanupDone, $pdo, $COOKIE) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $e) {}
    foreach ($madeBooks as $id) {
        try { $pdo->prepare("DELETE FROM books WHERE id = ?")->execute([(int) $id]); } catch (Throwable $e) {}
    }
    try {
        $n = $pdo->exec("DELETE FROM books WHERE title LIKE '%[CN2TEST]%'");
        if ($n > 0) echo "  🧹 กวาดหนังสือที่ติดป้าย [CN2TEST] อีก {$n} เล่ม\n";
    } catch (Throwable $e) {}
    echo '  ลบหนังสือ ' . count($madeBooks) . " เล่ม\n";
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
    } catch (Throwable $e) {}
    @unlink($COOKIE);
};
register_shutdown_function($cleanup);

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  หมายเหตุรายเล่ม — สมุดจดของเจ้าหน้าที่                    ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

function http(string $method, string $url, array $fields = []): string
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
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = (string) curl_exec($ch);
    curl_close($ch);
    return $body;
}

/** 🔴 ยิงแบบ **ไม่มี session** — ต้องใช้กับข้อ B ไม่งั้นจะได้หน้าฝั่งผู้ดูแลมาแทน */
function httpAnon(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
    $body = (string) curl_exec($ch);
    curl_close($ch);
    return $body;
}

function csrfFrom(string $html): string
{
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

$svc   = new \App\Services\BookService($pdo);
$catId = (int) $pdo->query("SELECT id FROM categories LIMIT 1")->fetchColumn();
$uniq  = substr((string) getmypid(), -4) . mt_rand(100, 999);
$noteOf = fn(int $id) => $pdo->query("SELECT copy_notes FROM books WHERE id = {$id}")->fetchColumn();

// 🔎 ข้อความที่ค้นเจอง่าย — ถ้ารั่วออกหน้าสาธารณะจะจับได้แน่
$SECRET = "เล่ม 2 ปกขาด\nเล่ม 3 หาย {$uniq} ส.ค.";

// ============================================================
// A. บันทึก / แก้ / ล้างค่า
// ============================================================
echo "── A. บันทึก แก้ไข ล้างค่า ──\n";

$bookId = (int) $svc->createBook([
    'title' => "[CN2TEST] หนังสือทดสอบ {$uniq}", 'author' => 'ผู้แต่งทดสอบ',
    'category_id' => $catId, 'quantity' => 3, 'copy_notes' => $SECRET,
]);
$madeBooks[] = $bookId;

check('CN2-A1', $noteOf($bookId) === $SECRET,
    'บันทึกหมายเหตุตอนสร้างหนังสือได้ (รวมข้อความหลายบรรทัด)',
    '🔴 ได้: ' . var_export($noteOf($bookId), true));

// 🔴 บทเรียนจาก call_number: Service สร้าง array ใหม่ทีละ key
//    คีย์ที่ไม่ได้ระบุจะตกหล่นเงียบ ๆ → แก้ไขหนังสือแล้วหมายเหตุหาย
$svc->updateBook($bookId, [
    'title' => "[CN2TEST] หนังสือทดสอบ {$uniq}", 'author' => 'ผู้แต่งทดสอบ',
    'category_id' => $catId, 'quantity' => 3, 'copy_notes' => 'เล่ม 1 ใหม่เอี่ยม',
]);
check('CN2-A2', $noteOf($bookId) === 'เล่ม 1 ใหม่เอี่ยม',
    'แก้ไขหนังสือแล้วหมายเหตุถูกส่งต่อ ไม่หาย',
    '🔴 ได้: ' . var_export($noteOf($bookId), true) . ' — Service ตกคีย์ copy_notes');

$svc->updateBook($bookId, [
    'title' => "[CN2TEST] หนังสือทดสอบ {$uniq}", 'author' => 'ผู้แต่งทดสอบ',
    'category_id' => $catId, 'quantity' => 3,
]);
check('CN2-A3', $noteOf($bookId) === null,
    'ไม่ส่งค่ามา → เก็บเป็น NULL (ไม่ใช่สตริงว่าง)',
    '🔴 ได้: ' . var_export($noteOf($bookId), true) . ' — จะมี "" กับ NULL ปนกันในตาราง');

// 📝 ใส่กลับเพื่อใช้ทดสอบข้อถัดไป
$pdo->prepare("UPDATE books SET copy_notes = ? WHERE id = ?")->execute([$SECRET, $bookId]);

// ============================================================
// B. ห้ามรั่วออกหน้าสาธารณะ
// ============================================================
echo "\n── B. เป็นบันทึกภายใน ห้ามรั่ว ──\n";

/**
 * 🔴 ข้อนี้คือเหตุผลที่ต้องมีช่องแยก แทนที่จะให้จดปนใน `description`
 *    เพราะ description สมาชิกเห็น · ถ้าจดว่า "เล่ม 3 หาย" ปนไปด้วย
 *    จะกลายเป็นประกาศให้ทุกคนรู้ว่าห้องสมุดทำหนังสือหาย
 */
$leaks = [];
$publicPages = [
    'index.php'                                    => 'หน้าแรก',
    'book.php?id=' . $bookId                       => 'หน้ารายละเอียดหนังสือ',
    'index.php?search=' . urlencode($uniq)         => 'หน้าแรก (ค้นหาเจอเล่มนี้)',
];
foreach ($publicPages as $path => $label) {
    $html = httpAnon("{$BASE_URL}/{$path}");
    foreach (['เล่ม 2 ปกขาด', "หาย {$uniq}", 'copy_notes', 'หมายเหตุรายเล่ม'] as $needle) {
        if (str_contains($html, $needle)) $leaks[] = "{$label} → เจอ \"{$needle}\"";
    }
}
check('CN2-B1', !$leaks,
    'ไม่รั่วออกหน้าสาธารณะเลยทั้ง ' . count($publicPages) . ' หน้า (ยิงแบบไม่ล็อกอิน)',
    "🔴 รั่ว " . count($leaks) . " จุด:\n       " . implode("\n       ", $leaks));

// 🛡️ ต้องไม่หลุดผ่าน API ค้นหาที่หน้าแรกเรียกด้วย
$api = httpAnon("{$BASE_URL}/api/search_books.php?search=" . urlencode($uniq));
check('CN2-B2', !str_contains($api, 'copy_notes') && !str_contains($api, 'ปกขาด'),
    'API ค้นหาไม่ส่งหมายเหตุออกมาด้วย',
    '🔴 API หลุดข้อมูลภายใน — ' . mb_substr($api, 0, 120));

// ============================================================
// C. ฝั่งผู้ดูแลต้องเห็น + ต้องเตือนว่าไม่ใช่ระบบตามรายเล่ม
// ============================================================
echo "\n── C. ฝั่งผู้ดูแล ──\n";

$login = http('GET', "{$BASE_URL}/login.php");
http('POST', "{$BASE_URL}/login.php", [
    'csrf_token' => csrfFrom($login), 'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD,
]);

$form = http('GET', "{$BASE_URL}/admin/book_form.php?id={$bookId}");
check('CN2-C1', str_contains($form, 'name="copy_notes"') && str_contains($form, 'เล่ม 2 ปกขาด'),
    'ฟอร์มหนังสือมีช่องหมายเหตุ และแสดงค่าที่บันทึกไว้',
    '🔴 ไม่เจอช่องหรือค่าในฟอร์ม');

/**
 * 🔴 เคสนี้สำคัญกว่าที่เห็น: ถ้าไม่เตือน บรรณารักษ์จะจดว่า "เล่ม 3 หาย"
 *    แล้วคิดว่าระบบรู้แล้ว → เลิกแก้จำนวนทั้งหมด → สต็อกบนจอไม่ตรงกับชั้นจริง
 *    เท่ากับฟีเจอร์นี้ทำให้ข้อมูลแย่ลงแทนที่จะดีขึ้น
 */
check('CN2-C2', str_contains($form, 'ไม่ใช่ระบบตามรายเล่ม') && str_contains($form, 'จำนวนทั้งหมด'),
    'มีคำเตือนบนหน้าจอว่านี่คือสมุดจด และต้องแก้จำนวนทั้งหมดเองถ้าเล่มหาย',
    '🔴 ไม่มีคำเตือน — บรรณารักษ์จะจดแล้วคิดว่าระบบหักสต็อกให้');

// 📝 ค้นด้วยเลขสุ่มอย่างเดียว — คำค้นที่มีวงเล็บ/ช่องว่างปนจะไม่ตรงกับ index ค้นหา
//    (เคยใส่ "CN2TEST {$uniq}" แล้วค้นไม่เจอ ทำให้เคสแดงผิดสาเหตุ)
$list = http('GET', "{$BASE_URL}/admin/books.php?search=" . urlencode($uniq));
check('CN2-C3', str_contains($list, 'มีหมายเหตุรายเล่ม'),
    'ตารางจัดการหนังสือขึ้นป้ายบอกว่าเล่มนี้มีหมายเหตุ',
    '🔴 ไม่มีป้าย — ต้องเปิดทีละเล่มถึงจะรู้ว่ามีบันทึกไหม');

// ============================================================
// D. ต้องไม่แตะสต็อก
// ============================================================
echo "\n── D. ไม่แตะสต็อก ──\n";

/**
 * 🔴 หัวใจของการตัดสินใจเลือกทางนี้: **ไม่แตะ invariant เลย**
 *    ถ้าวันหนึ่งมีคนทำให้หมายเหตุไปหักจำนวนที่ว่าง จะกลายเป็นงานใหญ่ที่ตั้งใจเลี่ยง
 */
$before = $pdo->query("SELECT quantity, available FROM books WHERE id = {$bookId}")->fetch(PDO::FETCH_ASSOC);
$pdo->prepare("UPDATE books SET copy_notes = ? WHERE id = ?")
    ->execute(["เล่ม 1 หาย\nเล่ม 2 หาย\nเล่ม 3 หาย", $bookId]);
$after = $pdo->query("SELECT quantity, available FROM books WHERE id = {$bookId}")->fetch(PDO::FETCH_ASSOC);

check('CN2-D1', $before === $after,
    "เขียนหมายเหตุว่าหายทั้ง 3 เล่ม แล้วสต็อกยังเท่าเดิม (quantity {$after['quantity']} · ว่าง {$after['available']})",
    '🔴 สต็อกเปลี่ยน: ก่อน ' . json_encode($before) . ' หลัง ' . json_encode($after));

// 📝 คอลัมน์นี้ต้องไม่ถูกใส่ใน index ค้นหา — ไม่งั้นค้นคำในบันทึกภายในแล้วเจอเล่มนั้น
$repoSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Repositories/BookRepository.php');
preg_match('/function makeSearchTokens.*?\n    \}/s', $repoSrc, $mk);
check('CN2-D2', !str_contains($mk[0] ?? '', 'copy_notes'),
    'หมายเหตุไม่ถูกใส่ใน index ค้นหา — ค้นคำในบันทึกภายในแล้วต้องไม่เจอ',
    '🔴 copy_notes อยู่ใน makeSearchTokens() — สมาชิกจะค้นเจอเล่มจากข้อความภายใน');

// ============================================================
echo "\n══════════════════════════════════════\n";
printf(" RESULTS: %d/%d passed (%.1f%%)%s\n",
    $results['passed'], $results['total'],
    $results['total'] ? $results['passed'] / $results['total'] * 100 : 0,
    $results['failed'] ? ' | ' . $results['failed'] . ' FAILED' : '');
echo "══════════════════════════════════════\n";

exit($results['failed'] > 0 ? 1 : 0);
