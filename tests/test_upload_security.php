<?php

/**
 * Upload Security Tests — ทดสอบด่านกรองไฟล์อัปโหลดด้วยไฟล์จริง
 *
 * ครอบคลุม:
 * - MIME ตรวจจากเนื้อไฟล์จริง ไม่เชื่อ Content-Type ที่ client ส่งมา
 * - ไฟล์ PHP เปลี่ยนนามสกุลเป็น .jpg / นามสกุลซ้อน (.php.jpg)
 * - HTML, SVG, ไฟล์ว่าง, ไฟล์เกิน 2MB
 * - Polyglot (PNG จริงที่มีโค้ด PHP ต่อท้าย) → ผ่าน filter ได้ แต่ต้องรันไม่ได้
 * - uploads/.htaccess เป็นด่านสุดท้าย: วางไฟล์ .php ลงโฟลเดอร์ตรง ๆ ต้องเข้าไม่ถึง
 *
 * Usage: php tests/test_upload_security.php [admin_password]
 * ⚠️ รันบน CLI เท่านั้น + ต้องเปิด Apache
 * ⚠️ สร้าง/ลบหนังสือทดสอบ 1 เล่ม และล้างไฟล์ที่อัปโหลดทั้งหมดเมื่อจบ
 *
 * 🧠 ทำไมต้องทดสอบด้วยไฟล์จริง:
 *    การอ่านโค้ดบอกได้แค่ว่า "เรียก finfo_file()" แต่บอกไม่ได้ว่า finfo มองไฟล์แต่ละแบบ
 *    เป็น MIME อะไรจริง ๆ และบอกไม่ได้เลยว่า Apache จะรันไฟล์ที่หลุดเข้าไปหรือไม่
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$BASE_URL       = rtrim(APP_URL, '/');
$ADMIN_EMAIL    = 'admin@library.com';
$ADMIN_PASSWORD = $argv[1] ?? '123456';
$COVER_DIR      = dirname(__DIR__) . '/uploads/covers';
$TMP            = sys_get_temp_dir() . '/bb_upload_fixtures_' . getmypid();

$results = ['passed' => 0, 'failed' => 0, 'total' => 0, 'errors' => []];

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
    $results['errors'][] = "$id: $msg";
    echo "  \033[31m❌ $id\033[0m: $msg\n";
}

// ============================================================
// HTTP helper (cookie jar เดียวตลอด session)
// ============================================================
$COOKIE = tempnam(sys_get_temp_dir(), 'bbjar');

function http(string $method, string $url, array $fields = [], bool $multipart = false): array
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $COOKIE,
        CURLOPT_COOKIEFILE     => $COOKIE,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $fields : http_build_query($fields));
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['body' => (string) $body, 'code' => $code, 'type' => $type];
}

/** ดึง CSRF token ตัวแรกจาก HTML */
function csrf(string $html): string
{
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}

// ============================================================
// สร้างไฟล์ทดสอบ
// ============================================================
// 🧠 สร้างตอนรัน ไม่ commit ลง repo — ไฟล์อย่าง shell.php.jpg ไม่ควรอยู่ใน git
//    (ทั้งเรื่อง virus scanner ของ hosting และเรื่องความเข้าใจผิดว่าเป็นของจริง)
@mkdir($TMP, 0755, true);

$PNG_BYTES = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9QzzCKRsEoGgUEAQAmnwGBHDF9pQAAAABJRU5ErkJggg=='
);
$PHP_PAYLOAD = '<?php echo "UPLOAD-TEST-EXECUTED"; ?>' . "\n";

file_put_contents("$TMP/ok.png", $PNG_BYTES);                            // ควบคุม: PNG จริง
file_put_contents("$TMP/php_as_jpg.jpg", $PHP_PAYLOAD);                  // PHP เปลี่ยนนามสกุล
file_put_contents("$TMP/shell.php.jpg", $PHP_PAYLOAD);                   // นามสกุลซ้อน
file_put_contents("$TMP/html_as_png.png", "<html><body>x</body></html>\n");
file_put_contents("$TMP/evil.svg", '<svg xmlns="http://www.w3.org/2000/svg"></svg>' . "\n");
file_put_contents("$TMP/empty.jpg", '');
file_put_contents("$TMP/polyglot.png", $PNG_BYTES . $PHP_PAYLOAD);       // PNG จริง + PHP ต่อท้าย
file_put_contents("$TMP/toobig.png", $PNG_BYTES . str_repeat('A', 2 * 1024 * 1024 + 1000));

echo "\n╔════════════════════════════════════════════════════════════╗\n";
echo "║  Upload Security Tests — ทดสอบด้วยไฟล์จริง                 ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "  เป้าหมาย: $BASE_URL\n\n";

// ============================================================
// เตรียม: login + สร้างหนังสือสำหรับยิงไฟล์ใส่
// ============================================================
$r = http('GET', "$BASE_URL/login.php");
$r = http('POST', "$BASE_URL/login.php", [
    'csrf_token' => csrf($r['body']),
    'email'      => $ADMIN_EMAIL,
    'password'   => $ADMIN_PASSWORD,
]);
if (!str_contains($r['body'], 'Dashboard') && !str_contains($r['body'], 'ผู้ดูแลระบบ')) {
    echo "  ❌ login ไม่สำเร็จ — ตรวจรหัสผ่าน admin (ส่งเป็น argument ตัวแรกได้)\n\n";
    exit(1);
}

$pdo = getDB();
$TITLE = '[TEST-UPLOAD] เป้าทดสอบอัปโหลด';
$r = http('GET', "$BASE_URL/admin/book_form.php");
http('POST', "$BASE_URL/admin/book_form.php", [
    'csrf_token' => csrf($r['body']),
    'title'      => $TITLE,
    'author'     => '[TEST-UPLOAD]',
    'quantity'   => '1',
    'is_visible' => '1',
], false);

$stmt = $pdo->prepare("SELECT id FROM books WHERE title = ?");
$stmt->execute([$TITLE]);
$bookId = (int) $stmt->fetchColumn();
if (!$bookId) {
    echo "  ❌ สร้างหนังสือทดสอบไม่สำเร็จ\n\n";
    exit(1);
}

/**
 * อัปโหลดไฟล์ 1 ไฟล์เข้าฟอร์มแก้ไขหนังสือ แล้วคืนข้อความผลลัพธ์
 * $fakeMime = Content-Type ที่ "ปลอม" ส่งไปกับ request (ระบบต้องไม่เชื่อค่านี้)
 */
function upload(string $path, string $fakeMime): string
{
    global $BASE_URL, $bookId, $TITLE;
    $r = http('GET', "$BASE_URL/admin/book_form.php?id=$bookId");
    $r = http('POST', "$BASE_URL/admin/book_form.php?id=$bookId", [
        'csrf_token'  => csrf($r['body']),
        'id'          => (string) $bookId,
        'title'       => $TITLE,
        'author'      => '[TEST-UPLOAD]',
        'quantity'    => '1',
        'is_visible'  => '1',
        'cover_image' => new CURLFile($path, $fakeMime, basename($path)),
    ], true);
    return $r['body'];
}

// ============================================================
// 1) ไฟล์ที่ต้องถูกปฏิเสธ
// ============================================================
echo "── 1. ไฟล์ที่ต้องถูกปฏิเสธ ──\n";

$rejects = [
    'UP-01' => ['php_as_jpg.jpg',  'image/jpeg', 'ไฟล์ PHP เปลี่ยนนามสกุลเป็น .jpg'],
    'UP-02' => ['shell.php.jpg',   'image/jpeg', 'นามสกุลซ้อน .php.jpg'],
    'UP-03' => ['html_as_png.png', 'image/png',  'ไฟล์ HTML เปลี่ยนนามสกุลเป็น .png'],
    'UP-04' => ['evil.svg',        'image/svg+xml', 'SVG (ไม่อยู่ใน allowlist)'],
    'UP-05' => ['empty.jpg',       'image/jpeg', 'ไฟล์ว่าง 0 byte'],
];
foreach ($rejects as $id => [$file, $mime, $label]) {
    $body = upload("$TMP/$file", $mime);
    if (str_contains($body, 'รองรับเฉพาะไฟล์รูปภาพ')) {
        pass($id, "$label → ปฏิเสธ");
    } else {
        fail($id, "$label → ไม่ถูกปฏิเสธ (ระบบยอมรับไฟล์นี้)");
    }
}

$body = upload("$TMP/toobig.png", 'image/png');
str_contains($body, 'ขนาดไฟล์ต้องไม่เกิน')
    ? pass('UP-06', 'ไฟล์เกิน 2MB → ปฏิเสธ')
    : fail('UP-06', 'ไฟล์เกิน 2MB → ไม่ถูกปฏิเสธ');

// ============================================================
// 2) ไฟล์ที่ต้องผ่าน
// ============================================================
echo "\n── 2. ไฟล์ที่ต้องผ่าน ──\n";

$body = upload("$TMP/ok.png", 'image/png');
$stmt = $pdo->prepare("SELECT cover_image FROM books WHERE id = ?");
$stmt->execute([$bookId]);
$stored = (string) $stmt->fetchColumn();

$stored !== '' ? pass('UP-07', "PNG ปกติ → บันทึกเป็น $stored")
               : fail('UP-07', 'PNG ปกติ → ไม่ถูกบันทึก');

// ชื่อไฟล์ต้องถูกตั้งใหม่จาก MIME ไม่ใช่ชื่อเดิมของผู้ใช้
(preg_match('/^cover_\d+_[a-f0-9]+\.png$/', $stored) === 1)
    ? pass('UP-08', 'ชื่อไฟล์ถูกตั้งใหม่จาก MIME (ไม่ใช้ชื่อที่ผู้ใช้ส่งมา)')
    : fail('UP-08', "ชื่อไฟล์ไม่เป็นไปตามรูปแบบที่ตั้งใหม่: $stored");

// ============================================================
// 3) Polyglot — ผ่าน filter ได้ แต่ต้องรันไม่ได้
// ============================================================
echo "\n── 3. Polyglot (PNG จริง + PHP ต่อท้าย) ──\n";

upload("$TMP/polyglot.png", 'image/png');
$stmt->execute([$bookId]);
$poly = (string) $stmt->fetchColumn();

// 🧠 finfo เห็น PNG header ก่อน → ยอมรับไฟล์นี้ "ตามที่ออกแบบไว้"
//    ด่านที่ต้องกันจริงคือ Apache ต้องไม่รันมัน (นามสกุล .png + uploads/.htaccess)
$onDisk = "$COVER_DIR/$poly";
str_contains((string) @file_get_contents($onDisk), 'UPLOAD-TEST-EXECUTED')
    ? pass('UP-09', 'ผ่าน filter ได้ (finfo เห็นเป็น image/png) — ตามที่ออกแบบ')
    : fail('UP-09', 'ไม่พบ payload ในไฟล์ที่เก็บ — ทดสอบไม่ครบ');

$res = http('GET', "$BASE_URL/uploads/covers/$poly");
if (str_contains($res['body'], '<?php')) {
    pass('UP-10', 'เรียกผ่าน HTTP → ส่งกลับเป็นไบต์ดิบ ไม่ถูก execute');
} elseif (str_contains($res['body'], 'UPLOAD-TEST-EXECUTED')) {
    fail('UP-10', '🔴 ไฟล์ถูก EXECUTE! PHP ทำงานในโฟลเดอร์ uploads');
} else {
    fail('UP-10', 'ผลลัพธ์ไม่คาดคิด (HTTP ' . $res['code'] . ')');
}

$res['body'] === (string) @file_get_contents($onDisk)
    ? pass('UP-11', 'ไบต์ที่เสิร์ฟตรงกับไฟล์บนดิสก์ทุกไบต์')
    : fail('UP-11', 'ไบต์ที่เสิร์ฟไม่ตรงกับไฟล์บนดิสก์');

// ============================================================
// 4) ด่านสุดท้าย — uploads/.htaccess
// ============================================================
echo "\n── 4. ด่านสุดท้าย: uploads/.htaccess ──\n";
// 🧠 จำลองกรณี "ไฟล์ .php หลุดเข้าไปในโฟลเดอร์ได้ด้วยวิธีอื่น"
//    (bug ในอนาคต / ช่องโหว่อื่น / คนเผลอ copy) — .htaccess ต้องยังกันไว้อยู่
$probes = [
    'UP-12' => ['probe_shell.php',     'ไฟล์ .php'],
    'UP-13' => ['probe_shell.phtml',   'ไฟล์ .phtml'],
    'UP-14' => ['probe_shell.php.png', 'ไฟล์ .php.png (ลงท้าย .png)'],
];
foreach ($probes as $id => [$name, $label]) {
    file_put_contents("$COVER_DIR/$name", $PHP_PAYLOAD);
    $res = http('GET', "$BASE_URL/uploads/covers/$name");
    if ($res['code'] === 403) {
        pass($id, "$label → ถูกบล็อก (403)");
    } elseif (str_contains($res['body'], '<?php')) {
        pass($id, "$label → ส่งเป็นข้อความดิบ ไม่ถูก execute");
    } elseif (str_contains($res['body'], 'UPLOAD-TEST-EXECUTED')) {
        fail($id, "🔴 $label → ถูก EXECUTE!");
    } else {
        fail($id, "$label → ผลลัพธ์ไม่คาดคิด (HTTP {$res['code']})");
    }
    @unlink("$COVER_DIR/$name");
}

// ============================================================
// TEARDOWN
// ============================================================
echo "\n── ล้างข้อมูลทดสอบ ──\n";
$stmt->execute([$bookId]);
$lastCover = (string) $stmt->fetchColumn();
if ($lastCover !== '') {
    @unlink("$COVER_DIR/$lastCover");
}
$pdo->prepare("DELETE FROM books WHERE id = ?")->execute([$bookId]);

// ลบไฟล์ปกที่ไม่มีหนังสืออ้างถึงแล้ว (เผื่อรอบก่อนหน้าค้างไว้)
$referenced = $pdo->query("SELECT cover_image FROM books WHERE cover_image IS NOT NULL")
    ->fetchAll(PDO::FETCH_COLUMN);
$orphans = 0;
foreach (glob("$COVER_DIR/cover_*") ?: [] as $f) {
    if (!in_array(basename($f), $referenced, true)) {
        @unlink($f);
        $orphans++;
    }
}
array_map('unlink', glob("$TMP/*") ?: []);
@rmdir($TMP);
@unlink($COOKIE);
echo "  ลบหนังสือทดสอบ 1 เล่ม, ไฟล์ปกค้าง $orphans ไฟล์, fixture ชั่วคราวทั้งหมด\n";

// ============================================================
// SUMMARY
// ============================================================
$pct = $results['total'] > 0 ? round($results['passed'] / $results['total'] * 100, 1) : 0;
echo "\n══════════════════════════════════════\n";
echo " RESULTS: {$results['passed']}/{$results['total']} passed ($pct%)";
if ($results['failed'] > 0) {
    echo " | {$results['failed']} FAILED";
}
echo "\n══════════════════════════════════════\n\n";

exit($results['failed'] > 0 ? 1 : 0);
