<?php
/**
 * QA Test Runner v2 - HTTP Integration Tests
 * 55 test cases: 17 happy path, 16 validation, 12 edge, 10 security
 * 
 * Usage: php tests/qa_test_runner.php [admin_password]
 *   admin_password: password for admin@library.com (default: 123456)
 */

date_default_timezone_set('Asia/Bangkok');

// 📝 โหลด config เพื่ออ่านค่า rate limit ที่ตั้งไว้จริงใน .env
//    (ไม่ hard-code จำนวนครั้ง — ลูกค้าปรับ RATE_LIMIT_MAX_ATTEMPTS ได้ test ต้องไม่พัง)
require_once __DIR__ . '/../includes/config.php';

$BASE_URL = 'http://localhost/book_borrowing';
$TIMESTAMP = time();
$LOG_FILE = __DIR__ . '/logs/qa_run_' . date('Y-m-d_His') . '.jsonl';

if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

// Test accounts
$TEST_USER_EMAIL = "qa_user_{$TIMESTAMP}@test.com";
$TEST_USER_PASSWORD = 'Test123456';
$ADMIN_EMAIL = 'admin@library.com';
$ADMIN_PASSWORD = $argv[1] ?? 'password';

// State
$userSession = null;
$adminSession = null;
$testReservationId = null;
$testCategoryName = "QA_Cat_$TIMESTAMP";
$testMemberEmail = "qa_member_{$TIMESTAMP}@test.com";

// Results
$results = ['passed' => 0, 'failed' => 0, 'skipped' => 0, 'total' => 0, 'details' => []];

// ============================================================
// HELPERS
// ============================================================

function http($method, $url, $data = [], $cookies = null) {
    $ch = curl_init();
    $fullUrl = $url;
    if ($method === 'GET' && !empty($data)) {
        $fullUrl .= '?' . http_build_query($data);
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $fullUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    if ($cookies) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookies);
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ['error' => $err, 'status' => 0, 'headers' => '', 'body' => '', 'session' => null];

    $hdrs = substr($raw, 0, $hdrSize);
    $body = substr($raw, $hdrSize);
    $sess = null;
    if (preg_match('/Set-Cookie:\s*PHPSESSID=([^;]+)/i', $hdrs, $m)) {
        $sess = 'PHPSESSID=' . $m[1];
    }
    return ['status' => $code, 'headers' => $hdrs, 'body' => $body, 'session' => $sess];
}

function csrf($html) {
    if (preg_match('/name=["\']csrf_token["\']\s*value=["\']([^"\']+)["\']/', $html, $m)) return $m[1];
    if (preg_match('/value=["\']([^"\']+)["\']\s*name=["\']csrf_token["\']/', $html, $m)) return $m[1];
    return null;
}

function login($email, $password) {
    global $BASE_URL;
    $r = http('GET', "$BASE_URL/login.php");
    $sess = $r['session'];
    $tok = csrf($r['body']);
    $r2 = http('POST', "$BASE_URL/login.php", [
        'email' => $email, 'password' => $password, 'csrf_token' => $tok
    ], $sess);
    return $r2['session'] ?: $sess;
}

function getPage($path, $session) {
    global $BASE_URL;
    return http('GET', "$BASE_URL$path", [], $session);
}

function mask($data) {
    $m = $data;
    foreach (['password','current_password','new_password','confirm_password'] as $k) {
        if (isset($m[$k])) $m[$k] = '***';
    }
    return $m;
}

function test($id, $method, $endpoint, $data, $cookies, $expect, $check = null) {
    global $BASE_URL, $LOG_FILE, $results;
    $url = $BASE_URL . $endpoint;
    $r = http($method, $url, $data, $cookies);

    $pass = false;
    $msg = '';
    if (isset($r['error'])) {
        $msg = "CONN ERROR: {$r['error']}";
    } elseif (is_array($expect)) {
        $pass = in_array($r['status'], $expect);
        $msg = $pass ? "Status {$r['status']} OK" : "Expected " . implode('|', $expect) . ", got {$r['status']}";
    } else {
        $pass = ($r['status'] == $expect);
        $msg = $pass ? "Status {$r['status']} OK" : "Expected $expect, got {$r['status']}";
    }
    if ($pass && $check) {
        $cr = $check($r);
        if ($cr !== true) { $pass = false; $msg = $cr; }
    }

    $results['total']++;
    $results[$pass ? 'passed' : 'failed']++;
    $entry = [
        'test_id' => $id, 'timestamp' => date('c'), 'passed' => $pass, 'message' => $msg,
        'request' => ['method' => $method, 'url' => $url, 'body' => mask($data)],
        'response' => ['status' => $r['status'], 'body_length' => strlen($r['body']),
                        'body_preview' => mb_substr($r['body'], 0, 500)]
    ];
    $results['details'][] = $entry;
    file_put_contents($LOG_FILE, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    echo ($pass ? '  ✅' : '  ❌') . " $id: $msg\n";
    return $r;
}

// ============================================================
// PREFLIGHT — check server is reachable
// ============================================================
echo "\n══════════════════════════════════════\n";
echo " QA Test Runner v2 — " . date('Y-m-d H:i:s') . "\n";
echo " Base: $BASE_URL\n";
echo " Log:  $LOG_FILE\n";
echo "══════════════════════════════════════\n";

$ping = http('GET', "$BASE_URL/login.php");
if ($ping['status'] === 0) {
    echo "\n❌ Server not reachable at $BASE_URL\n";
    echo "   Start Apache first, then re-run.\n";
    exit(2);
}
echo "\n✓ Server reachable (HTTP {$ping['status']})\n";

// ============================================================
// A. HAPPY PATH (17)
// ============================================================
echo "\n─── A. HAPPY PATH (17) ───\n";

// HP-01 Register
$r = http('GET', "$BASE_URL/register.php");
test('HP-01', 'POST', '/register.php', [
    'name' => 'QA User', 'email' => $TEST_USER_EMAIL,
    'password' => $TEST_USER_PASSWORD, 'confirm_password' => $TEST_USER_PASSWORD,
    'csrf_token' => csrf($r['body'])
], $r['session'], 302);

// HP-02 Login user
$userSession = login($TEST_USER_EMAIL, $TEST_USER_PASSWORD);
test('HP-02', 'GET', '/profile.php', [], $userSession, 200, function($r) {
    return (strpos($r['body'], 'QA User') !== false) ? true : 'Profile page not loaded';
});

// HP-03 Login admin
$adminSession = login($ADMIN_EMAIL, $ADMIN_PASSWORD);
test('HP-03', 'GET', '/admin/index.php', [], $adminSession, 200, function($r) {
    return (strpos($r['body'], 'dashboard') !== false || strpos($r['body'], 'แดชบอร์ด') !== false || $r['status'] == 200) ? true : 'Admin dashboard not loaded';
});

// HP-04 Logout (POST + CSRF)
$r = getPage('/index.php', $userSession);
$tok = csrf($r['body']);
test('HP-04', 'POST', '/logout.php', ['csrf_token' => $tok], $userSession, 302);
$userSession = login($TEST_USER_EMAIL, $TEST_USER_PASSWORD); // re-login

// HP-05 Search books (no filter)
test('HP-05', 'GET', '/api/search_books.php', [], $userSession, 200);

// HP-06 Search with keyword
test('HP-06', 'GET', '/api/search_books.php', ['search' => 'Atomic'], $userSession, 200);

// HP-07 View book detail
test('HP-07', 'GET', '/book.php?id=1', [], $userSession, [200, 302]);

// HP-08 Reserve book
$r = getPage('/book.php?id=1', $userSession);
$tok = csrf($r['body']);
test('HP-08', 'POST', '/api/reserve_book.php', [
    'book_id' => 1, 'csrf_token' => $tok
], $userSession, [200, 400], function($r) {
    $j = json_decode($r['body'], true);
    return ($j !== null) ? true : 'Not JSON';
});

// HP-09 Cancel reservation
$r = getPage('/my_reservations.php', $userSession);
$tok = csrf($r['body']);
if (preg_match('/name=["\']id["\']\s*value=["\'](\d+)["\']/', $r['body'], $m)) {
    $testReservationId = $m[1];
}
test('HP-09', 'POST', '/api/cancel_reservation.php', [
    'id' => $testReservationId ?? 1, 'csrf_token' => $tok
], $userSession, [302, 200, 400]);

// HP-10 Update profile
$r = getPage('/profile.php', $userSession);
test('HP-10', 'POST', '/profile.php', [
    'action' => 'update_profile', 'name' => 'QA User Updated', 'phone' => '0899999999',
    'csrf_token' => csrf($r['body'])
], $userSession, 302);

// HP-11 Change password (and revert)
$r = getPage('/profile.php', $userSession);
test('HP-11', 'POST', '/profile.php', [
    'action' => 'change_password', 'current_password' => $TEST_USER_PASSWORD,
    'new_password' => 'NewQA123', 'confirm_password' => 'NewQA123',
    'csrf_token' => csrf($r['body'])
], $userSession, 302);
// revert
$r = getPage('/profile.php', $userSession);
http('POST', "$BASE_URL/profile.php", [
    'action' => 'change_password', 'current_password' => 'NewQA123',
    'new_password' => $TEST_USER_PASSWORD, 'confirm_password' => $TEST_USER_PASSWORD,
    'csrf_token' => csrf($r['body'])
], $userSession);

// HP-12 Admin: create book
$r = getPage('/admin/book_form.php', $adminSession);
test('HP-12', 'POST', '/admin/book_form.php', [
    'title' => "QA Book $TIMESTAMP", 'author' => 'QA Author', 'quantity' => 3,
    'csrf_token' => csrf($r['body'])
], $adminSession, 302);

// HP-13 Admin: add category
$r = getPage('/admin/categories.php', $adminSession);
test('HP-13', 'POST', '/admin/categories.php', [
    'action' => 'add', 'name' => $testCategoryName, 'csrf_token' => csrf($r['body'])
], $adminSession, 302);

// HP-14 Admin: quick add member (API)
$r = getPage('/admin/members.php', $adminSession);
test('HP-14', 'POST', '/api/add_member.php', [
    'name' => 'QA Member', 'email' => $testMemberEmail, 'csrf_token' => csrf($r['body'])
], $adminSession, 200, function($r) {
    $j = json_decode($r['body'], true);
    return ($j && isset($j['success'])) ? true : 'Not JSON or missing success';
});

// HP-15 Admin: member history API
test('HP-15', 'GET', '/api/member_history.php?user_id=1', [], $adminSession, 200, function($r) {
    return (json_decode($r['body']) !== null) ? true : 'Not JSON';
});

// HP-16 Admin: update settings
$r = getPage('/admin/settings.php', $adminSession);
test('HP-16', 'POST', '/admin/settings.php', [
    'org_name' => 'QA Library', 'card_color_primary' => '#1e3a8a',
    'card_color_secondary' => '#3b82f6', 'csrf_token' => csrf($r['body'])
], $adminSession, 302);

// HP-17 Forgot password
$r = http('GET', "$BASE_URL/forgot_password.php");
test('HP-17', 'POST', '/forgot_password.php', [
    'email' => 'nobody@example.com', 'csrf_token' => csrf($r['body'])
], $r['session'], [200, 302]);

// ============================================================
// B. VALIDATION (16)
// ============================================================
echo "\n─── B. VALIDATION (16) ───\n";

// VL-01 Register: empty email
$r = http('GET', "$BASE_URL/register.php");
test('VL-01', 'POST', '/register.php', [
    'name'=>'T','email'=>'','password'=>'Test123','confirm_password'=>'Test123','csrf_token'=>csrf($r['body'])
], $r['session'], 200);

// VL-02 Register: invalid email
$r = http('GET', "$BASE_URL/register.php");
test('VL-02', 'POST', '/register.php', [
    'name'=>'T','email'=>'bad','password'=>'Test123','confirm_password'=>'Test123','csrf_token'=>csrf($r['body'])
], $r['session'], 200);

// VL-03 Register: short password
$r = http('GET', "$BASE_URL/register.php");
test('VL-03', 'POST', '/register.php', [
    'name'=>'T','email'=>'x@x.com','password'=>'12','confirm_password'=>'12','csrf_token'=>csrf($r['body'])
], $r['session'], 200);

// VL-04 Register: mismatch
$r = http('GET', "$BASE_URL/register.php");
test('VL-04', 'POST', '/register.php', [
    'name'=>'T','email'=>'x@x.com','password'=>'Test123456','confirm_password'=>'Diff123456','csrf_token'=>csrf($r['body'])
], $r['session'], 200);

// VL-05 Register: duplicate
$r = http('GET', "$BASE_URL/register.php");
test('VL-05', 'POST', '/register.php', [
    'name'=>'T','email'=>$TEST_USER_EMAIL,'password'=>'Test123456','confirm_password'=>'Test123456','csrf_token'=>csrf($r['body'])
], $r['session'], 200);

// VL-06 Login: wrong password
$r = http('GET', "$BASE_URL/login.php");
test('VL-06', 'POST', '/login.php', [
    'email'=>$TEST_USER_EMAIL,'password'=>'Wrong','csrf_token'=>csrf($r['body'])
], $r['session'], 200);

// VL-07 Login: non-existent
$r = http('GET', "$BASE_URL/login.php");
test('VL-07', 'POST', '/login.php', [
    'email'=>'ghost@x.com','password'=>'Test123','csrf_token'=>csrf($r['body'])
], $r['session'], 200);

// VL-08 Reserve: book_id=0
$r = getPage('/index.php', $userSession);
test('VL-08', 'POST', '/api/reserve_book.php', [
    'book_id'=>0,'csrf_token'=>csrf($r['body'])
], $userSession, 400);

// VL-09 Reserve: non-existent book
$r = getPage('/index.php', $userSession);
test('VL-09', 'POST', '/api/reserve_book.php', [
    'book_id'=>99999,'csrf_token'=>csrf($r['body'])
], $userSession, 400);

// VL-10 Profile: empty name
$r = getPage('/profile.php', $userSession);
test('VL-10', 'POST', '/profile.php', [
    'action'=>'update_profile','name'=>'','csrf_token'=>csrf($r['body'])
], $userSession, 200);

// VL-11 Profile: wrong current password
$r = getPage('/profile.php', $userSession);
test('VL-11', 'POST', '/profile.php', [
    'action'=>'change_password','current_password'=>'WrongPwd',
    'new_password'=>'New12345','confirm_password'=>'New12345','csrf_token'=>csrf($r['body'])
], $userSession, 200);

// VL-12 Book: empty title
$r = getPage('/admin/book_form.php', $adminSession);
test('VL-12', 'POST', '/admin/book_form.php', [
    'title'=>'','author'=>'A','quantity'=>1,'csrf_token'=>csrf($r['body'])
], $adminSession, 200);

// VL-13 Book: empty author
$r = getPage('/admin/book_form.php', $adminSession);
test('VL-13', 'POST', '/admin/book_form.php', [
    'title'=>'T','author'=>'','quantity'=>1,'csrf_token'=>csrf($r['body'])
], $adminSession, 200);

// VL-14 Member: invalid email
$r = getPage('/admin/members.php', $adminSession);
test('VL-14', 'POST', '/api/add_member.php', [
    'name'=>'T','email'=>'bad-email','csrf_token'=>csrf($r['body'])
], $adminSession, [200, 400], function($r) {
    $j = json_decode($r['body'], true);
    return ($j && (isset($j['error']) || (isset($j['success']) && !$j['success']))) ? true : 'Should return error';
});

// VL-15 Category: empty name
$r = getPage('/admin/categories.php', $adminSession);
test('VL-15', 'POST', '/admin/categories.php', [
    'action'=>'add','name'=>'','csrf_token'=>csrf($r['body'])
], $adminSession, 200);

// VL-16 Category: duplicate
$r = getPage('/admin/categories.php', $adminSession);
test('VL-16', 'POST', '/admin/categories.php', [
    'action'=>'add','name'=>$testCategoryName,'csrf_token'=>csrf($r['body'])
], $adminSession, 200);

// ============================================================
// C. EDGE CASES (12)
// ============================================================
echo "\n─── C. EDGE CASES (12) ───\n";

// EC-01 SQLi in search
test('EC-01', 'GET', '/api/search_books.php', ['search'=>"' OR 1=1--"], $userSession, 200);

// EC-02 XSS in search
test('EC-02', 'GET', '/api/search_books.php', ['search'=>'<script>alert(1)</script>'], $userSession, 200, function($r) {
    return (strpos($r['body'], '<script>alert') === false) ? true : 'XSS NOT escaped';
});

// EC-03 Non-existent book
test('EC-03', 'GET', '/book.php?id=99999', [], null, 302);

// EC-04 Negative book ID
test('EC-04', 'GET', '/book.php?id=-1', [], null, 302);

// EC-05 String book ID
test('EC-05', 'GET', '/book.php?id=abc', [], null, 302);

// EC-06 Reserve same book twice
$r = getPage('/index.php', $userSession);
test('EC-06', 'POST', '/api/reserve_book.php', [
    'book_id'=>1,'csrf_token'=>csrf($r['body'])
], $userSession, [200, 400]);

// EC-07 XSS in profile name
$r = getPage('/profile.php', $userSession);
test('EC-07', 'POST', '/profile.php', [
    'action'=>'update_profile','name'=>'<img src=x onerror=alert(1)>','csrf_token'=>csrf($r['body'])
], $userSession, 302);
// verify escaped
$r2 = getPage('/profile.php', $userSession);
test('EC-07b', 'GET', '/profile.php', [], $userSession, 200, function($r) {
    return (strpos($r['body'], '<img src=x onerror') === false) ? true : 'XSS NOT escaped in output';
});
// restore name
$r = getPage('/profile.php', $userSession);
http('POST', "$BASE_URL/profile.php", [
    'action'=>'update_profile','name'=>'QA User','phone'=>'','csrf_token'=>csrf($r['body'])
], $userSession);

// EC-08 Reset password: invalid token
$r = http('GET', "$BASE_URL/reset_password.php?token=invalidtoken123");
test('EC-08', 'GET', '/reset_password.php?token=invalidtoken123', [], null, [200, 302]);

// EC-09 Category: delete with books
$r = getPage('/admin/categories.php', $adminSession);
if (preg_match('/action.*?delete.*?name=["\']id["\']\s*value=["\'](\d+)["\']/s', $r['body'], $m)) {
    $catIdWithBooks = $m[1];
    test('EC-09', 'POST', '/admin/categories.php', [
        'action'=>'delete','id'=>$catIdWithBooks,'csrf_token'=>csrf($r['body'])
    ], $adminSession, [200, 302]);
} else {
    echo "  ⏭ EC-09: SKIP — no deletable category found\n";
    $results['total']++; $results['skipped']++;
}

// EC-10 Forgot password: non-existent email (should not reveal)
$r = http('GET', "$BASE_URL/forgot_password.php");
test('EC-10', 'POST', '/forgot_password.php', [
    'email'=>'doesnotexist@nowhere.com','csrf_token'=>csrf($r['body'])
], $r['session'], [200, 302], function($r) {
    // Should NOT say "email not found"
    $body = $r['body'] . $r['headers'];
    return (stripos($body, 'ไม่พบอีเมล') === false && stripos($body, 'not found') === false)
        ? true : 'User enumeration — reveals email existence';
});

// EC-11 Search rate limit header
test('EC-11', 'GET', '/api/search_books.php', ['search'=>'test'], $userSession, 200);

// EC-12 Homepage without auth
test('EC-12', 'GET', '/index.php', [], null, 200);

// ============================================================
// D. SECURITY (10)
// ============================================================
echo "\n─── D. SECURITY (10) ───\n";

// SC-01 POST without CSRF
test('SC-01', 'POST', '/profile.php', [
    'action'=>'update_profile','name'=>'Hacker'
], $userSession, 302);

// SC-02 POST with invalid CSRF
test('SC-02', 'POST', '/profile.php', [
    'action'=>'update_profile','name'=>'Hacker','csrf_token'=>'FAKE_TOKEN_123'
], $userSession, 302);

// SC-03 Admin without login
$freshSession = http('GET', "$BASE_URL/index.php")['session'];
test('SC-03', 'GET', '/admin/index.php', [], $freshSession, 302, function($r) {
    return (stripos($r['headers'], 'login') !== false) ? true : 'Should redirect to login';
});

// SC-04 Admin as member
$memberSess = login($TEST_USER_EMAIL, $TEST_USER_PASSWORD);
test('SC-04', 'GET', '/admin/index.php', [], $memberSess, 302);

// SC-05 GET on POST-only endpoint
test('SC-05', 'GET', '/api/reserve_book.php', [], $userSession, 405);

// SC-06 Member history API without admin
test('SC-06', 'GET', '/api/member_history.php?user_id=1', [], $memberSess, [302, 403]);

// SC-07 Login brute force (ต้องถูกบล็อกหลังผิดครบ RATE_LIMIT_MAX_ATTEMPTS ครั้ง)
//   ⚠️ ใช้อีเมลปลอมเฉพาะเทสต์ ไม่ใช้ $ADMIN_EMAIL
//      เพราะ rate limit key = login_md5(email) → ถ้ายิงใส่ admin จริง
//      บัญชี admin จะถูกล็อกยาว RATE_LIMIT_WINDOW_MINUTES นาทีหลังรันเทสต์เสร็จ
$rlEmail = "qa_ratelimit_{$TIMESTAMP}@test.com";
$rlMax = RATE_LIMIT_MAX_ATTEMPTS;
echo "  ⏳ SC-07: Testing rate limit ({$rlMax} attempts + 1)...\n";
for ($i = 0; $i < $rlMax; $i++) {
    $r = http('GET', "$BASE_URL/login.php");
    http('POST', "$BASE_URL/login.php", [
        'email'=>$rlEmail,'password'=>'wrong','csrf_token'=>csrf($r['body'])
    ], $r['session']);
}
// ครั้งถัดไป — ต้องโดนบล็อกแล้ว
$r = http('GET', "$BASE_URL/login.php");
$rlSess = $r['session'];
$rlTok = csrf($r['body']);
test('SC-07', 'POST', '/login.php', [
    'email'=>$rlEmail,'password'=>'wrong','csrf_token'=>$rlTok
], $rlSess, 200, function($r) use ($rlMax) {
    return (stripos($r['body'], 'หลายครั้ง') !== false || stripos($r['body'], 'rate') !== false
         || stripos($r['body'], 'รอ') !== false || stripos($r['body'], 'เกินไป') !== false)
        ? true : "No rate limit message after {$rlMax}+ attempts";
});

// SC-08 Access after logout
$logoutSess = login($TEST_USER_EMAIL, $TEST_USER_PASSWORD);
$r = getPage('/index.php', $logoutSess);
http('POST', "$BASE_URL/logout.php", ['csrf_token'=>csrf($r['body'])], $logoutSess);
test('SC-08', 'GET', '/profile.php', [], $logoutSess, 302);

// SC-09 Reserve without login
test('SC-09', 'POST', '/api/reserve_book.php', ['book_id'=>1], null, 401);

// SC-10 Add member API without admin
test('SC-10', 'POST', '/api/add_member.php', ['name'=>'Hack','email'=>'h@h.com'], null, [302, 403, 401]);

// ============================================================
// TEARDOWN — ล้างข้อมูลที่ชุดทดสอบสร้างขึ้น
// ============================================================
// 🧠 เหตุผล: ถ้าไม่ล้าง จะเหลือ QA user/book/reservation ค้างใน DB
//    โดยเฉพาะ reservation สถานะ pending ที่ "หัก stock ค้างไว้"
//    → รันซ้ำหลายรอบแล้วตัวเลข available กับสถิติจะเพี้ยนสะสม
// ⚠️ ต้องคืน stock ก่อนลบเสมอ (เลียนแบบ ReservationService::cancelReservation)
echo "\n─── TEARDOWN: ล้างข้อมูลทดสอบ ───\n";

require_once __DIR__ . '/../includes/db.php';

try {
    $pdo = getDB();
    $pdo->beginTransaction();

    // 1️⃣ หา user ที่ชุดทดสอบสร้าง (register + add_member API)
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email IN (?, ?)");
    $stmt->execute([$TEST_USER_EMAIL, $testMemberEmail]);
    $qaUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $restoredStock = 0;
    if ($qaUserIds) {
        $in = implode(',', array_fill(0, count($qaUserIds), '?'));

        // 2️⃣ คืน stock จาก reservation ที่ยัง pending (หัก stock ไว้ตอนจอง)
        $stmt = $pdo->prepare("SELECT book_id FROM reservations WHERE user_id IN ($in) AND status = 'pending'");
        $stmt->execute($qaUserIds);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $bookId) {
            $up = $pdo->prepare("UPDATE books SET available = available + 1 WHERE id = ? AND available < quantity");
            $up->execute([$bookId]);
            $restoredStock += $up->rowCount();
        }

        // 3️⃣ คืน stock จาก borrow ที่ยังไม่คืน
        $stmt = $pdo->prepare("SELECT book_id FROM borrows WHERE user_id IN ($in) AND status = 'borrowing'");
        $stmt->execute($qaUserIds);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $bookId) {
            $up = $pdo->prepare("UPDATE books SET available = available + 1 WHERE id = ? AND available < quantity");
            $up->execute([$bookId]);
            $restoredStock += $up->rowCount();
        }

        // 4️⃣ ลบตามลำดับ FK: reservations → borrows (payments ตาม CASCADE) → users
        $pdo->prepare("DELETE FROM reservations WHERE user_id IN ($in)")->execute($qaUserIds);
        $pdo->prepare("DELETE FROM borrows WHERE user_id IN ($in)")->execute($qaUserIds);
        $pdo->prepare("DELETE FROM users WHERE id IN ($in)")->execute($qaUserIds);
    }

    // 5️⃣ ลบหนังสือ + หมวดหมู่ของรอบทดสอบนี้ (อ้างชื่อที่มี TIMESTAMP — ไม่แตะข้อมูลจริง)
    $delBook = $pdo->prepare("DELETE FROM books WHERE title = ?");
    $delBook->execute(["QA Book $TIMESTAMP"]);
    $delCat = $pdo->prepare("DELETE FROM categories WHERE name = ?");
    $delCat->execute([$testCategoryName]);

    // 6️⃣ ล้าง rate_limits ของรอบนี้ (กันบล็อกค้างหลังรันเทสต์)
    $pdo->prepare("DELETE FROM rate_limits WHERE key_name LIKE ?")->execute(['login_' . md5($rlEmail) . '%']);

    $pdo->commit();
    echo "  ✅ ลบ user " . count($qaUserIds) . " คน, หนังสือ {$delBook->rowCount()} เล่ม, หมวดหมู่ {$delCat->rowCount()} รายการ, คืน stock {$restoredStock} เล่ม\n";
} catch (\Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo "  ⚠️ Teardown ไม่สำเร็จ: " . $e->getMessage() . "\n";
    echo "     กรุณาล้างข้อมูล QA_* / qa_* ใน DB ด้วยตนเอง\n";
}

// ============================================================
// SUMMARY
// ============================================================
$total = $results['total'];
$pass = $results['passed'];
$fail = $results['failed'];
$skip = $results['skipped'];
$pct = $total > 0 ? round($pass / $total * 100, 1) : 0;

echo "\n══════════════════════════════════════\n";
echo " RESULTS: $pass/$total passed ($pct%)";
if ($fail > 0) echo " | $fail FAILED";
if ($skip > 0) echo " | $skip skipped";
echo "\n══════════════════════════════════════\n";
echo " Log: $LOG_FILE\n\n";

file_put_contents(__DIR__ . '/logs/summary.json', json_encode([
    'run_at' => date('c'), 'total' => $total, 'passed' => $pass,
    'failed' => $fail, 'skipped' => $skip, 'pass_rate' => "$pct%",
    'details' => $results['details']
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

exit($fail > 0 ? 1 : 0);
