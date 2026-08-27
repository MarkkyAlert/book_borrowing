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
    curl_close($ch);

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
    curl_close($ch);
    return $response;
}

// 1. Login
echo "1. Logging in as Admin...\n";
$res = curlPost($baseUrl . '/login.php', [
    'email' => 'qa_admin@library.com',
    'password' => 'password123',
    'csrf_token' => 'mock_token_if_needed_but_login_page_generates_one'
    // Wait, login needs CSRF. I need to GET login page first to parse token.
]);

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
    'email' => 'qa_admin@library.com',
    'password' => 'password123',
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

echo "\nDone.\n";
