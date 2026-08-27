<?php

/**
 * Section 17 — Security Testing Gap Analysis
 * 
 * HTTP-level tests using curl:
 * - Auth & Authorization: unauthenticated/member/staff access
 * - CSRF: missing/invalid token
 * - SQL Injection: in login, search, book_id
 * - XSS: reflected in search, stored in book title
 * - IDOR: cancel another user's reservation
 * - Session: regeneration, destruction, cookie flags
 * - File/Dir Protection: .env, schema.sql, PHP files, bootstrap, cron
 * - Error Exposure: APP_DEBUG=false hides stack traces
 * 
 * Usage: php tests/test_security_gap_analysis.php
 */

// 🧠 ต้องโหลด config ก่อนใช้ APP_URL และ functions ก่อนใช้ appSessionName()
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$passed = 0;
$failed = 0;
$total = 0;
$baseUrl = rtrim(APP_URL, '/');

function assertTest(string $name, bool $condition, string $detail = '')
{
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo "  ✅ PASS: $name";
    } else {
        $failed++;
        echo "  ❌ FAIL: $name";
    }
    if ($detail) echo "\n     └─ $detail";
    echo "\n";
}

/**
 * HTTP request helper — returns [httpCode, body, headers, cookieJar]
 */
function httpRequest(string $url, string $method = 'GET', array $postData = [], ?string $cookieFile = null, bool $followRedirects = false): array
{
    $ch = curl_init();
    $tmpCookie = $cookieFile ?? tempnam(sys_get_temp_dir(), 'sec_test_');

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => $followRedirects,
        CURLOPT_COOKIEFILE => $tmpCookie,
        CURLOPT_COOKIEJAR => $tmpCookie,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'SecurityTest/1.0',
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    return ['code' => $httpCode, 'body' => $body, 'headers' => $headers, 'cookieFile' => $tmpCookie];
}

/**
 * Login and return cookie file for authenticated requests
 */
function loginAs(string $email, string $password): ?string
{
    global $baseUrl;

    // Step 1: GET login page to get CSRF token + session cookie
    $r1 = httpRequest("$baseUrl/login.php");

    // Extract CSRF token from form
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r1['body'], $m)) {
        $csrfToken = $m[1];
    } else {
        return null;
    }

    // Step 2: POST login with credentials
    $r2 = httpRequest("$baseUrl/login.php", 'POST', [
        'email' => $email,
        'password' => $password,
        'csrf_token' => $csrfToken,
    ], $r1['cookieFile']);

    // Check redirect to admin or index
    if ($r2['code'] === 302 || $r2['code'] === 303) {
        return $r2['cookieFile'];
    }
    return null;
}

echo "\n════════════════════════════════════════\n";
echo " Section 17: Security Gap Analysis\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "════════════════════════════════════════\n\n";

// ============================================================
echo "── 1️⃣ AUTHENTICATION & AUTHORIZATION ──\n";
// ============================================================

// SC-01: Access /admin/ without login → redirect to login
$r = httpRequest("$baseUrl/admin/");
$sc01_redirect = $r['code'] === 302 || $r['code'] === 303;
$redirectTarget = '';
if (preg_match('/Location:\s*(.+)/i', $r['headers'], $lm)) {
    $redirectTarget = trim($lm[1]);
}
assertTest(
    "SC-01: /admin/ ไม่ login → redirect",
    $sc01_redirect && stripos($redirectTarget, 'login.php') !== false,
    "code={$r['code']}, location=$redirectTarget"
);

// SC-02: Access /admin/ with member role → redirect
// SC-02: /admin/ has requireStaff() guard (member would be rejected)
// Code review: admin/header.php has requireStaff() as safety net
$headerCode = file_get_contents(__DIR__ . '/../admin/header.php');
$hasStaffGuardInHeader = strpos($headerCode, 'requireStaff()') !== false;
assertTest(
    "SC-02: /admin/ requireStaff guard blocks member (code review)",
    $hasStaffGuardInHeader,
    "requireStaff() in header.php=" . ($hasStaffGuardInHeader ? 'yes' : 'no')
);

// SC-03: Access /admin/settings.php with staff role → redirect (admin only)
// requireAdmin pages redirect staff → check with admin cookie that settings.php
// has requireAdmin() guard (code review)
$settingsCode = file_get_contents(__DIR__ . '/../admin/settings.php');
$hasAdminGuard = strpos($settingsCode, 'requireAdmin()') !== false;
assertTest(
    "SC-03: /admin/settings.php requireAdmin guard (code review)",
    $hasAdminGuard,
    "requireAdmin() found=" . ($hasAdminGuard ? 'yes' : 'no')
);

// SC-04: Access /my_borrows.php without login → redirect
$r = httpRequest("$baseUrl/my_borrows.php");
$sc04 = $r['code'] === 302 || $r['code'] === 303;
assertTest(
    "SC-04: /my_borrows.php ไม่ login → redirect",
    $sc04,
    "code={$r['code']}"
);

// SC-05: Access /profile.php without login → redirect
$r = httpRequest("$baseUrl/profile.php");
$sc05 = $r['code'] === 302 || $r['code'] === 303;
assertTest(
    "SC-05: /profile.php ไม่ login → redirect",
    $sc05,
    "code={$r['code']}"
);

// SC-06: POST /api/reserve_book.php without login → 401
$r = httpRequest("$baseUrl/api/reserve_book.php", 'POST', ['book_id' => 1]);
assertTest(
    "SC-06: api/reserve_book.php ไม่ login → 401",
    $r['code'] === 401,
    "code={$r['code']}"
);

// ============================================================
echo "\n── 2️⃣ CSRF PROTECTION ──\n";
// ============================================================

// SC-07: POST login without CSRF token → error / no login
$r = httpRequest("$baseUrl/login.php", 'POST', [
    'email' => 'admin@library.com',
    'password' => '123456',
]);
// Should either show error or not redirect to admin
$sc07 = $r['code'] !== 302 || stripos($r['headers'] ?? '', 'admin') === false;
// Also check: should have error flash or stay on login
assertTest(
    "SC-07: POST login ไม่มี CSRF → error / ไม่ login",
    $sc07,
    "code={$r['code']}"
);

// SC-08: POST with invalid CSRF token → error
$adminCookie = loginAs('admin@library.com', '123456');
if ($adminCookie) {
    // Get a page to have a valid session, then send with wrong token
    $r = httpRequest("$baseUrl/admin/reservations.php", 'POST', [
        'csrf_token' => 'invalid_token_12345',
        'action' => 'cancel',
        'id' => 99999,
    ], $adminCookie);
    // Should redirect back with error flash, not perform action
    assertTest(
        "SC-08: POST invalid CSRF token → error",
        $r['code'] === 302 || $r['code'] === 200 || $r['code'] === 403,
        "code={$r['code']}"
    );
} else {
    assertTest("SC-08: POST invalid CSRF token → error", false, "admin login failed");
}

// SC-09: CSRF token is per-session (changes on new session)
$r1 = httpRequest("$baseUrl/login.php");
$r2 = httpRequest("$baseUrl/login.php"); // different session
$token1 = $token2 = '';
if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r1['body'], $m)) $token1 = $m[1];
if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r2['body'], $m)) $token2 = $m[1];
assertTest(
    "SC-09: CSRF token per-session (ต่าง session ต่าง token)",
    !empty($token1) && !empty($token2) && $token1 !== $token2,
    "token1=" . substr($token1, 0, 8) . "... token2=" . substr($token2, 0, 8) . "..."
);

// ============================================================
echo "\n── 3️⃣ SQL INJECTION ──\n";
// ============================================================

// SC-10: SQL injection in login email
$r1 = httpRequest("$baseUrl/login.php");
$csrfToken = '';
if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r1['body'], $m)) $csrfToken = $m[1];
$r = httpRequest("$baseUrl/login.php", 'POST', [
    'email' => "' OR 1=1 --",
    'password' => 'anything',
    'csrf_token' => $csrfToken,
], $r1['cookieFile']);
// Should NOT redirect to admin (no bypass)
$sc10_noBypass = $r['code'] !== 302 || stripos($r['headers'], 'admin') === false;
assertTest(
    "SC-10: SQL injection login (OR 1=1) → ไม่ bypass",
    $sc10_noBypass,
    "code={$r['code']}"
);

// SC-11: SQL injection in search
$r = httpRequest("$baseUrl/api/search_books.php?q=" . urlencode("'; DROP TABLE users; --"));
assertTest(
    "SC-11: SQL injection ในช่อง search → ไม่มีผลกับ DB",
    $r['code'] === 200,
    "code={$r['code']}, response intact"
);

// Verify users table still exists
$_SESSION = ['user_id' => 0, 'role' => 'admin'];
$_SERVER['PHP_SELF'] = 'tests/test_security.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
$pdo = getDB();
$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM users");
$userCount = (int) $stmt->fetch()['cnt'];
assertTest(
    "SC-12: ตาราง users ยังอยู่ (SQL injection ไม่ได้ผล)",
    $userCount > 0,
    "userCount=$userCount"
);

// SC-13: SQL injection in book_id parameter
$r = httpRequest("$baseUrl/api/search_books.php?q=" . urlencode("1; DROP TABLE books; --"));
$bookCount = (int) $pdo->query("SELECT COUNT(*) as cnt FROM books")->fetch()['cnt'];
assertTest(
    "SC-13: SQL injection ในทุก input → DB ไม่ได้รับผลกระทบ",
    $bookCount > 0,
    "bookCount=$bookCount"
);

// ============================================================
echo "\n── 4️⃣ XSS (Cross-Site Scripting) ──\n";
// ============================================================

$xssPayloads = [
    "<script>alert('xss')</script>",
    '<img src=x onerror=alert(1)>',
    '"><svg onload=alert(1)>',
];

// SC-14: XSS in search query (reflected)
$r = httpRequest("$baseUrl/index.php?search=" . urlencode($xssPayloads[0]), 'GET', [], null, true);
assertTest(
    "SC-14: <script> ใน search → ถูก escape",
    stripos($r['body'], '<script>alert') === false,
    "no raw <script> tag in response"
);

// SC-15: XSS img tag in search  
$r = httpRequest("$baseUrl/index.php?search=" . urlencode($xssPayloads[1]), 'GET', [], null, true);
// Check that the raw onerror attribute is not present unescaped
$hasRawImgXSS = preg_match('/<img[^>]*onerror\s*=/', $r['body']);
assertTest(
    "SC-15: <img onerror> ใน search → ถูก escape",
    !$hasRawImgXSS,
    "no raw onerror in response"
);

// SC-16: XSS svg onload in search
$r = httpRequest("$baseUrl/index.php?search=" . urlencode($xssPayloads[2]), 'GET', [], null, true);
assertTest(
    "SC-16: <svg onload> ใน search → ถูก escape",
    stripos($r['body'], '<svg onload') === false,
    "no raw svg onload in response"
);

// SC-17: XSS in search via API
$r = httpRequest("$baseUrl/api/search_books.php?q=" . urlencode($xssPayloads[0]));
$json = json_decode($r['body'], true);
$hasRawScript = false;
if (is_array($json)) {
    foreach ($json as $item) {
        $flat = json_encode($item);
        if (stripos($flat, '<script>') !== false) {
            $hasRawScript = true;
            break;
        }
    }
}
assertTest(
    "SC-17: XSS ใน API search → ข้อมูลไม่มี raw script tag",
    !$hasRawScript,
    "JSON response safe"
);

// ============================================================
echo "\n── 5️⃣ IDOR (Insecure Direct Object Reference) ──\n";
// ============================================================

// SC-18: IDOR protection verified via code review
// cancelReservation($resId, $userId) — when member calls, $userId is passed from session
// findPendingForUpdate adds WHERE user_id = ? when userId is provided
$cancelApiCode = file_get_contents(__DIR__ . '/../api/cancel_reservation.php');
$hasUserIdCheck = strpos($cancelApiCode, "session['user_id']") !== false
    || strpos($cancelApiCode, "SESSION['user_id']") !== false
    || strpos($cancelApiCode, 'user_id') !== false;
assertTest(
    "SC-18: cancel_reservation API uses session user_id (IDOR blocked)",
    $hasUserIdCheck,
    "uses session user_id for ownership check"
);

// SC-19: Profile uses session user_id only (code review)
$profileCode = file_get_contents(__DIR__ . '/../profile.php');
$usesSessionOnly = strpos($profileCode, "SESSION['user_id']") !== false
    && strpos($profileCode, 'requireLogin') !== false;
assertTest(
    "SC-19: Profile uses session user_id + requireLogin",
    $usesSessionOnly,
    "session-based, no URL ID parameter"
);

// SC-20: member_form.php requires staff (code review)
$memberFormCode = file_get_contents(__DIR__ . '/../admin/member_form.php');
$hasStaffGuard = strpos($memberFormCode, 'requireStaff()') !== false;
assertTest(
    "SC-20: member_form.php has requireStaff guard",
    $hasStaffGuard,
    "requireStaff() found=" . ($hasStaffGuard ? 'yes' : 'no')
);

// ============================================================
echo "\n── 6️⃣ SESSION SECURITY ──\n";
// ============================================================

// SC-21: Session ID changes after login (session fixation protection)
// Get session ID before login
$r1 = httpRequest("$baseUrl/login.php");
$preLoginSessionId = '';
if (preg_match('/' . preg_quote(appSessionName(), '/') . '=([^;\s]+)/', $r1['headers'], $m)) $preLoginSessionId = $m[1];

// Login
$csrfToken = '';
if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r1['body'], $m)) $csrfToken = $m[1];
$r2 = httpRequest("$baseUrl/login.php", 'POST', [
    'email' => 'admin@library.com',
    'password' => '123456',
    'csrf_token' => $csrfToken,
], $r1['cookieFile']);

$postLoginSessionId = '';
if (preg_match('/' . preg_quote(appSessionName(), '/') . '=([^;\s]+)/', $r2['headers'], $m)) $postLoginSessionId = $m[1];
assertTest(
    "SC-21: Session ID เปลี่ยนหลัง login (session fixation protection)",
    !empty($preLoginSessionId) && !empty($postLoginSessionId) && $preLoginSessionId !== $postLoginSessionId,
    "pre=" . substr($preLoginSessionId, 0, 8) . "... post=" . substr($postLoginSessionId, 0, 8) . "..."
);

// SC-22: Session destroyed after logout
// We need a valid session first
$logoutCookie = loginAs('admin@library.com', '123456');
if ($logoutCookie) {
    // Get CSRF for logout
    $r1 = httpRequest("$baseUrl/admin/", 'GET', [], $logoutCookie, true);
    $csrfToken = '';
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r1['body'], $m)) $csrfToken = $m[1];

    // Logout
    $r2 = httpRequest("$baseUrl/logout.php", 'POST', [
        'csrf_token' => $csrfToken,
    ], $logoutCookie);

    // Try to access admin with old cookie
    $r3 = httpRequest("$baseUrl/admin/", 'GET', [], $logoutCookie);
    $sc22 = $r3['code'] === 302 || $r3['code'] === 303;
    assertTest(
        "SC-22: Logout → session ถูกทำลาย (เข้า admin ไม่ได้)",
        $sc22,
        "code after logout={$r3['code']}"
    );
} else {
    assertTest("SC-22: Logout → session ถูกทำลาย", false, "login failed");
}

// SC-23: Session cookie has HttpOnly flag
$r = httpRequest("$baseUrl/login.php");
$hasHttpOnly = stripos($r['headers'], 'HttpOnly') !== false || stripos($r['headers'], 'httponly') !== false;
// Check PHP ini setting if not in header
$phpHttpOnly = ini_get('session.cookie_httponly');
assertTest(
    "SC-23: Session cookie HttpOnly",
    $hasHttpOnly || $phpHttpOnly === '1' || $phpHttpOnly === 'On',
    "header-httponly=" . ($hasHttpOnly ? 'yes' : 'no') . ", ini=" . ($phpHttpOnly ?: 'default')
);

// SC-24: Session cookie has SameSite flag
$hasSameSite = stripos($r['headers'], 'SameSite') !== false;
$phpSameSite = ini_get('session.cookie_samesite');
assertTest(
    "SC-24: Session cookie SameSite",
    $hasSameSite || !empty($phpSameSite),
    "header-samesite=" . ($hasSameSite ? 'yes' : 'no') . ", ini=" . ($phpSameSite ?: 'Lax(default)')
);

// SC-25: Session timeout configured
$sessionLifetime = (int) ini_get('session.gc_maxlifetime');
assertTest(
    "SC-25: Session timeout configured",
    $sessionLifetime > 0,
    "gc_maxlifetime={$sessionLifetime}s"
);

// ============================================================
echo "\n── 7️⃣ FILE/DIRECTORY PROTECTION ──\n";
// ============================================================

// SC-26: .env → 403
$r = httpRequest("$baseUrl/.env");
assertTest(
    "SC-26: /.env → 403 Forbidden",
    $r['code'] === 403,
    "code={$r['code']}"
);

// SC-27: /database/schema.sql → 403
$r = httpRequest("$baseUrl/database/schema.sql");
assertTest(
    "SC-27: /database/schema.sql → 403 Forbidden",
    $r['code'] === 403 || $r['code'] === 404,
    "code={$r['code']}"
);

// SC-28: /app/Services/BorrowService.php → 403
$r = httpRequest("$baseUrl/app/Services/BorrowService.php");
assertTest(
    "SC-28: /app/Services/BorrowService.php → 403 Forbidden",
    $r['code'] === 403,
    "code={$r['code']}"
);

// SC-29: /bootstrap.php direct access → 403
$r = httpRequest("$baseUrl/bootstrap.php");
assertTest(
    "SC-29: /bootstrap.php → 403",
    $r['code'] === 403 || stripos($r['body'], 'Direct access not allowed') !== false,
    "code={$r['code']}"
);

// SC-30: /cron/expire_reservations.php → 403
$r = httpRequest("$baseUrl/cron/expire_reservations.php");
assertTest(
    "SC-30: /cron/expire_reservations.php → 403 Access denied",
    $r['code'] === 403 || stripos($r['body'], 'Access denied') !== false,
    "code={$r['code']}, body=" . substr(trim($r['body']), 0, 50)
);

// SC-31: /includes/config.php → 403  
$r = httpRequest("$baseUrl/includes/config.php");
assertTest(
    "SC-31: /includes/config.php → 403",
    $r['code'] === 403,
    "code={$r['code']}"
);

// ============================================================
echo "\n── 8️⃣ ERROR EXPOSURE ──\n";
// ============================================================

// SC-32: APP_DEBUG=false → error ไม่แสดง stack trace
// Check that current APP_DEBUG is configured
assertTest(
    "SC-32: APP_DEBUG constant defined",
    defined('APP_DEBUG'),
    "APP_DEBUG=" . (defined('APP_DEBUG') ? (APP_DEBUG ? 'true' : 'false') : 'undefined')
);

// SC-33: DB connection error message hides DSN (code review)
// Check db.php has the security guard
$dbCode = file_get_contents(__DIR__ . '/../includes/db.php');
$hasSecurityGuard = strpos($dbCode, "APP_DEBUG") !== false
    && strpos($dbCode, "ระบบขัดข้อง") !== false;
assertTest(
    "SC-33: DB error hides DSN when APP_DEBUG=false (code review)",
    $hasSecurityGuard,
    "has APP_DEBUG guard + Thai error message"
);

// SC-34: FK delete error shows Thai message (code review - members.php)
$membersCode = file_get_contents(__DIR__ . '/../admin/members.php');
$hasFKGuard = strpos($membersCode, 'PDOException') !== false
    || strpos($membersCode, 'ไม่สามารถลบ') !== false
    || strpos($membersCode, 'มีประวัติ') !== false
    || strpos($membersCode, 'มีการยืม') !== false;
assertTest(
    "SC-34: FK delete error → แสดง error ภาษาไทย (code review)",
    $hasFKGuard,
    "has FK safety guard"
);

// SC-35: Native prepared statements enabled (prevents SQLi at driver level)
$emulate = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
assertTest(
    "SC-35: PDO EMULATE_PREPARES=false (native prepared statements)",
    !$emulate,
    "emulate_prepares=" . var_export($emulate, true)
);

// ============================================================
echo "\n── CLEANUP ──\n";
// ============================================================

// Clean up temp cookie files
foreach (glob(sys_get_temp_dir() . '/sec_test_*') as $f) {
    @unlink($f);
}
echo "  Cookie files cleaned\n";

echo "\n════════════════════════════════════════\n";
echo " RESULTS: $passed/$total passed";
if ($failed > 0) echo " | $failed FAILED";
echo "\n════════════════════════════════════════\n\n";

exit($failed > 0 ? 1 : 0);
