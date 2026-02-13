<?php

/**
 * Reset Password - รีเซ็ตรหัสผ่าน (ใช้ token จาก forgot_password)
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้า public — ต้องมี ?token=XXX ที่ valid ถึงจะแสดง form
 * - Token ต้อง: ตรงกับ DB, ยังไม่เคยใช้ (used=0), ยังไม่หมดอายุ
 * 
 * 📂 Flow:
 * 1. GET ?token=XXX → AuthService::validateResetToken() → ถ้า valid แสดง form
 * 2. POST → AuthService::resetPassword(token, newPassword) → เปลี่ยน password + mark token used
 * 3. สำเร็จ → redirect ไป login.php
 * 
 * 🔗 มาจาก: forgot_password.php (สร้าง token)
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/bootstrap.php';

// 🔁 ถ้า login แล้ว → ไม่ต้อง reset
if (isLoggedIn()) {
    redirect(APP_URL . '/index.php');
}

$errors = [];
$success = false;
$validToken = false;        // true = token valid → แสดง form
$token = $_GET['token'] ?? ''; // รับ token จาก URL (?token=XXX)

$pdo = getDB();

use App\Services\AuthService;

$authService = new AuthService($pdo);

// ── Step 1: ตรวจสอบ token ก่อนแสดง form ──
// 🔍 ตรวจ: token ตรงกับ DB, ยังไม่เคยใช้ (used=0), ยังไม่หมดอายุ
if (!empty($token)) {
    $resetRequest = $authService->validateResetToken($token);
    if ($resetRequest) {
        $validToken = true;
    }
}

// ── Step 2: POST → เปลี่ยนรหัสผ่าน (เฉพาะถ้า token valid) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    // 🛡️ [SECURITY] CSRF
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    }

    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // 🔍 Validation ผ่าน helper function (Single Source of Truth)
    if (empty($errors)) {
        if ($err = validatePassword($password)) {
            $errors[] = $err;
        }
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'รหัสผ่านไม่ตรงกัน';
    }

    if (empty($errors)) {
        // 🚀 [WRITE] เปลี่ยนรหัสผ่าน + mark token เป็น used (ใช้ได้ครั้งเดียว)
        //    ทำใน transaction: hash password + UPDATE users + UPDATE token.used=1
        $result = $authService->resetPassword($token, $password);

        if ($result['success']) {
            $success = true;
        } else {
            $errors[] = $result['error'];
        }
    }
}

$pageTitle = 'รีเซ็ตรหัสผ่าน';
require_once __DIR__ . '/includes/header.php';
?>

<div class="min-h-[80vh] flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gray-50 bg-pattern">
    <div class="max-w-md w-full space-y-8 relative z-10">
        <!-- Card -->
        <div class="bg-white rounded-3xl shadow-2xl p-8 sm:p-10 border border-gray-200">
            <div class="text-center mb-8">
                <div class="mx-auto h-16 w-16 bg-green-500 rounded-2xl flex items-center justify-center shadow-lg shadow-green-500/30 transform rotate-3 hover:rotate-0 transition-all duration-300">
                    <i class="bi bi-shield-lock text-3xl text-white"></i>
                </div>
                <h2 class="mt-6 text-3xl font-extrabold text-gray-900">
                    รีเซ็ตรหัสผ่าน
                </h2>
                <p class="mt-2 text-sm text-gray-600">
                    กรอกรหัสผ่านใหม่ของคุณ
                </p>
            </div>

            <?php if ($success): ?>
                <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6 rounded-r-lg">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="bi bi-check-circle-fill text-green-500 text-xl"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-green-700 font-medium">
                                เปลี่ยนรหัสผ่านสำเร็จ!
                            </p>
                            <p class="text-sm text-green-600 mt-1">
                                คุณสามารถเข้าสู่ระบบด้วยรหัสผ่านใหม่ได้แล้ว
                            </p>
                        </div>
                    </div>
                </div>

                <div class="text-center">
                    <a href="login.php" class="inline-flex items-center justify-center w-full py-3 px-4 border border-transparent text-sm font-bold rounded-xl text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 shadow-lg shadow-primary-500/30 transition-all hover:-translate-y-0.5">
                        <i class="bi bi-box-arrow-in-right mr-2"></i>
                        ไปหน้าเข้าสู่ระบบ
                    </a>
                </div>

            <?php elseif (!$validToken): ?>
                <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-r-lg">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="bi bi-exclamation-circle-fill text-red-500 text-xl"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-red-700 font-medium">
                                ลิงก์ไม่ถูกต้องหรือหมดอายุ
                            </p>
                            <p class="text-sm text-red-600 mt-1">
                                กรุณาขอลิงก์รีเซ็ตรหัสผ่านใหม่
                            </p>
                        </div>
                    </div>
                </div>

                <div class="text-center space-y-4">
                    <a href="forgot_password.php" class="inline-flex items-center justify-center w-full py-3 px-4 border border-transparent text-sm font-bold rounded-xl text-white bg-amber-500 hover:bg-amber-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500 shadow-lg shadow-amber-500/30 transition-all hover:-translate-y-0.5">
                        <i class="bi bi-key mr-2"></i>
                        ขอลิงก์ใหม่
                    </a>
                    <a href="login.php" class="block text-sm text-gray-600 hover:text-primary-600 transition-colors">
                        <i class="bi bi-arrow-left mr-1"></i>
                        กลับไปหน้าเข้าสู่ระบบ
                    </a>
                </div>

            <?php else: ?>
                <?php if (!empty($errors)): ?>
                    <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-r-lg animate-fade-in-down">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="bi bi-exclamation-circle-fill text-red-500"></i>
                            </div>
                            <div class="ml-3">
                                <ul class="list-disc list-inside text-sm text-red-700">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?= e($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form class="space-y-6" method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                            รหัสผ่านใหม่
                        </label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="bi bi-lock text-gray-400"></i>
                            </div>
                            <input id="password" name="password" type="password" required
                                class="focus:ring-primary-500 focus:border-primary-500 block w-full pl-10 sm:text-sm border-gray-300 rounded-xl py-3 transition-colors"
                                placeholder="••••••••" minlength="6">
                        </div>
                        <p class="mt-1 text-xs text-gray-500">อย่างน้อย 6 ตัวอักษร</p>
                    </div>

                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">
                            ยืนยันรหัสผ่านใหม่
                        </label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="bi bi-lock-fill text-gray-400"></i>
                            </div>
                            <input id="confirm_password" name="confirm_password" type="password" required
                                class="focus:ring-primary-500 focus:border-primary-500 block w-full pl-10 sm:text-sm border-gray-300 rounded-xl py-3 transition-colors"
                                placeholder="••••••••">
                        </div>
                    </div>

                    <div>
                        <button type="submit" class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-bold rounded-xl text-white bg-green-500 hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 shadow-lg shadow-green-500/30 transition-all hover:-translate-y-0.5">
                            <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                                <i class="bi bi-check-lg text-green-400 group-hover:text-green-300 transition-colors"></i>
                            </span>
                            บันทึกรหัสผ่านใหม่
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .bg-pattern {
        background-color: #f9fafb;
        background-image: radial-gradient(#6366f1 0.5px, transparent 0.5px), radial-gradient(#6366f1 0.5px, #f9fafb 0.5px);
        background-size: 20px 20px;
        background-position: 0 0, 10px 10px;
    }

    .animate-fade-in-down {
        animation: fadeInDown 0.5s ease-out;
    }

    @keyframes fadeInDown {
        from {
            opacity: 0;
            transform: translate3d(0, -20px, 0);
        }

        to {
            opacity: 1;
            transform: translate3d(0, 0, 0);
        }
    }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>