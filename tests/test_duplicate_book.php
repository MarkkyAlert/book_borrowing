<?php

/**
 * ทดสอบ "กันการเพิ่มหนังสือซ้ำ" — F-36
 *
 * ==========================================================================
 * 🔴 บั๊กเดิมที่ต้องกันไม่ให้กลับมา
 * ==========================================================================
 * ส่งฟอร์ม /admin/book_form.php ด้วยข้อมูลเดิมซ้ำ ๆ ได้หนังสือใหม่ทุกครั้ง
 *   ส่ง 3 ครั้ง → ได้ 3 เล่ม · ไม่มีคำเตือนเลย
 * uq_isbn คุ้มครองเฉพาะเล่มที่ **มี** ISBN — NULL ซ้ำได้หลายแถวตามมาตรฐาน SQL
 * เล่มที่ไม่มี ISBN (เอกสารเย็บเล่มเอง วารสารเก่า หนังสือบริจาค) จึงไม่มีอะไรกันเลย
 *
 * เทียบกับ flow อื่นในระบบเดียวกัน — ยืมซ้ำ 3 ครั้งได้ 1 รายการ ✅
 * รับชำระซ้ำ 3 ครั้งได้ 1 รายการ ✅ แต่เพิ่มหนังสือซ้ำ 3 ครั้งได้ 3 เล่ม ❌
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. ตัวหา "เล่มที่อาจซ้ำ" — ต้องกันตัวเองออกได้ (สำคัญกับหน้าแก้ไข)
 * B. ส่งฟอร์มซ้ำผ่าน HTTP → ได้เล่มเดียว + เห็นคำเตือนที่อธิบายได้
 * C. 🔴 เตือนแล้วต้อง **ยืนยันเพื่อเพิ่มเป็นคนละเล่มได้** ไม่ใช่ห้ามเด็ดขาด
 * D. ไม่กระทบงานปกติ — แก้ไขเล่มเดิม · เพิ่มหลายเล่มติดกัน · import CSV
 *
 * 🧹 ลบหนังสือที่สร้างขึ้นทั้งหมด — อยู่ใน register_shutdown_function
 *
 * 📌 การใช้งาน: php tests/test_duplicate_book.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Repositories/BookRepository.php';

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

$pdo      = getDB();
$bookRepo = new App\Repositories\BookRepository($pdo);
$COOKIE   = tempnam(sys_get_temp_dir(), 'bbdup');

// 🏷️ ทุกเล่มที่เทสต์นี้สร้างจะขึ้นต้นด้วยคำนี้ — ใช้ทั้งตอนนับและตอนล้าง
const TAG = '[DUPTEST]';

$cleanupDone = false;
$cleanup = function () use (&$cleanupDone, $pdo, $COOKIE) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    try {
        // 🔴 rollback ก่อน ไม่งั้น DELETE ถูก rollback ไปด้วยเมื่อเทสต์ตายกลางทรานแซกชัน
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
            echo "  ↩️  rollback transaction ที่ค้างอยู่ก่อนล้างข้อมูล\n";
        }
        $ids = $pdo->query("SELECT id FROM books WHERE title LIKE '" . TAG . "%'")->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $pdo->exec("DELETE FROM reservations WHERE book_id IN ($in)");
            $pdo->exec("DELETE FROM payments WHERE borrow_id IN (SELECT id FROM borrows WHERE book_id IN ($in))");
            $pdo->exec("DELETE FROM borrows WHERE book_id IN ($in)");
            $pdo->exec("DELETE FROM books WHERE id IN ($in)");
        }
        echo '  ลบหนังสือทดสอบ ' . count($ids) . " เล่ม\n";
    } catch (Throwable $e) {
        echo '  ⚠️ ล้างข้อมูลไม่ครบ: ' . $e->getMessage() . "\n";
    }
    @unlink($COOKIE);
};
register_shutdown_function($cleanup);

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  กันการเพิ่มหนังสือซ้ำ (F-36)                             ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

/** นับหนังสือตามชื่อ */
$countByTitle = function (string $title) use ($pdo): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM books WHERE title = ?");
    $st->execute([$title]);
    return (int) $st->fetchColumn();
};

// ============================================================
// A. ตัวหาเล่มที่อาจซ้ำ
// ============================================================
echo "── A. ตัวหาเล่มที่อาจซ้ำ ──\n";

$catId = (int) $pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn();
$baseTitle  = TAG . ' คู่มือทดสอบซ้ำ';
$baseAuthor = 'ผู้แต่งทดสอบซ้ำ';

$existingId = $bookRepo->create([
    'title' => $baseTitle, 'author' => $baseAuthor,
    'category_id' => $catId, 'quantity' => 2,
]);

$found = $bookRepo->findDuplicateCandidate($baseTitle, $baseAuthor);
check('DUP-A1',
    $found && (int) $found['id'] === $existingId,
    'หาเล่มที่ชื่อ+ผู้แต่งตรงกันเจอ',
    'หาไม่เจอ หรือได้เล่มผิด');

check('DUP-A2',
    isset($found['quantity'], $found['available'], $found['title']),
    'คืนข้อมูลพอให้หน้าจอบอกผู้ใช้ได้ว่าเล่มเดิมมีกี่เล่ม เหลือกี่เล่ม',
    'ข้อมูลไม่พอแสดงผล: ' . json_encode(array_keys($found ?? [])));

// A3 — 🔴 ต้องกันตัวเองออกได้ ไม่งั้นหน้าแก้ไขจะเตือนว่าซ้ำกับตัวเอง
check('DUP-A3',
    $bookRepo->findDuplicateCandidate($baseTitle, $baseAuthor, $existingId) === null,
    'กันตัวเองออกได้ — แก้ไขเล่มเดิมโดยไม่เปลี่ยนชื่อจะไม่ถูกเตือนว่าซ้ำกับตัวเอง',
    '🔴 ยังเจอตัวเอง — หน้าแก้ไขจะเตือนว่าซ้ำทุกครั้งที่กดบันทึก');

// A4 — ช่องว่างหัวท้ายและตัวพิมพ์เล็กใหญ่ต้องไม่ทำให้หลุด
$variants = [
    '  ' . $baseTitle . '  ' => 'มีช่องว่างหัวท้าย',
    mb_strtoupper($baseTitle) => 'ตัวพิมพ์ใหญ่',
];
$missed = [];
foreach ($variants as $v => $label) {
    if ($bookRepo->findDuplicateCandidate($v, $baseAuthor) === null) $missed[] = $label;
}
check('DUP-A4', $missed === [],
    'ช่องว่างหัวท้าย / ตัวพิมพ์เล็กใหญ่ ไม่ทำให้หลุดการตรวจ',
    'หลุดเมื่อ: ' . implode(' · ', $missed));

// A5 — ชื่อคนละเล่มต้องไม่ถูกจับว่าซ้ำ
check('DUP-A5',
    $bookRepo->findDuplicateCandidate($baseTitle, 'ผู้แต่งคนอื่นไปเลย') === null
        && $bookRepo->findDuplicateCandidate(TAG . ' เล่มอื่น', $baseAuthor) === null,
    'ชื่อเดียวกันแต่คนละผู้แต่ง (และกลับกัน) ไม่ถูกจับว่าซ้ำ',
    '🔴 จับผิดตัว — เตือนทั้งที่เป็นคนละเล่ม');

// ============================================================
// B–D. ผ่านหน้าเว็บจริง
// ============================================================
function http(string $method, string $url, array $fields = []): array
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $COOKIE,
        CURLOPT_COOKIEFILE     => $COOKIE,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 25,
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

$login = http('GET', "$BASE_URL/login.php");
$r = http('POST', "$BASE_URL/login.php", [
    'csrf_token' => csrfFrom($login['body']),
    'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD,
]);

if (!str_contains($r['body'], 'ออกจากระบบ') && !str_contains($r['body'], 'logout')) {
    fail('DUP-B1', 'ล็อกอินไม่สำเร็จ — ข้ามการทดสอบผ่านหน้าเว็บ (ส่งรหัสผ่าน admin เป็น argument)');
} else {
    /** ส่งฟอร์มเพิ่มหนังสือ (ดึง CSRF สดทุกครั้งเหมือนคนกดจริง) */
    $submit = function (array $extra = []) use ($BASE_URL): array {
        $form = http('GET', "$BASE_URL/admin/book_form.php");
        return http('POST', "$BASE_URL/admin/book_form.php", array_merge([
            'csrf_token' => csrfFrom($form['body']),
            'quantity'   => 1,
            'is_visible' => 1,
        ], $extra));
    };

    // ── B. ส่งซ้ำผ่านหน้าเว็บ ──
    echo "\n── B. ส่งฟอร์มซ้ำผ่านหน้าเว็บ ──\n";

    $dupTitle  = TAG . ' หนังสือกดซ้ำ';
    $dupAuthor = 'ผู้แต่งกดซ้ำ';

    $submit(['title' => $dupTitle, 'author' => $dupAuthor]);
    $after1 = $countByTitle($dupTitle);
    $submit(['title' => $dupTitle, 'author' => $dupAuthor]);
    $res3 = $submit(['title' => $dupTitle, 'author' => $dupAuthor]);
    $after3 = $countByTitle($dupTitle);

    check('DUP-B1', $after1 === 1 && $after3 === 1,
        "ส่งฟอร์มเดิม 3 ครั้ง ได้หนังสือ {$after3} เล่ม (เดิมได้ 3 เล่ม)",
        "🔴 ได้ {$after3} เล่มจากการส่ง 3 ครั้ง — ยังกันซ้ำไม่ได้");

    // B2 — 🔴 ต้องเห็นคำเตือนที่อธิบายได้ ไม่ใช่เงียบ ๆ หรือเด้งกลับเฉย ๆ
    check('DUP-B2',
        str_contains($res3['body'], 'มีหนังสือชื่อนี้อยู่แล้ว'),
        'ผู้ใช้เห็นคำเตือนว่ามีเล่มนี้อยู่แล้ว',
        '🔴 ไม่มีคำเตือน — ผู้ใช้ไม่รู้ว่าทำไมเพิ่มไม่ได้');

    check('DUP-B3',
        preg_match('/คงเหลือ\s*\d+\s*จาก\s*\d+\s*เล่ม/u', $res3['body']) === 1,
        'คำเตือนบอกด้วยว่าเล่มเดิมมีกี่เล่ม เหลือกี่เล่ม',
        'คำเตือนไม่บอกจำนวนของเล่มเดิม');

    check('DUP-B4',
        str_contains($res3['body'], 'ไปเพิ่มจำนวนที่เล่มเดิม'),
        'มีทางลัดไปแก้จำนวนที่เล่มเดิม — กรณีได้เล่มเดิมมาเพิ่ม',
        'ไม่มีทางไปต่อ ผู้ใช้ต้องหาเล่มเดิมเอง');

    // ── C. ยืนยันเพื่อเพิ่มเป็นคนละเล่ม ──
    echo "\n── C. ยืนยันว่าเป็นคนละเล่ม ──\n";

    check('DUP-C1',
        str_contains($res3['body'], 'เป็นคนละเล่มจริง ๆ'),
        'มีตัวเลือกยืนยันว่าเป็นคนละเล่ม',
        '🔴 ไม่มีทางยืนยัน — ชื่อเรื่องซ้ำกันได้จริง (คนละสำนักพิมพ์/คนละปี) จะเพิ่มไม่ได้เลย');

    $submit(['title' => $dupTitle, 'author' => $dupAuthor, 'confirm_duplicate' => 1]);
    $afterConfirm = $countByTitle($dupTitle);
    check('DUP-C2', $afterConfirm === 2,
        "ติ๊กยืนยันแล้วเพิ่มเป็นรายการใหม่ได้ (ตอนนี้ {$afterConfirm} เล่ม)",
        "🔴 ยืนยันแล้วยังเพิ่มไม่ได้ — ได้ {$afterConfirm} เล่ม ควรเป็น 2");

    // C3 — 🔴 กดยืนยันซ้ำเร็ว ๆ ต้องไม่ได้เล่มที่ 3
    $submit(['title' => $dupTitle, 'author' => $dupAuthor, 'confirm_duplicate' => 1]);
    $afterConfirm2 = $countByTitle($dupTitle);
    check('DUP-C3', $afterConfirm2 === 2,
        "กดยืนยันซ้ำทันที ยังได้ {$afterConfirm2} เล่มเท่าเดิม",
        "🔴 กดยืนยันซ้ำแล้วได้ {$afterConfirm2} เล่ม — idempotency ไม่ครอบเส้นทางที่ยืนยันแล้ว");

    // ── D. ไม่กระทบงานปกติ ──
    echo "\n── D. ไม่กระทบงานปกติ ──\n";

    // D1 — 🔴 แก้ไขเล่มที่ชื่อไม่ซ้ำใคร โดยไม่เปลี่ยนชื่อ ต้องไม่ถูกเตือน
    $soloTitle  = TAG . ' เล่มไม่ซ้ำใคร';
    $soloAuthor = 'ผู้แต่งเดี่ยว';
    $submit(['title' => $soloTitle, 'author' => $soloAuthor]);
    $soloId = (int) $pdo->query("SELECT id FROM books WHERE title = " . $pdo->quote($soloTitle))->fetchColumn();

    $editForm = http('GET', "$BASE_URL/admin/book_form.php?id={$soloId}");
    $editRes  = http('POST', "$BASE_URL/admin/book_form.php", [
        'csrf_token' => csrfFrom($editForm['body']),
        'id' => $soloId, 'title' => $soloTitle, 'author' => $soloAuthor,
        'quantity' => 5, 'is_visible' => 1,
    ]);
    $soloQty = (int) $pdo->query("SELECT quantity FROM books WHERE id = {$soloId}")->fetchColumn();

    check('DUP-D1',
        !str_contains($editRes['body'], 'มีหนังสือชื่อนี้อยู่แล้ว') && $soloQty === 5,
        "แก้ไขเล่มเดิมโดยไม่เปลี่ยนชื่อ → ไม่ถูกเตือน และบันทึกได้ (quantity = {$soloQty})",
        '🔴 หน้าแก้ไขเตือนว่าซ้ำกับตัวเอง — บรรณารักษ์จะแก้ข้อมูลไม่ได้เลย');

    // D2 — แก้ชื่อไปชนเล่มอื่น ต้องเตือน
    $collide = http('POST', "$BASE_URL/admin/book_form.php", [
        'csrf_token' => csrfFrom(http('GET', "$BASE_URL/admin/book_form.php?id={$soloId}")['body']),
        'id' => $soloId, 'title' => $dupTitle, 'author' => $dupAuthor,
        'quantity' => 5, 'is_visible' => 1,
    ]);
    check('DUP-D2',
        str_contains($collide['body'], 'มีหนังสือชื่อนี้อยู่แล้ว'),
        'แก้ชื่อไปชนกับเล่มอื่น → เตือนถูก',
        '🔴 แก้ชื่อไปชนเล่มอื่นแล้วไม่เตือน');

    // D3 — 🔴 เพิ่มหนังสือคนละเล่ม 2 เล่มติดกัน ต้องไม่ถูกบล็อก
    //      (ถ้า idempotency key ผูกกับผู้ใช้อย่างเดียวจะพลาดตรงนี้)
    $submit(['title' => TAG . ' เล่มติดกัน 1', 'author' => 'ผู้แต่ง 1']);
    $submit(['title' => TAG . ' เล่มติดกัน 2', 'author' => 'ผู้แต่ง 2']);
    $consecutive = (int) $pdo->query("SELECT COUNT(*) FROM books WHERE title LIKE '" . TAG . " เล่มติดกัน%'")->fetchColumn();
    check('DUP-D3', $consecutive === 2,
        "เพิ่มหนังสือคนละเล่ม 2 เล่มติดกันได้ครบ ({$consecutive}/2)",
        "🔴 ได้ {$consecutive}/2 — idempotency บล็อกผิด ต้องผูก key กับเนื้อหาฟอร์ม ไม่ใช่แค่ผู้ใช้");

    // D4 — import CSV ต้องยังใช้เมธอดเดิมที่ไม่กันตัวเองออก
    $importSrc = (string) file_get_contents(__DIR__ . '/../admin/import_books.php');
    check('DUP-D4',
        str_contains($importSrc, 'findByTitleAndAuthor')
            && !str_contains($importSrc, 'findDuplicateCandidate'),
        'import CSV ยังใช้ findByTitleAndAuthor() เดิม — พฤติกรรมเพิ่มจำนวนไม่เปลี่ยน',
        '🔴 import CSV ถูกเปลี่ยนพฤติกรรมไปด้วย ทั้งที่ไม่ควรแตะ');
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
