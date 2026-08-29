<?php

/**
 * รายงานที่บรรณารักษ์ต้องใช้ — F-50
 *
 * ==========================================================================
 * 🔴 ปัญหาเดิม
 * ==========================================================================
 * 1. Dashboard "หนังสือทั้งหมด" = จำนวน **เล่ม** ไม่ใช่ **ชื่อเรื่อง**
 *    ตัวเลขชื่อเรื่องไม่ปรากฏบนหน้านั้นเลย ทั้งที่สำมะโนหนังสือต้องใช้ทั้งคู่
 * 2. มีแต่รายงาน "ยอดนิยม" ไม่มีด้านกลับ — รายงานที่ใช้ตัดสินใจ **จำหน่ายหนังสือออก**
 * 3. รายงานค้างชำระเรียงตามยอดเงิน ไม่มีคอลัมน์ "ค้างมากี่วัน"
 *    (ค้าง 20 บาทมา 8 เดือน ต่างจากค้าง 200 บาทเมื่อวาน)
 * 4. ตาราง 217 แถวไม่มีแถวรวม ต้องเปิด Excel บวกเอง
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. รายงานหนังสือที่ไม่มีการยืม — ผูกกับช่วงวันที่จริง · ตัดหนังสืออ้างอิง ·
 *    เล่มที่ยืมออกแล้วยังไม่คืนต้องไม่ติด (มีคนใช้อยู่)
 * B. คอลัมน์อายุหนี้ — ค่าถูก และ **จำนวนหัวตารางต้องเท่าจำนวนคอลัมน์ข้อมูล**
 *    (บทเรียนจาก F-44: เพิ่มคอลัมน์ใน query แล้วลืมหัวตาราง ทำให้ทุกช่องเลื่อน)
 * C. ยอดรวม — 🔴 คอลัมน์ "อายุ" ห้ามถูกรวม · เบอร์โทรห้ามถูกรวม ·
 *    CSV ต้องเว้นบรรทัดคั่นและไม่มีคอมมา (Excel SUM ต่อได้)
 * D. Dashboard — จำนวนชื่อเรื่องกับจำนวนเล่มต้องเป็นคนละตัวและตรงกับ DB ทั้งคู่
 *
 * 🧹 ลบ fixture ทั้งหมด + คืนสต็อกให้ตรง invariant
 *
 * 📌 การใช้งาน: php tests/test_reports.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report_helper.php';
require_once __DIR__ . '/../app/Services/BookService.php';
require_once __DIR__ . '/../app/Repositories/ReportRepository.php';

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
$COOKIE = tempnam(sys_get_temp_dir(), 'bbrpt');

$madeBooks = $madeUsers = $madeBorrows = [];
$cleanupDone = false;
$cleanup = function () use (&$madeBooks, &$madeUsers, &$madeBorrows, &$cleanupDone, $pdo, $COOKIE) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    try {
        if ($pdo->inTransaction()) $pdo->rollBack();
    } catch (Throwable $e) {
        echo '  ⚠️ rollback ไม่สำเร็จ: ' . $e->getMessage() . "\n";
    }

    $failed = [];
    // 📚 คืนสต็อกจากการยืมที่ทดสอบสร้างเอง (insert ดิบ → ต้องคืนเอง)
    foreach ($madeBorrows as $bw) {
        try {
            $pdo->prepare("DELETE FROM payments WHERE borrow_id = ?")->execute([$bw['id']]);
            $pdo->prepare("DELETE FROM borrows WHERE id = ?")->execute([$bw['id']]);
            if ($bw['held']) {
                $pdo->prepare("UPDATE books SET available = available + 1 WHERE id = ?")->execute([$bw['book_id']]);
            }
        } catch (Throwable $e) { $failed[] = "borrow#{$bw['id']}"; }
    }
    foreach ($madeUsers as $uid) {
        try { $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]); }
        catch (Throwable $e) { $failed[] = "user#{$uid}"; }
    }
    foreach ($madeBooks as $bid) {
        try { $pdo->prepare("DELETE FROM books WHERE id = ?")->execute([$bid]); }
        catch (Throwable $e) { $failed[] = "book#{$bid}"; }
    }

    echo '  ลบหนังสือ ' . count($madeBooks) . ' · สมาชิก ' . count($madeUsers)
        . ' · การยืม ' . count($madeBorrows) . "\n";
    if ($failed) echo '  🔴 ลบไม่สำเร็จ ต้องลบมือ: ' . implode(' · ', $failed) . "\n";

    try {
        $bad = $pdo->query("
            SELECT COUNT(*) FROM (
                SELECT b.id, b.quantity, b.available FROM books b
                HAVING b.available <> b.quantity
                    - (SELECT COUNT(*) FROM borrows bo WHERE bo.book_id = b.id AND bo.status = 'borrowing')
                    - (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.id AND r.status = 'pending')
            ) t
        ")->fetchColumn();
        echo ((int) $bad === 0)
            ? "  ✅ invariant สต็อกยังตรง\n"
            : "  🔴 invariant สต็อกเพี้ยน {$bad} เล่ม — ต้องแก้มือ\n";
    } catch (Throwable $e) {
        echo '  ⚠️ ตรวจ invariant ไม่ได้: ' . $e->getMessage() . "\n";
    }
    @unlink($COOKIE);
};
register_shutdown_function($cleanup);

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  รายงานที่บรรณารักษ์ต้องใช้ (F-50)                        ║\n";
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
        CURLOPT_TIMEOUT        => 60,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = (string) curl_exec($ch);
    curl_close($ch);
    return $body;
}

function csrfFrom(string $html): string
{
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

$login = http('GET', "$BASE_URL/login.php");
$r = http('POST', "$BASE_URL/login.php", [
    'csrf_token' => csrfFrom($login), 'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD,
]);
if (!str_contains($r, 'ออกจากระบบ') && !str_contains($r, 'logout')) {
    fail('RPT-00', 'ล็อกอินไม่สำเร็จ — ส่งรหัสผ่าน admin เป็น argument');
    exit(1);
}

$uniq        = substr((string) getmypid(), -4) . mt_rand(100, 999);
$bookService = new \App\Services\BookService($pdo);
$reportRepo  = new \App\Repositories\ReportRepository($pdo);
$catId       = (int) $pdo->query("SELECT id FROM categories LIMIT 1")->fetchColumn();

$mkBook = function (string $tag, bool $isReference = false) use ($bookService, $catId, $uniq, $pdo, &$madeBooks): int {
    // 🔴 สร้างผ่าน Service — Repository เป็นตัวเติม search_tokens
    $id = (int) $bookService->createBook([
        'title' => "[RPTTEST] {$tag} {$uniq}", 'author' => 'ผู้แต่งทดสอบ',
        'category_id' => $catId, 'quantity' => 1, 'isbn' => null,
    ]);
    if ($isReference) {
        $pdo->prepare("UPDATE books SET is_reference = 1 WHERE id = ?")->execute([$id]);
    }
    $madeBooks[] = $id;
    return $id;
};

$st = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'member')");
$st->execute(["[RPTTEST] ผู้ยืมทดสอบ {$uniq}", "rpt_{$uniq}@test.com", password_hash('x', PASSWORD_DEFAULT)]);
$userId = (int) $pdo->lastInsertId();
$madeUsers[] = $userId;

/** สร้างการยืม — $stillOut = ยังไม่คืน (กันสต็อก) */
$mkBorrow = function (int $bookId, string $borrowDate, bool $stillOut = false, float $fine = 0.0) use ($pdo, $userId, &$madeBorrows): int {
    $st = $pdo->prepare("
        INSERT INTO borrows (user_id, book_id, borrow_date, due_date, return_date, status, fine_amount, created_at)
        VALUES (?, ?, ?, DATE_ADD(?, INTERVAL 7 DAY), " . ($stillOut ? 'NULL' : 'DATE_ADD(?, INTERVAL 10 DAY)') . ",
                ?, ?, NOW())
    ");
    $params = $stillOut
        ? [$userId, $bookId, $borrowDate, $borrowDate, 'borrowing', $fine]
        : [$userId, $bookId, $borrowDate, $borrowDate, $borrowDate, 'returned', $fine];
    $st->execute($params);
    $id = (int) $pdo->lastInsertId();
    if ($stillOut) {
        $pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?")->execute([$bookId]);
    }
    $madeBorrows[] = ['id' => $id, 'book_id' => $bookId, 'held' => $stillOut];
    return $id;
};

// ============================================================
// A. รายงานหนังสือที่ไม่มีการยืม
// ============================================================
echo "── A. หนังสือที่ไม่มีการยืม ──\n";

// 📅 ช่วงที่ใช้ทดสอบ: 60 วันที่ผ่านมา
$rangeStart = date('Y-m-d', strtotime('-60 days'));
$rangeEnd   = date('Y-m-d');

$bookNever     = $mkBook('ไม่เคยถูกยืมเลย');
$bookInRange   = $mkBook('ยืมในช่วงที่เลือก');
$bookOutRange  = $mkBook('ยืมนานมาแล้ว');
$bookStillOut  = $mkBook('ยืมออกไปแล้วยังไม่คืน');
$bookReference = $mkBook('หนังสืออ้างอิง', true);

$mkBorrow($bookInRange,  date('Y-m-d', strtotime('-10 days')));
$mkBorrow($bookOutRange, date('Y-m-d', strtotime('-200 days')));
$mkBorrow($bookStillOut, date('Y-m-d', strtotime('-5 days')), true);

$dormant = $reportRepo->getDormantBooksReport($rangeStart, $rangeEnd);
$titles  = array_column($dormant, 'title');
$has = fn(int $id) => in_array((string) $pdo->query("SELECT title FROM books WHERE id = {$id}")->fetchColumn(), $titles, true);

check('RPT-A1', $has($bookNever),
    'หนังสือที่ไม่เคยถูกยืมเลย → โผล่ในรายงาน',
    '🔴 หาไม่เจอ — รายงานนี้มีไว้หากลุ่มนี้โดยเฉพาะ');

check('RPT-A2', !$has($bookInRange),
    'หนังสือที่ถูกยืมในช่วงที่เลือก → ไม่โผล่',
    '🔴 เล่มที่เพิ่งถูกยืมยังติดรายงาน — จะแนะนำให้จำหน่ายของที่คนใช้อยู่ออก');

check('RPT-A3', $has($bookOutRange),
    '🔴 หนังสือที่ยืมครั้งสุดท้ายนอกช่วง → โผล่ (ผูกกับช่วงที่เลือกจริง ไม่ใช่ทั้งประวัติ)',
    '🔴 ไม่โผล่ — แปลว่ารายงานดูทั้งประวัติ ไม่ได้ดูช่วงที่เลือก '
        . 'ห้องสมุดที่ใช้เกณฑ์ 3 เดือนจะไม่ได้รายชื่ออะไรเลย');

check('RPT-A4', !$has($bookReference),
    '🔴 หนังสืออ้างอิง → ไม่โผล่ (ยืมออกไม่ได้อยู่แล้ว ไม่มีสถิติเป็นเรื่องปกติ)',
    '🔴 หนังสืออ้างอิงติดรายงานด้วย — จะกลายเป็นรายชื่อหลอกให้จำหน่ายของที่ยังต้องใช้ทิ้ง');

check('RPT-A5', !$has($bookStillOut),
    '🔴 เล่มที่ยืมออกไปแล้วยังไม่คืน → ไม่โผล่ (มีคนใช้อยู่)',
    '🔴 เล่มที่อยู่ในมือคนอ่านตอนนี้ติดรายงานว่า "ไม่มีการยืม"');

// A6 — จำนวนต้องตรงกับ SQL ตรง ๆ
$sqlCount = (int) $pdo->query("
    SELECT COUNT(*) FROM books bk
    WHERE bk.is_reference = 0
      AND NOT EXISTS (
          SELECT 1 FROM borrows b WHERE b.book_id = bk.id
            AND DATE(b.borrow_date) BETWEEN '{$rangeStart}' AND '{$rangeEnd}'
      )
")->fetchColumn();
check('RPT-A6', count($dormant) === $sqlCount,
    'จำนวนในรายงานตรงกับที่นับจาก DB ตรง ๆ (' . count($dormant) . ' เล่ม)',
    '🔴 รายงานได้ ' . count($dormant) . ' แต่ DB มี ' . $sqlCount);

// ============================================================
// B. คอลัมน์อายุหนี้ในรายงานค้างชำระ
// ============================================================
echo "\n── B. คอลัมน์ \"ค้างมากี่วัน\" ──\n";

$cfg = getReportConfig('unpaid', date('Y-m-d', strtotime('-1 year')), date('Y-m-d'), $reportRepo);

// B1 — 🔴 จำนวนหัวตารางต้องเท่าจำนวนคอลัมน์ข้อมูลเป๊ะ
//      บทเรียนจาก F-44: ROADMAP ข้อ 4 เติมคอลัมน์ใน query แล้วลืมหัวตาราง
//      ผลคือ CSV 217 แถวมีคอลัมน์เกินหัว 1 ช่อง ทุกช่องตั้งแต่ตรงนั้นเลื่อนผิดตำแหน่ง
$mismatch = [];
foreach (['books', 'members', 'revenue', 'overdue', 'borrows', 'unpaid', 'dormant'] as $type) {
    $c = getReportConfig($type, date('Y-m-d', strtotime('-1 year')), date('Y-m-d'), $reportRepo);
    if (!$c['data']) continue;
    $dataCols = count((array) reset($c['data']));
    if ($dataCols !== count($c['headers'])) {
        $mismatch[] = "{$type}: หัว " . count($c['headers']) . " ช่อง แต่ข้อมูล {$dataCols} ช่อง";
    }
}
check('RPT-B1', $mismatch === [],
    'ทุกรายงาน จำนวนหัวตารางเท่าจำนวนคอลัมน์ข้อมูล (ตรวจ 7 รายงาน)',
    '🔴 คอลัมน์เลื่อนผิดตำแหน่ง: ' . implode(' · ', $mismatch));

check('RPT-B2', in_array('ค้างมา (วัน)', $cfg['headers'], true),
    'รายงานค้างชำระมีคอลัมน์ "ค้างมา (วัน)"',
    '🔴 ไม่มีคอลัมน์อายุหนี้ — เรียงตามยอดเงินอย่างเดียวไม่พอ');

// B3 — ค่าต้องตรงกับที่คำนวณจาก DB
$sample = $cfg['data'][0] ?? null;
if ($sample === null) {
    fail('RPT-B3', 'ไม่มีข้อมูลค้างชำระให้ตรวจ');
} else {
    $expect = (int) $pdo->query("
        SELECT DATEDIFF(CURDATE(), COALESCE(b.return_date, b.lost_reported_at))
        FROM borrows b JOIN users u ON u.id = b.user_id JOIN books bk ON bk.id = b.book_id
        WHERE u.name = " . $pdo->quote($sample['user_name']) . "
          AND bk.title = " . $pdo->quote($sample['book_title']) . "
          AND b.fine_amount = " . (float) $sample['fine_amount'] . "
        LIMIT 1
    ")->fetchColumn();
    check('RPT-B3', (int) $sample['days_unpaid'] === $expect,
        "อายุหนี้คำนวณถูก ({$sample['days_unpaid']} วัน ตรงกับ DB)",
        "🔴 รายงานบอก {$sample['days_unpaid']} วัน แต่ DB คำนวณได้ {$expect}");
}

// ============================================================
// C. ยอดรวมท้ายตาราง
// ============================================================
echo "\n── C. ยอดรวมท้ายตาราง ──\n";

$totals = reportColumnTotals($cfg['data']);

// C1 — ยอดเงินต้องเท่าผลบวกทุกแถวจริง (ไม่ใช่แค่ที่เห็นบนจอ)
$expectSum = array_sum(array_map(fn($r) => (float) $r['fine_amount'], $cfg['data']));
check('RPT-C1', abs(($totals['fine_amount'] ?? -1) - $expectSum) < 0.01,
    'ยอดรวมค่าปรับเท่าผลบวกทุกแถว (' . number_format($expectSum, 2) . ' บาท จาก ' . count($cfg['data']) . ' แถว)',
    '🔴 ยอดรวมได้ ' . var_export($totals['fine_amount'] ?? null, true) . ' แต่ผลบวกจริงคือ ' . $expectSum);

// C2 — 🔴 คอลัมน์ "อายุ" ห้ามถูกรวม
//      เจอตอนทดสอบจริง: แถวรวมเคยขึ้นว่า "ค้างมา 11,660 วัน" ซึ่งไร้ความหมาย
//      (ไร้สาระแบบเดียวกับรวมเบอร์โทร แค่มองออกยากกว่า)
// 🔴 [บทเรียน] ห้ามใช้ ?? ตรวจค่า null — `null ?? 'x'` คืน 'x'
//    เพราะ ?? มองค่า null ว่า "ไม่มีค่า" เคสนี้จึงกลับด้านทั้งที่โค้ดถูก
//    ต้องใช้ array_key_exists แยกให้ชัดระหว่าง "ไม่มีคีย์" กับ "มีคีย์แต่ค่าเป็น null"
$hasDaysKey = array_key_exists('days_unpaid', $totals);
check('RPT-C2', $hasDaysKey && $totals['days_unpaid'] === null,
    '🔴 คอลัมน์อายุหนี้ไม่ถูกรวมยอด — "รวมจำนวนวัน" ไม่มีความหมาย',
    '🔴 ' . ($hasDaysKey
        ? 'รวมอายุหนี้เป็น ' . var_export($totals['days_unpaid'], true)
          . ' วัน — ตัวเลขไร้ความหมายที่อ่านแล้วเข้าใจผิดได้'
        : 'ไม่มีคอลัมน์ days_unpaid ในผลลัพธ์เลย'));

// C3 — 🔴 เบอร์โทรห้ามถูกรวม (เหตุผลเดียวกับ F-44)
$hasPhoneKey = array_key_exists('user_phone', $totals);
check('RPT-C3', $hasPhoneKey && $totals['user_phone'] === null,
    'เบอร์โทรไม่ถูกรวมยอด',
    '🔴 ' . ($hasPhoneKey
        ? 'รวมเบอร์โทรเป็น ' . var_export($totals['user_phone'], true)
          . ' — ตัวเลขไร้สาระ (F-44 เตือนเรื่องนี้ไว้แล้ว)'
        : 'ไม่มีคอลัมน์ user_phone ในผลลัพธ์เลย'));

// C4 + C5 — CSV
$csv = http('GET', "$BASE_URL/admin/reports.php?report=unpaid&start_date="
    . date('Y-m-d', strtotime('-1 year')) . "&end_date=" . date('Y-m-d') . "&export=csv");
$lines = preg_split('/\r\n|\n/', trim($csv));
$lastLine   = (string) end($lines);
$beforeLast = (string) ($lines[count($lines) - 2] ?? 'x');

check('RPT-C4', str_starts_with($lastLine, 'รวมทั้งหมด') && trim($beforeLast) === '',
    '🔴 CSV มีแถวรวม และเว้นบรรทัดคั่นก่อน — Excel ตัดช่วงข้อมูลถูก ไม่นับแถวรวมเป็นข้อมูล',
    '🔴 ' . (str_starts_with($lastLine, 'รวมทั้งหมด')
        ? 'ไม่ได้เว้นบรรทัดคั่น → Excel จะนับแถวรวมเป็นข้อมูลอีกแถว = ยอดเบิ้ลตอน SUM'
        : 'ไม่มีแถวรวมใน CSV: "' . $lastLine . '"'));

// 🔴 ยอดรวมใน CSV ต้องไม่มีคอมมา ไม่งั้น Excel มองเป็นข้อความแล้ว SUM ต่อไม่ได้ (F-44)
$totalCells = str_getcsv($lastLine);
$moneyCell  = (string) end($totalCells);
check('RPT-C5', preg_match('/^\d+(\.\d+)?$/', $moneyCell) === 1,
    "ยอดรวมใน CSV ไม่มีคอมมา ({$moneyCell}) — Excel เอาไป SUM ต่อได้",
    '🔴 ยอดรวมมีคอมมา/รูปแบบผิด: "' . $moneyCell . '" → Excel มองเป็นข้อความ');

// C6 — 🔴 รายงานที่ไม่มีคอลัมน์รวมได้เลย ต้องไม่มีแถวรวม
//      "หนังสือค้างส่ง" มีตัวเลขตัวเดียวคือ days_overdue ซึ่งรวมไม่ได้
//      ถ้ายังขึ้นแถวรวมว่าง ๆ จะดูเหมือนระบบคำนวณพลาด
$csvOverdue = http('GET', "$BASE_URL/admin/reports.php?report=overdue&export=csv");
check('RPT-C6', !str_contains($csvOverdue, 'รวมทั้งหมด'),
    'รายงานที่ไม่มีคอลัมน์รวมได้ → ไม่ขึ้นแถวรวมเปล่า ๆ',
    '🔴 ขึ้นแถวรวมทั้งที่ไม่มีอะไรให้รวม — ดูเหมือนระบบคำนวณพลาด');

// C7 — แถวรวมต้องมีในไฟล์พิมพ์ด้วย ไม่ใช่แค่หน้าเว็บ
$pdfHtml = http('GET', "$BASE_URL/admin/export_pdf.php?report=unpaid&start_date="
    . date('Y-m-d', strtotime('-1 year')) . "&end_date=" . date('Y-m-d'));
// 🔴 [บทเรียน] เคยเช็คแค่ str_contains($pdfHtml, 'total-row')
//    ซึ่ง **ไปแมตช์กับกฎ CSS `.total-row td {...}` ในหัวไฟล์** ไม่ใช่แถวจริง
//    เปลี่ยนชื่อคลาสของแถวทิ้งแล้วเทสต์ยังเขียว → ต้องดูที่ <tfoot> ที่มีเนื้อจริง
$pdfHasFooter = preg_match('/<tfoot\b.*?รวมทั้งหมด.*?<\/tfoot>/s', $pdfHtml) === 1;
check('RPT-C7', $pdfHasFooter,
    'ไฟล์สำหรับพิมพ์มีแถวรวมด้วย — คนที่ถือกระดาษไปประชุมไม่ต้องบวกเอง',
    '🔴 ไฟล์พิมพ์ไม่มีแถวรวม — แก้แค่หน้าเว็บ ยังไม่ได้แก้ปัญหาที่ยกมา');

// C8 — หน้าเว็บก็ต้องมี
$webHtml = http('GET', "$BASE_URL/admin/reports.php?report=unpaid&start_date="
    . date('Y-m-d', strtotime('-1 year')) . "&end_date=" . date('Y-m-d'));
// ตรวจว่า "รวมทั้งหมด" อยู่ **ใน** tfoot จริง ไม่ใช่โผล่ที่อื่นในหน้า
check('RPT-C8', preg_match('/<tfoot\b.*?รวมทั้งหมด.*?<\/tfoot>/s', $webHtml) === 1,
    'หน้าเว็บมีแถวรวมท้ายตาราง',
    '🔴 หน้าเว็บไม่มีแถวรวม');

// ============================================================
// D. Dashboard: เล่ม กับ ชื่อเรื่อง
// ============================================================
echo "\n── D. Dashboard: จำนวนเล่ม vs จำนวนชื่อเรื่อง ──\n";

$dash    = http('GET', "$BASE_URL/admin/index.php");
$dbCopies = (int) $pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM books")->fetchColumn();
$dbTitles = (int) $pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();

check('RPT-D1', $dbCopies !== $dbTitles,
    "ข้อมูลจริงมีเล่มกับชื่อเรื่องไม่เท่ากัน ({$dbCopies} เล่ม / {$dbTitles} ชื่อเรื่อง) — เคสนี้จึงมีความหมาย",
    'ข้อมูลทดสอบมีเล่ม = ชื่อเรื่อง ทำให้แยกไม่ออกว่าหน้าจอแสดงตัวไหน — ต้องเพิ่ม fixture');

check('RPT-D2', str_contains($dash, number_format($dbTitles) . ' ชื่อเรื่อง'),
    "Dashboard แสดงจำนวนชื่อเรื่อง ({$dbTitles}) ตรงกับ DB",
    '🔴 ไม่พบจำนวนชื่อเรื่องบน Dashboard — สำมะโนหนังสือต้องใช้ตัวนี้');

// 🔴 [บทเรียน] เคยเช็คแค่ว่าข้อความโผล่ที่ไหนสักแห่งในหน้า
//    แต่หน้านี้มีการ์ดสองชุด (บนจอ + ฉบับพิมพ์) ที่ใช้ข้อความเดียวกัน
//    แก้ป้ายบนจอทิ้งแล้วเทสต์ยังเขียว เพราะไปแมตช์กับการ์ดฉบับพิมพ์แทน
//    → ต้องเจาะจงว่าเป็นป้ายของการ์ดบนจอ (<p class="...">) ไม่ใช่ .stat-label
$cardLabelOk = preg_match('/<p[^>]*class="[^"]*tracking-wider[^"]*"[^>]*>\s*หนังสือทั้งหมด \(เล่ม\)\s*<\/p>/u', $dash) === 1;
check('RPT-D3', $cardLabelOk,
    'ป้ายการ์ดบนหน้าจอบอกชัดว่าตัวเลขใหญ่คือจำนวน "เล่ม"',
    '🔴 ป้ายการ์ดยังเขียนว่า "หนังสือทั้งหมด" เฉย ๆ ซึ่งอ่านได้ทั้งเล่มและชื่อเรื่อง');

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
