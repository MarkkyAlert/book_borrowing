<?php

/**
 * Section 4 — Authentication (Forgot/Reset Password) Gap Analysis
 * 
 * Tests:
 * ── Happy Path ─────────────────────────
 * AU-01: Request Reset (Valid Email) → Success + Token generated
 * AU-02: Validate Token (Valid) → Return token data
 * AU-03: Reset Password (Valid) → Success + Password changed + Token used
 * AU-04: Login with New Password → Success
 * AU-05: Login with Old Password → Fail
 * 
 * ── Failure Cases ──────────────────────
 * AU-06: Request Reset (Invalid Email) → Success (Enumeration protection) + No token
 * AU-07: Validate Token (Invalid) → Return null
 * AU-08: Validate Token (Expired) → Return null
 * AU-09: Validate Token (Used) → Return null
 * AU-10: Reset Password (Expired Token) → Fail
 * AU-11: Reset Password (Used Token) → Fail
 * 
 * ── Rate Limiting ──────────────────────
 * AU-12: Request > 3 times/hour → Fail (Rate limit)
 * 
 * Usage: php tests/test_authentication_gap_analysis.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/../app/Repositories/UserRepository.php';
require_once __DIR__ . '/../app/Repositories/PasswordResetRepository.php';

use App\Services\AuthService;

$pdo = getDB();
$authService = new AuthService($pdo);
$passResetRepo = new \App\Repositories\PasswordResetRepository($pdo);

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
    $email = 'auth_' . $tag . '_' . time() . mt_rand(100, 999) . '@test.com';
    $password = 'Password123!';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, phone) VALUES (?, ?, ?, 'member', '0800000000')");
    $stmt->execute(["Auth $tag", $email, $hash]);
    return ['id' => (int)$pdo->lastInsertId(), 'email' => $email, 'password' => $password];
}

echo "\n════════════════════════════════════════\n";
echo " Section 4: Authentication Security\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "════════════════════════════════════════\n";

// ============================================================
echo "\n── 1️⃣ HAPPY PATH ──\n";
// ============================================================

$user1 = createTestUser('happy');
echo "  Created user: {$user1['email']}\n";

// AU-01: Request Reset (Valid Email)
$req1 = $authService->requestPasswordReset($user1['email']);
assertTest(
    "AU-01: Request Reset (Valid Email) → Success + Token",
    $req1['success'] === true && !empty($req1['token']),
    "token=" . substr($req1['token'] ?? '', 0, 10) . "..."
);

$token1 = $req1['token'];

// AU-02: Validate Token (Valid)
$valid1 = $authService->validateResetToken($token1);
assertTest(
    "AU-02: Validate Token (Valid) → Return data",
    $valid1 !== null && $valid1['email'] === $user1['email'],
    "email=" . ($valid1['email'] ?? 'null')
);

// AU-03: Reset Password (Valid)
$newPass = 'NewPassword789!';
$reset1 = $authService->resetPassword($token1, $newPass);
assertTest(
    "AU-03: Reset Password (Valid) → Success",
    $reset1['success'] === true,
    "success=" . ($reset1['success'] ? 'true' : 'false')
);

// AU-04: Login with New Password
$loginNew = $authService->login($user1['email'], $newPass);
assertTest(
    "AU-04: Login with New Password → Success",
    $loginNew !== null && $loginNew['id'] === $user1['id'],
    "login=" . ($loginNew ? 'success' : 'fail')
);

// AU-05: Login with Old Password
$loginOld = $authService->login($user1['email'], $user1['password']);
assertTest(
    "AU-05: Login with Old Password → Fail",
    $loginOld === null,
    "login=" . ($loginOld ? 'success' : 'fail')
);


// ============================================================
echo "\n── 2️⃣ FAILURE CASES ──\n";
// ============================================================

// AU-06: Request Reset (Invalid Email)
$resInvalid = $authService->requestPasswordReset('nonexistent@test.com');
assertTest(
    "AU-06: Request Reset (Invalid Email) → Success (Enumeration protection)",
    $resInvalid['success'] === true && ($resInvalid['token'] ?? null) === null,
    "success=" . ($resInvalid['success'] ? 'true' : 'false') . ", token=" . ($resInvalid['token'] ?? 'null')
);

// AU-07: Validate Token (Random/Invalid)
$validInvalid = $authService->validateResetToken('invalid_token_string_xxxxxxxx');
assertTest(
    "AU-07: Validate Token (Invalid) → Null",
    $validInvalid === null,
    "result=" . ($validInvalid ? 'found' : 'null')
);

// AU-08: Validate Token (Expired) & AU-10
// Manually insert expired token
$user2 = createTestUser('expired');
$tokenExpired = bin2hex(random_bytes(32));
$passResetRepo->create($user2['email'], $tokenExpired, date('Y-m-d H:i:s', strtotime('-1 hour')));

$validExpired = $authService->validateResetToken($tokenExpired);
assertTest(
    "AU-08: Validate Token (Expired) → Null",
    $validExpired === null,
    "result=" . ($validExpired ? 'found' : 'null')
);

$resetExpired = $authService->resetPassword($tokenExpired, 'Pass1234');
assertTest(
    "AU-10: Reset Password (Expired Token) → Fail",
    $resetExpired['success'] === false,
    "error=" . ($resetExpired['error'] ?? 'none')
);

// AU-09: Validate Token (Used) & AU-11
// Manually insert and mark used
$user3 = createTestUser('used');
$tokenUsed = bin2hex(random_bytes(32));
$insertId = $passResetRepo->create($user3['email'], $tokenUsed, date('Y-m-d H:i:s', strtotime('+1 hour')));
$passResetRepo->markUsed($insertId);

$validUsed = $authService->validateResetToken($tokenUsed);
assertTest(
    "AU-09: Validate Token (Used) → Null",
    $validUsed === null,
    "result=" . ($validUsed ? 'found' : 'null')
);

$resetUsed = $authService->resetPassword($tokenUsed, 'Pass1234');
assertTest(
    "AU-11: Reset Password (Used Token) → Fail",
    $resetUsed['success'] === false,
    "error=" . ($resetUsed['error'] ?? 'none')
);


// ============================================================
echo "\n── 3️⃣ RATE LIMITING ──\n";
// ============================================================

// AU-12: Request > 3 times/hour
$userRate = createTestUser('ratelimit');
$limitReached = false;
$limitMsg = '';
$tokens = [];

// Request 1
$r1 = $authService->requestPasswordReset($userRate['email']);
$tokens[] = $r1['token'];
// Request 2
$r2 = $authService->requestPasswordReset($userRate['email']);
$tokens[] = $r2['token'];
// Request 3
$r3 = $authService->requestPasswordReset($userRate['email']);
$tokens[] = $r3['token'];

// Request 4 (Should fail)
$r4 = $authService->requestPasswordReset($userRate['email']);
if (!$r4['success']) {
    $limitReached = true;
    $limitMsg = $r4['error'] ?? '';
}

assertTest(
    "AU-12: Request 4th time -> Fail (Rate Limit)",
    $limitReached,
    "error=" . $limitMsg
);


// ============================================================
echo "\n── CLEANUP ──\n";
// ============================================================
// Clean up users and password resets
$pdo->exec("DELETE FROM password_resets WHERE email LIKE 'auth_%'");
$pdo->exec("DELETE FROM users WHERE email LIKE 'auth_%'");
echo "  Test data cleaned\n";

echo "\n════════════════════════════════════════\n";
echo " RESULTS: $passed/$total passed";
if ($failed > 0) echo " | $failed FAILED";
echo "\n════════════════════════════════════════\n\n";

exit($failed > 0 ? 1 : 0);
