<?php

/**
 * Section 5 — Profile Security Gap Analysis
 * 
 * Tests:
 * ── Happy Path ─────────────────────────
 * PF-01: Update Profile (Name/Phone) → Success + DB updated
 * PF-03: Change Password (Valid) → Success + Login with new pass works
 * 
 * ── Failure Cases ──────────────────────
 * PF-04: Change Password (Wrong Old Pass) → Fail
 * PF-05: Change Password (New = Old) → Fail
 * 
 * ── Security ───────────────────────────
 * PF-02: Email Immutability → Update with new email → DB keeps OLD email
 * PF-06: Rate Limit (Password Change) → Block after 5 attempts
 * 
 * Usage: php tests/test_profile_security.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// Mock REMOTE_ADDR for rate limit
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/../app/Repositories/UserRepository.php';

use App\Services\AuthService;

$pdo = getDB();
$authService = new AuthService($pdo);
$userRepo = new \App\Repositories\UserRepository($pdo);

$passed = 0;
$failed = 0;
$total = 0;

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

function createTestUser(string $tag): array
{
    global $pdo;
    $email = 'prof_' . $tag . '_' . time() . mt_rand(100, 999) . '@test.com';
    $password = 'Password123!';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, phone) VALUES (?, ?, ?, 'member', '0800000000')");
    $stmt->execute(["Prof $tag", $email, $hash]);
    return ['id' => (int)$pdo->lastInsertId(), 'email' => $email, 'password' => $password];
}

echo "\n════════════════════════════════════════\n";
echo " Section 5: Profile Security\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "════════════════════════════════════════\n";

// ============================================================
echo "\n── PROFILE UPDATE ──\n";
// ============================================================

$user1 = createTestUser('update');
echo "  Created user: {$user1['email']} (ID: {$user1['id']})\n";

// PF-01: Update Name/Phone
$newName = "Updated Name " . time();
$newPhone = "0999999999";
$update1 = $authService->updateProfile($user1['id'], [
    'name' => $newName,
    'phone' => $newPhone
]);
$u1 = $userRepo->findById($user1['id']);

assertTest(
    "PF-01: Update Profile (Name/Phone) → Success",
    $update1 === true && $u1['name'] === $newName && $u1['phone'] === $newPhone,
    "name={$u1['name']}, phone={$u1['phone']}"
);

// PF-02: Email Immutability
// Try to change email in $data
$hackerEmail = "hacker@test.com";
$update2 = $authService->updateProfile($user1['id'], [
    'name' => $newName,
    'phone' => $newPhone,
    'email' => $hackerEmail // malicious input
]);
$u2 = $userRepo->findById($user1['id']);

assertTest(
    "PF-02: Email Immutability → Email not changed",
    $u2['email'] === $user1['email'] && $u2['email'] !== $hackerEmail,
    "current_email={$u2['email']}"
);


// ============================================================
echo "\n── PASSWORD CHANGE ──\n";
// ============================================================

// PF-03: Change Password (Happy)
$newPass = "NewPass789!";
$change1 = $authService->changePassword($user1['id'], $user1['password'], $newPass);
$checkLogin = $authService->login($user1['email'], $newPass);

assertTest(
    "PF-03: Change Password (Valid) → Success",
    $change1['success'] === true && $checkLogin !== null,
    "login=" . ($checkLogin ? 'OK' : 'Fail')
);

// PF-04: Change Password (Wrong Old)
$change2 = $authService->changePassword($user1['id'], "WrongPass123!", "SomePass888!");
assertTest(
    "PF-04: Change Password (Wrong Old Pass) → Fail",
    $change2['success'] === false && strpos($change2['error'], 'รหัสผ่านปัจจุบันไม่ถูกต้อง') !== false,
    "error=" . ($change2['error'] ?? 'none')
);

// PF-05: Change Password (New = Old)
$change3 = $authService->changePassword($user1['id'], $newPass, $newPass);
assertTest(
    "PF-05: Change Password (New = Old) → Fail",
    $change3['success'] === false && strpos($change3['error'], 'รหัสผ่านใหม่ต้องไม่ซ้ำ') !== false,
    "error=" . ($change3['error'] ?? 'none')
);


// ============================================================
echo "\n── RATE LIMITING ──\n";
// ============================================================

// PF-06: Rate Limit (Password Change)
// Using 'password_change' key as used in profile.php
$limitKey = 'password_change';
$mockIp = '127.0.0.1'; // set in code at top

// First, reset any existing limit
resetRateLimit($limitKey);

// Simulate MAX attempts (usually 5)
$max = RATE_LIMIT_MAX_ATTEMPTS; // from config
echo "  Max attempts: $max\n";

for ($i = 0; $i < $max; $i++) {
    $allowed = checkRateLimit($limitKey);
    incrementRateLimit($limitKey); // Assume failure increments
}

// Next check should fail
$blocked = !checkRateLimit($limitKey);
assertTest(
    "PF-06: Rate Limit Logic → Blocked after $max attempts",
    $blocked === true,
    "blocked=" . ($blocked ? 'true' : 'false')
);


// ============================================================
echo "\n── CLEANUP ──\n";
// ============================================================
$pdo->exec("DELETE FROM users WHERE email LIKE 'prof_%'");
$pdo->exec("DELETE FROM rate_limits WHERE key_name LIKE 'password_change_%'");
echo "  Test data cleaned\n";

echo "\n════════════════════════════════════════\n";
echo " RESULTS: $passed/$total passed";
if ($failed > 0) echo " | $failed FAILED";
echo "\n════════════════════════════════════════\n\n";

exit($failed > 0 ? 1 : 0);
