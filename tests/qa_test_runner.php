<?php
/**
 * QA Test Runner - HTTP Integration Tests
 * Runs actual HTTP requests against the system
 */

date_default_timezone_set('Asia/Bangkok');

$BASE_URL = 'http://localhost/book_borrowing';
$TIMESTAMP = time();
$LOG_FILE = __DIR__ . '/logs/qa_run_' . date('Y-m-d_His') . '.jsonl';

// Ensure logs directory exists
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

// Test data
$TEST_USER_EMAIL = "testuser_qa_{$TIMESTAMP}@test.com";
$TEST_USER_PASSWORD = 'Test123456';
$ADMIN_EMAIL = 'admin@gmail.com';
$ADMIN_PASSWORD = '123456';

// Session storage
$userSession = null;
$adminSession = null;
$csrfToken = null;
$testBookId = null;
$testMemberId = null;
$testCategoryId = null;
$testBorrowId = null;

// Results
$results = [
    'passed' => 0,
    'failed' => 0,
    'total' => 0,
    'details' => []
];

/**
 * Make HTTP request using cURL
 */
function httpRequest($method, $url, $data = [], $cookies = null, $headers = []) {
    $ch = curl_init();
    
    $fullUrl = $url;
    if ($method === 'GET' && !empty($data)) {
        $fullUrl .= '?' . http_build_query($data);
    }
    
    curl_setopt($ch, CURLOPT_URL, $fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    
    if ($cookies) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookies);
    }
    
    $defaultHeaders = ['Content-Type: application/x-www-form-urlencoded'];
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defaultHeaders, $headers));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => $error, 'status' => 0, 'headers' => '', 'body' => ''];
    }
    
    $responseHeaders = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    
    // Extract session cookie
    $sessionCookie = null;
    if (preg_match('/Set-Cookie:\s*PHPSESSID=([^;]+)/i', $responseHeaders, $m)) {
        $sessionCookie = 'PHPSESSID=' . $m[1];
    }
    
    return [
        'status' => $httpCode,
        'headers' => $responseHeaders,
        'body' => $body,
        'session' => $sessionCookie
    ];
}

/**
 * Get CSRF token from page
 */
function getCSRFToken($html) {
    if (preg_match('/name=["\']csrf_token["\']\s+value=["\']([^"\']+)["\']/', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/value=["\']([^"\']+)["\']\s+name=["\']csrf_token["\']/', $html, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Log test result
 */
function logResult($testId, $request, $response, $passed, $message = '') {
    global $LOG_FILE, $results;
    
    $results['total']++;
    if ($passed) {
        $results['passed']++;
    } else {
        $results['failed']++;
    }
    
    $logEntry = [
        'test_id' => $testId,
        'timestamp' => date('c'),
        'passed' => $passed,
        'message' => $message,
        'request' => [
            'method' => $request['method'],
            'url' => $request['url'],
            'body' => maskSecrets($request['body'] ?? [])
        ],
        'response' => [
            'status' => $response['status'],
            'body_length' => strlen($response['body'] ?? ''),
            'body_preview' => substr($response['body'] ?? '', 0, 500)
        ]
    ];
    
    $results['details'][] = $logEntry;
    
    file_put_contents($LOG_FILE, json_encode($logEntry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    
    $status = $passed ? '✅ PASS' : '❌ FAIL';
    echo "[$status] $testId: $message\n";
}

/**
 * Mask sensitive data
 */
function maskSecrets($data) {
    $masked = $data;
    foreach (['password', 'current_password', 'new_password', 'confirm_password'] as $key) {
        if (isset($masked[$key])) {
            $masked[$key] = '***';
        }
    }
    return $masked;
}

/**
 * Run single test
 */
function runTest($testId, $method, $endpoint, $data, $cookies, $expectedStatus, $check = null) {
    global $BASE_URL;
    
    $url = $BASE_URL . $endpoint;
    $response = httpRequest($method, $url, $data, $cookies);
    
    $passed = false;
    $message = '';
    
    if (isset($response['error'])) {
        $message = "Connection error: {$response['error']}";
    } elseif (is_array($expectedStatus)) {
        $passed = in_array($response['status'], $expectedStatus);
        $message = $passed ? "Status {$response['status']} OK" : "Expected " . implode('/', $expectedStatus) . ", got {$response['status']}";
    } else {
        $passed = $response['status'] == $expectedStatus;
        $message = $passed ? "Status {$response['status']} OK" : "Expected $expectedStatus, got {$response['status']}";
    }
    
    // Additional checks
    if ($passed && $check) {
        $checkResult = $check($response);
        if ($checkResult !== true) {
            $passed = false;
            $message = $checkResult;
        }
    }
    
    logResult($testId, ['method' => $method, 'url' => $url, 'body' => $data], $response, $passed, $message);
    
    return $response;
}

// ============================================================
// START TESTS
// ============================================================

echo "\n========================================\n";
echo "QA Test Runner - " . date('Y-m-d H:i:s') . "\n";
echo "Base URL: $BASE_URL\n";
echo "Log File: $LOG_FILE\n";
echo "========================================\n\n";

// --------------------------------------------------
// A. HAPPY PATH TESTS
// --------------------------------------------------
echo "\n--- HAPPY PATH TESTS ---\n";

// HP-01: Register new user
$resp = httpRequest('GET', "$BASE_URL/register.php");
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('HP-01', 'POST', '/register.php', [
    'name' => 'Test User QA',
    'email' => $TEST_USER_EMAIL,
    'password' => $TEST_USER_PASSWORD,
    'confirm_password' => $TEST_USER_PASSWORD,
    'csrf_token' => $csrfToken
], $resp['session'], 302);

// HP-02: Login as user
$resp = httpRequest('GET', "$BASE_URL/login.php");
$csrfToken = getCSRFToken($resp['body']);
$userSession = $resp['session'];
$resp = runTest('HP-02', 'POST', '/login.php', [
    'email' => $TEST_USER_EMAIL,
    'password' => $TEST_USER_PASSWORD
], $userSession, 302);
if ($resp['session']) $userSession = $resp['session'];

// HP-03: Login as admin
$resp = httpRequest('GET', "$BASE_URL/login.php");
$csrfToken = getCSRFToken($resp['body']);
$adminSession = $resp['session'];
$resp = runTest('HP-03', 'POST', '/login.php', [
    'email' => $ADMIN_EMAIL,
    'password' => $ADMIN_PASSWORD
], $adminSession, 302, function($r) {
    return strpos($r['headers'], 'Location') !== false ? true : 'No redirect';
});
if ($resp['session']) $adminSession = $resp['session'];

// HP-04: Logout
$resp = runTest('HP-04', 'GET', '/logout.php', [], $userSession, 302);

// Re-login user for further tests
$resp = httpRequest('GET', "$BASE_URL/login.php");
$userSession = $resp['session'];
httpRequest('POST', "$BASE_URL/login.php", [
    'email' => $TEST_USER_EMAIL,
    'password' => $TEST_USER_PASSWORD
], $userSession);
$resp = httpRequest('GET', "$BASE_URL/login.php");
if ($resp['session']) $userSession = $resp['session'];

// HP-05: Search books (no filter)
$resp = runTest('HP-05', 'GET', '/api/search_books.php', [], null, 200);

// HP-06: Search books with keyword
$resp = runTest('HP-06', 'GET', '/api/search_books.php', ['search' => 'test'], null, 200);

// HP-07: View book detail
$resp = runTest('HP-07', 'GET', '/book.php', ['id' => 1], null, [200, 302]);

// HP-08: Reserve a book (need logged in user)
$resp = httpRequest('GET', "$BASE_URL/login.php");
$userSession = $resp['session'];
$resp = httpRequest('POST', "$BASE_URL/login.php", [
    'email' => $TEST_USER_EMAIL,
    'password' => $TEST_USER_PASSWORD
], $userSession);
if ($resp['session']) $userSession = $resp['session'];

$resp = httpRequest('GET', "$BASE_URL/book.php?id=1", [], $userSession);
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('HP-08', 'POST', '/api/reserve_book.php', [
    'book_id' => 1,
    'csrf_token' => $csrfToken
], $userSession, [200, 400], function($r) {
    $json = json_decode($r['body'], true);
    return ($json !== null) ? true : 'Invalid JSON response';
});

// HP-09: Update profile
$resp = httpRequest('GET', "$BASE_URL/profile.php", [], $userSession);
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('HP-09', 'POST', '/profile.php', [
    'action' => 'update_profile',
    'name' => 'Test User QA Updated',
    'phone' => '0812345678',
    'csrf_token' => $csrfToken
], $userSession, 302);

// HP-10: Change password
$resp = httpRequest('GET', "$BASE_URL/profile.php", [], $userSession);
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('HP-10', 'POST', '/profile.php', [
    'action' => 'change_password',
    'current_password' => $TEST_USER_PASSWORD,
    'new_password' => 'NewPass123',
    'confirm_password' => 'NewPass123',
    'csrf_token' => $csrfToken
], $userSession, 302);

// Change password back
$resp = httpRequest('GET', "$BASE_URL/profile.php", [], $userSession);
$csrfToken = getCSRFToken($resp['body']);
httpRequest('POST', "$BASE_URL/profile.php", [
    'action' => 'change_password',
    'current_password' => 'NewPass123',
    'new_password' => $TEST_USER_PASSWORD,
    'confirm_password' => $TEST_USER_PASSWORD,
    'csrf_token' => $csrfToken
], $userSession);

// Admin tests - need admin session
$resp = httpRequest('GET', "$BASE_URL/login.php");
$adminSession = $resp['session'];
$resp = httpRequest('POST', "$BASE_URL/login.php", [
    'email' => $ADMIN_EMAIL,
    'password' => $ADMIN_PASSWORD
], $adminSession);
if ($resp['session']) $adminSession = $resp['session'];

// HP-11: Create book
$resp = httpRequest('GET', "$BASE_URL/admin/book_form.php", [], $adminSession);
$csrfToken = getCSRFToken($resp['body']);
$testBookTitle = "QA Test Book $TIMESTAMP";
$resp = runTest('HP-11', 'POST', '/admin/book_form.php', [
    'title' => $testBookTitle,
    'author' => 'QA Author',
    'quantity' => 5,
    'csrf_token' => $csrfToken
], $adminSession, 302);

// HP-16: Add category
$resp = httpRequest('GET', "$BASE_URL/admin/categories.php", [], $adminSession);
$csrfToken = getCSRFToken($resp['body']);
$testCategoryName = "QA Category $TIMESTAMP";
$resp = runTest('HP-16', 'POST', '/admin/categories.php', [
    'action' => 'add',
    'name' => $testCategoryName,
    'csrf_token' => $csrfToken
], $adminSession, 302);

// HP-13: Create member
$resp = httpRequest('GET', "$BASE_URL/admin/member_form.php", [], $adminSession);
$csrfToken = getCSRFToken($resp['body']);
$testMemberEmail = "member_qa_{$TIMESTAMP}@test.com";
$resp = runTest('HP-13', 'POST', '/admin/member_form.php', [
    'name' => 'QA Test Member',
    'email' => $testMemberEmail,
    'password' => 'Member123',
    'csrf_token' => $csrfToken
], $adminSession, 302);

// HP-17: Update settings
$resp = httpRequest('GET', "$BASE_URL/admin/settings.php", [], $adminSession);
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('HP-17', 'POST', '/admin/settings.php', [
    'org_name' => 'QA Test Library',
    'card_color_primary' => '#1e3a8a',
    'card_color_secondary' => '#3b82f6',
    'csrf_token' => $csrfToken
], $adminSession, 302);

// --------------------------------------------------
// B. VALIDATION TESTS
// --------------------------------------------------
echo "\n--- VALIDATION TESTS ---\n";

// VL-01: Register - empty email
$resp = httpRequest('GET', "$BASE_URL/register.php");
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('VL-01', 'POST', '/register.php', [
    'name' => 'Test',
    'email' => '',
    'password' => 'Test123',
    'confirm_password' => 'Test123'
], $resp['session'], 200, function($r) {
    return (strpos($r['body'], 'กรุณากรอกอีเมล') !== false || strpos($r['body'], 'error') !== false) ? true : 'No error message';
});

// VL-02: Register - invalid email
$resp = httpRequest('GET', "$BASE_URL/register.php");
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('VL-02', 'POST', '/register.php', [
    'name' => 'Test',
    'email' => 'invalid-email',
    'password' => 'Test123',
    'confirm_password' => 'Test123'
], $resp['session'], 200);

// VL-03: Register - short password
$resp = httpRequest('GET', "$BASE_URL/register.php");
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('VL-03', 'POST', '/register.php', [
    'name' => 'Test',
    'email' => 'test@test.com',
    'password' => '123',
    'confirm_password' => '123'
], $resp['session'], 200);

// VL-04: Register - password mismatch
$resp = httpRequest('GET', "$BASE_URL/register.php");
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('VL-04', 'POST', '/register.php', [
    'name' => 'Test',
    'email' => 'test2@test.com',
    'password' => 'Test123456',
    'confirm_password' => 'Different123'
], $resp['session'], 200);

// VL-05: Register - duplicate email
$resp = httpRequest('GET', "$BASE_URL/register.php");
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('VL-05', 'POST', '/register.php', [
    'name' => 'Test',
    'email' => $TEST_USER_EMAIL,
    'password' => 'Test123456',
    'confirm_password' => 'Test123456'
], $resp['session'], 200);

// VL-06: Login - wrong password
$resp = httpRequest('GET', "$BASE_URL/login.php");
$resp = runTest('VL-06', 'POST', '/login.php', [
    'email' => $TEST_USER_EMAIL,
    'password' => 'WrongPassword'
], $resp['session'], 200);

// VL-07: Login - non-existent email
$resp = httpRequest('GET', "$BASE_URL/login.php");
$resp = runTest('VL-07', 'POST', '/login.php', [
    'email' => 'nonexistent@test.com',
    'password' => 'Test123456'
], $resp['session'], 200);

// VL-08: Reserve - invalid book_id
$resp = httpRequest('GET', "$BASE_URL/index.php", [], $userSession);
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('VL-08', 'POST', '/api/reserve_book.php', [
    'book_id' => 0,
    'csrf_token' => $csrfToken
], $userSession, 400);

// VL-10: Profile - empty name
$resp = httpRequest('GET', "$BASE_URL/profile.php", [], $userSession);
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('VL-10', 'POST', '/profile.php', [
    'action' => 'update_profile',
    'name' => '',
    'csrf_token' => $csrfToken
], $userSession, 200);

// VL-11: Change password - wrong current
$resp = httpRequest('GET', "$BASE_URL/profile.php", [], $userSession);
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('VL-11', 'POST', '/profile.php', [
    'action' => 'change_password',
    'current_password' => 'WrongCurrent',
    'new_password' => 'NewPass123',
    'confirm_password' => 'NewPass123',
    'csrf_token' => $csrfToken
], $userSession, 200);

// VL-12: Book - empty title
$resp = httpRequest('GET', "$BASE_URL/admin/book_form.php", [], $adminSession);
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('VL-12', 'POST', '/admin/book_form.php', [
    'title' => '',
    'author' => 'Test Author',
    'csrf_token' => $csrfToken
], $adminSession, 200);

// VL-13: Book - empty author
$resp = httpRequest('GET', "$BASE_URL/admin/book_form.php", [], $adminSession);
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('VL-13', 'POST', '/admin/book_form.php', [
    'title' => 'Test Title',
    'author' => '',
    'csrf_token' => $csrfToken
], $adminSession, 200);

// VL-15: Category - empty name
$resp = httpRequest('GET', "$BASE_URL/admin/categories.php", [], $adminSession);
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('VL-15', 'POST', '/admin/categories.php', [
    'action' => 'add',
    'name' => '',
    'csrf_token' => $csrfToken
], $adminSession, 200);

// VL-16: Category - duplicate
$resp = httpRequest('GET', "$BASE_URL/admin/categories.php", [], $adminSession);
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('VL-16', 'POST', '/admin/categories.php', [
    'action' => 'add',
    'name' => $testCategoryName,
    'csrf_token' => $csrfToken
], $adminSession, 200);

// --------------------------------------------------
// C. EDGE CASES
// --------------------------------------------------
echo "\n--- EDGE CASE TESTS ---\n";

// EC-01: SQL injection attempt
$resp = runTest('EC-01', 'GET', '/api/search_books.php', ['search' => "' OR 1=1--"], null, 200);

// EC-02: XSS attempt
$resp = runTest('EC-02', 'GET', '/api/search_books.php', ['search' => '<script>alert(1)</script>'], null, 200, function($r) {
    return (strpos($r['body'], '<script>alert') === false) ? true : 'XSS not escaped';
});

// EC-03: Non-existent book
$resp = runTest('EC-03', 'GET', '/book.php', ['id' => 99999], null, 302);

// EC-04: Negative book ID
$resp = runTest('EC-04', 'GET', '/book.php', ['id' => -1], null, 302);

// EC-05: String book ID
$resp = runTest('EC-05', 'GET', '/book.php', ['id' => 'abc'], null, 302);

// EC-06: Reserve same book twice
$resp = httpRequest('GET', "$BASE_URL/book.php?id=1", [], $userSession);
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('EC-06', 'POST', '/api/reserve_book.php', [
    'book_id' => 1,
    'csrf_token' => $csrfToken
], $userSession, [200, 400]);

// EC-09: XSS in profile name
$resp = httpRequest('GET', "$BASE_URL/profile.php", [], $userSession);
$csrfToken = getCSRFToken($resp['body']);
$resp = runTest('EC-09', 'POST', '/profile.php', [
    'action' => 'update_profile',
    'name' => '<script>alert(1)</script>Test',
    'csrf_token' => $csrfToken
], $userSession, 302);

// --------------------------------------------------
// D. SECURITY TESTS
// --------------------------------------------------
echo "\n--- SECURITY TESTS ---\n";

// SC-01: POST without csrf_token
$resp = runTest('SC-01', 'POST', '/profile.php', [
    'action' => 'update_profile',
    'name' => 'Test'
], $userSession, 302, function($r) {
    return (strpos($r['headers'], 'Location') !== false) ? true : 'Should redirect';
});

// SC-02: POST with invalid csrf_token
$resp = runTest('SC-02', 'POST', '/profile.php', [
    'action' => 'update_profile',
    'name' => 'Test',
    'csrf_token' => 'invalid_token_12345'
], $userSession, 302);

// SC-03: Access admin without login
$resp = httpRequest('GET', "$BASE_URL/logout.php");
$noAuthSession = $resp['session'];
$resp = runTest('SC-03', 'GET', '/admin/index.php', [], $noAuthSession, 302);

// SC-04: Access admin as user
$resp = httpRequest('GET', "$BASE_URL/login.php");
$tempSession = $resp['session'];
httpRequest('POST', "$BASE_URL/login.php", [
    'email' => $TEST_USER_EMAIL,
    'password' => $TEST_USER_PASSWORD
], $tempSession);
$resp = httpRequest('GET', "$BASE_URL/login.php");
$tempSession = $resp['session'];
$resp = runTest('SC-04', 'GET', '/admin/index.php', [], $tempSession, 302);

// SC-05: GET on POST-only endpoint
$resp = runTest('SC-05', 'GET', '/api/reserve_book.php', [], $userSession, 405);

// SC-08: Access after logout
$resp = httpRequest('GET', "$BASE_URL/logout.php", [], $userSession);
$loggedOutSession = $resp['session'];
$resp = runTest('SC-08', 'GET', '/profile.php', [], $loggedOutSession, 302);

// SC-09: Reserve without login
$resp = runTest('SC-09', 'POST', '/api/reserve_book.php', [
    'book_id' => 1
], null, 401);

// SC-10: AJAX add member without admin
$resp = runTest('SC-10', 'POST', '/api/add_member.php', [
    'name' => 'Test',
    'email' => 'test@test.com'
], null, [302, 403]);

// ============================================================
// SUMMARY
// ============================================================

echo "\n========================================\n";
echo "TEST SUMMARY\n";
echo "========================================\n";
echo "Total:  {$results['total']}\n";
echo "Passed: {$results['passed']} (" . round($results['passed']/$results['total']*100, 1) . "%)\n";
echo "Failed: {$results['failed']} (" . round($results['failed']/$results['total']*100, 1) . "%)\n";
echo "Log: $LOG_FILE\n";
echo "========================================\n";

// Save summary
file_put_contents(__DIR__ . '/logs/summary.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Return exit code
exit($results['failed'] > 0 ? 1 : 0);
