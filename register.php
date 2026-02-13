<?php

/**
 * Register Page - สมัครสมาชิก
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้า public — ถ้า login แล้วจะ redirect ไป index.php
 * - สมัครได้เฉพาะ role='member' — admin/staff ต้องสร้างผ่าน admin panel
 * 
 * 📂 Flow:
 * 1. POST → CSRF check → rate limit (global key) → validateMemberData()
 * 2. สำเร็จ → AuthService::register() → MemberService::createMember() → redirect login
 * 3. ล้มเหลว → แสดง errors บน form (เก็บค่าเดิมไว้)
 * 
 * ⚠️ ระวัง:
 * - rate limit ใช้ global key (ไม่ใช่ per-email) เพราะ attacker ใช้ email ใหม่ได้ทุกครั้ง
 * - incrementRateLimit() เรียกก่อน validation — ป้องกัน bypass ด้วย invalid data
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/bootstrap.php';

// 🔁 ถ้า login แล้ว → redirect ไปหน้าแรก
if (isLoggedIn()) {
    redirect(APP_URL . '/index.php');
}

$errors = [];
// 📝 เก็บค่าไว้สำหรับแสดงค่าเดิมใน form ถ้าเกิด error
$name = '';
$email = '';
$phone = '';

// ── POST: สมัครสมาชิกใหม่ ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ [SECURITY] CSRF
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    }

    // 🛡️ [SECURITY] Rate limiting ป้องกัน spam registration
    //    ใช้ global key (ไม่ใช่ per-email) — เพราะ attacker สร้าง email ใหม่ได้ทุกครั้ง
    //    ถ้าใช้ per-email → attacker แค่เปลี่ยน email ก็ bypass ได้
    $rateLimitKey = 'register';

    if (!checkRateLimit($rateLimitKey)) {
        $errors[] = 'ลองหลายครั้งเกินไป กรุณารอ ' . RATE_LIMIT_WINDOW_MINUTES . ' นาที';
    }

    // 🧠 นับ attempt ก่อน validation — ป้องกัน bypass ด้วย invalid data
    //    ถ้านับหลัง validate → bot ส่ง invalid data ได้ไม่จำกัด
    incrementRateLimit($rateLimitKey);

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // 🔍 Validation ผ่าน shared helper (Single Source of Truth — ใช้ร่วมกับ admin member_form.php)
    $errors = array_merge($errors, validateMemberData([
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'password' => $password
    ]));

    // 🔒 Page-specific: ตรวจ confirm password ตรงกัน
    if ($password !== $confirmPassword) {
        $errors[] = 'รหัสผ่านไม่ตรงกัน';
    }

    if (empty($errors)) {
        // 🚀 [WRITE] สมัครผ่าน AuthService → MemberService::createMember()
        //    สร้างได้เฉพาะ role=member — admin/staff ต้องสร้างผ่าน admin panel
        $authService = new \App\Services\AuthService(getDB());

        $result = $authService->register([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => $password
        ]);

        if ($result['success']) {
            setFlash('success', 'สมัครสมาชิกสำเร็จ กรุณาเข้าสู่ระบบ');
            redirect(APP_URL . '/login.php');
        } else {
            $errors[] = $result['error'];
        }
    }
}

$pageTitle = 'สมัครสมาชิก';
require_once __DIR__ . '/includes/header.php';
?>

<div class="min-h-[85vh] flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gray-50 bg-pattern">
    <div class="max-w-md w-full space-y-8 relative z-10">
        <!-- Card -->
        <div class="bg-white rounded-3xl shadow-2xl p-8 sm:p-10 border border-gray-200">
            <div class="text-center mb-8">
                <div class="mx-auto h-16 w-16 bg-primary-600 rounded-2xl flex items-center justify-center shadow-lg shadow-primary-500/30 transform -rotate-3 hover:rotate-0 transition-all duration-300">
                    <i class="bi bi-person-plus text-3xl text-white"></i>
                </div>
                <h2 class="mt-6 text-3xl font-extrabold text-gray-900">
                    สมัครสมาชิกใหม่
                </h2>
                <p class="mt-2 text-sm text-gray-600">
                    เข้าร่วมเป็นสมาชิกเพื่อยืมหนังสือ
                </p>
            </div>

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

            <form class="space-y-5" method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">
                        ชื่อ-นามสกุล <span class="text-red-500">*</span>
                    </label>
                    <div class="relative rounded-md shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="bi bi-person text-gray-400"></i>
                        </div>
                        <input id="name" name="name" type="text" required
                            class="focus:ring-primary-500 focus:border-primary-500 block w-full pl-10 sm:text-sm border-gray-300 rounded-xl py-2.5 transition-colors"
                            placeholder="กรอกชื่อ-นามสกุล" value="<?= e($name) ?>">
                    </div>
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">
                        อีเมล <span class="text-red-500">*</span>
                    </label>
                    <div class="relative rounded-md shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="bi bi-envelope text-gray-400"></i>
                        </div>
                        <input id="email" name="email" type="email" required
                            class="focus:ring-primary-500 focus:border-primary-500 block w-full pl-10 sm:text-sm border-gray-300 rounded-xl py-2.5 transition-colors"
                            placeholder="example@email.com" value="<?= e($email) ?>">
                    </div>
                </div>

                <div>
                    <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">
                        เบอร์โทรศัพท์
                    </label>
                    <div class="relative rounded-md shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="bi bi-telephone text-gray-400"></i>
                        </div>
                        <input id="phone" name="phone" type="tel"
                            class="focus:ring-primary-500 focus:border-primary-500 block w-full pl-10 sm:text-sm border-gray-300 rounded-xl py-2.5 transition-colors"
                            placeholder="0812345678" value="<?= e($phone) ?>">
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                            รหัสผ่าน <span class="text-red-500">*</span>
                        </label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="bi bi-lock text-gray-400"></i>
                            </div>
                            <input id="password" name="password" type="password" required
                                class="focus:ring-primary-500 focus:border-primary-500 block w-full pl-10 sm:text-sm border-gray-300 rounded-xl py-2.5 transition-colors"
                                placeholder="******">
                        </div>
                    </div>
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">
                            ยืนยันรหัสผ่าน <span class="text-red-500">*</span>
                        </label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="bi bi-lock-fill text-gray-400"></i>
                            </div>
                            <input id="confirm_password" name="confirm_password" type="password" required
                                class="focus:ring-primary-500 focus:border-primary-500 block w-full pl-10 sm:text-sm border-gray-300 rounded-xl py-2.5 transition-colors"
                                placeholder="******">
                        </div>
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-bold rounded-xl text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 shadow-lg shadow-primary-500/30 transition-all hover:-translate-y-0.5">
                        <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                            <i class="bi bi-person-check text-primary-500 group-hover:text-primary-400 transition-colors"></i>
                        </span>
                        สมัครสมาชิก
                    </button>
                </div>
            </form>

            <div class="mt-8 pt-6 border-t border-gray-100">
                <div class="text-center">
                    <p class="text-sm text-gray-600">
                        มีบัญชีอยู่แล้ว?
                        <a href="login.php" class="font-bold text-primary-600 hover:text-primary-500 transition-colors">
                            เข้าสู่ระบบ
                        </a>
                    </p>
                </div>
            </div>
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