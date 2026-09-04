<?php

/**
 * Test Import Flow via Curl
 * 
 * 1. Login as Admin
 * 2. Upload Valid Books -> Check Success
 * 3. Upload Invalid Books -> Check Start/Skip
 * 4. Upload BOM Books -> Check Success
 * 5. Check DB state
 */

require_once __DIR__ . '/../bootstrap.php';

$baseUrl = rtrim(APP_URL, '/');
$cookieFile = __DIR__ . '/logs/cookie_import_test.txt';
if (!file_exists(__DIR__ . '/logs')) mkdir(__DIR__ . '/logs');
if (file_exists($cookieFile)) unlink($cookieFile);

function curlPost($url, $data, $files = [])
{
    global $cookieFile;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    // Prepare multipart if files exist
    if (!empty($files)) {
        foreach ($files as $key => $path) {
            $data[$key] = new CURLFile($path);
        }
    }

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    $response = curl_exec($ch);
    $info = curl_getinfo($ch);

    return ['body' => $response, 'info' => $info];
}

function curlGet($url)
{
    global $cookieFile;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    return $response;
}

// 1. Login
// 🧠 เดิมฝังบัญชี qa_admin@library.com + รหัส password123 ไว้ตายตัว
//    ซึ่งไม่มีอยู่จริงในระบบ → "Login Failed" ทุกครั้ง เทสต์นี้เลยไม่เคยทำงานเลยสักรอบ
//    เปลี่ยนมาใช้บัญชี admin จริง และรับรหัสผ่านทาง argument แบบเดียวกับชุดอื่น
$adminEmail    = ADMIN_EMAIL;
$adminPassword = $argv[1] ?? '123456';

echo "1. Logging in as Admin...\n";

// Helper to extract CSRF
function getCSRF($html)
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

// Real Login Flow
$loginPage = curlGet($baseUrl . '/login.php');
$token = getCSRF($loginPage);
$res = curlPost($baseUrl . '/login.php', [
    'email' => $adminEmail,
    'password' => $adminPassword,
    'csrf_token' => $token
]);

if (strpos($res['body'], 'Dashboard') !== false || strpos($res['body'], 'ออกจากระบบ') !== false) {
    echo "✅ Login Successful\n";
} else {
    echo "❌ Login Failed\n";
    exit(1);
}

// 2. Import Books (Valid)
echo "\n2. Testing Import Books (Valid)...\n";
$importPage = curlGet($baseUrl . '/admin/import_books.php');
$token = getCSRF($importPage);
$res = curlPost($baseUrl . '/admin/import_books.php', [
    'csrf_token' => $token
], [
    'csv_file' => __DIR__ . '/fixtures/books_valid.csv'
]);

if (strpos($res['body'], 'นำเข้าเสร็จสิ้น') !== false) {
    echo "✅ Success Message Found\n";
    // Check specific counts if possible via regex
    if (preg_match('/เพิ่มใหม่ (\d+) รายการ, อัปเดต (\d+) รายการ/', $res['body'], $m)) {
        echo "   - Created: $m[1], Updated: $m[2]\n";
    }
} else {
    echo "❌ Import Failed\n";
    file_put_contents(__DIR__ . '/logs/error_import_valid.html', $res['body']);
}

// 3. Import Books (BOM)
echo "\n3. Testing Import Books (BOM)...\n";
$importPage = curlGet($baseUrl . '/admin/import_books.php');
$token = getCSRF($importPage);
$res = curlPost($baseUrl . '/admin/import_books.php', [
    'csrf_token' => $token
], [
    'csv_file' => __DIR__ . '/fixtures/books_bom.csv'
]);

if (strpos($res['body'], 'นำเข้าเสร็จสิ้น') !== false) {
    echo "✅ BOM Support Works\n";
} else {
    echo "❌ BOM Import Failed\n";
    // Log content to see if it failed due to header mismatch
    file_put_contents(__DIR__ . '/logs/error_import_bom.html', $res['body']);
}

// 4. Import Books (Invalid)
echo "\n4. Testing Import Books (Invalid)...\n";
$importPage = curlGet($baseUrl . '/admin/import_books.php');
$token = getCSRF($importPage);
$res = curlPost($baseUrl . '/admin/import_books.php', [
    'csrf_token' => $token
], [
    'csv_file' => __DIR__ . '/fixtures/books_invalid.csv'
]);

if (strpos($res['body'], 'รายการที่ไม่สำเร็จ') !== false) {
    echo "✅ Skipped Items Warning Found (Success)\n";
} else {
    echo "❌ Warning Missing\n";
}

// 5. Import Members (Upsert)
echo "\n5. Testing Import Members (Upsert)...\n";
$importPage = curlGet($baseUrl . '/admin/import_members.php');
$token = getCSRF($importPage);
$res = curlPost($baseUrl . '/admin/import_members.php', [
    'csrf_token' => $token
], [
    'csv_file' => __DIR__ . '/fixtures/members_upsert.csv'
]);

if (strpos($res['body'], 'นำเข้าเสร็จสิ้น') !== false) {
    echo "✅ Member Upsert Success\n";
} else {
    echo "❌ Member Import Failed\n";
}

// ── TEARDOWN ──
// 🧹 เก็บกวาดข้อมูลที่ import เข้ามา (อ้างอิงจากไฟล์ใน tests/fixtures/*.csv)
//    เดิมไม่มีส่วนนี้เลย → ทิ้งหนังสือ 6 เล่ม สมาชิก และหมวดหมู่ไว้ในฐานข้อมูลทุกครั้ง
//    (ไม่สะสมเพิ่มเพราะ import ข้ามรายการซ้ำ แต่ก็ไม่ควรทิ้งขยะไว้ตั้งแต่แรก)
try {
    require_once __DIR__ . '/../includes/db.php';
    $cleanupPdo = getDB();

    $bookTitles  = ['Test Import Book 1', 'Test Import Book 2', 'Test Import Book 3',
                    'BOM Book', 'Test Fail 2', 'Test Fail 3'];
    $memberMails = ['import1@test.com', 'import2@test.com'];
    $catNames    = ['Fiction', 'NewCategory', 'General', 'BOM Cat', 'Cat'];

    $inBooks = implode(',', array_fill(0, count($bookTitles), '?'));
    $inMails = implode(',', array_fill(0, count($memberMails), '?'));
    $inCats  = implode(',', array_fill(0, count($catNames), '?'));

    // 📌 ลบตามลำดับ FK: payments → reservations → borrows → books/users → categories
    $bookIdSql = "SELECT id FROM books WHERE title IN ($inBooks)";
    $cleanupPdo->prepare("DELETE FROM payments WHERE borrow_id IN (SELECT id FROM borrows WHERE book_id IN ($bookIdSql))")->execute($bookTitles);
    $cleanupPdo->prepare("DELETE FROM reservations WHERE book_id IN ($bookIdSql)")->execute($bookTitles);
    $cleanupPdo->prepare("DELETE FROM borrows WHERE book_id IN ($bookIdSql)")->execute($bookTitles);
    $cleanupPdo->prepare("DELETE FROM books WHERE title IN ($inBooks)")->execute($bookTitles);
    $cleanupPdo->prepare("DELETE FROM users WHERE email IN ($inMails)")->execute($memberMails);
    // 🧠 ลบหมวดหมู่เฉพาะที่ยังไม่มีหนังสือผูกอยู่ (import สร้างหมวดหมู่ใหม่ให้อัตโนมัติ)
    $cleanupPdo->prepare("DELETE FROM categories WHERE name IN ($inCats)
                          AND id NOT IN (SELECT DISTINCT category_id FROM books WHERE category_id IS NOT NULL)")->execute($catNames);

    echo "\n🧹 เก็บกวาดข้อมูลทดสอบแล้ว\n";
} catch (Throwable $e) {
    echo "\n⚠️ เก็บกวาดไม่ครบ: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
