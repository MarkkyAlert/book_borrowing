<?php

/**
 * Forgot Password - ลืมรหัสผ่าน (ขอ reset link)
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้า public — สร้าง reset token แล้วแสดง link (ระบบนี้ไม่ส่ง email จริง)
 * - Token มีอายุ 1 ชม. ใช้ได้ครั้งเดียว (one-time-use)
 * 
 * 📂 Flow:
 * 1. POST → CSRF check → rate limit → AuthService::requestPasswordReset(email)
 * 2. สำเร็จ → แสดง reset link ให้ copy (เพราะไม่มี mail server)
 * 3. ล้มเหลว → แสดง "สำเร็จ" เหมือนกัน (ป้องกัน user enumeration)
 * 
 * 🔗 ต่อไป: reset_password.php?token=XXX
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/bootstrap.php';

// 🔁 ถ้า login แล้ว → redirect ไปหน้าแรก (ไม่ต้อง reset password)
if (isLoggedIn()) {
    redirect(APP_URL . '/index.php');
}

$errors = [];
$success = false;
$resetLink = null; // แสดงเฉพาะ demo mode (localhost + APP_DEBUG)
$email = '';

// ── POST: ขอ reset link ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ [SECURITY] CSRF
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    }

    $email = trim($_POST['email'] ?? '');

    // 🛡️ [SECURITY] Rate limiting ป้องกัน spam + การใช้หน้านี้เพื่อ enumerate email
    $rateLimitKey = 'forgot_password';
    if (!checkRateLimit($rateLimitKey)) {
        $errors[] = 'ลองหลายครั้งเกินไป กรุณารอ ' . RATE_LIMIT_WINDOW_MINUTES . ' นาที';
    }
    incrementRateLimit($rateLimitKey);

    // Validation
    if (empty($errors) && empty($email)) {
        $errors[] = 'กรุณากรอกอีเมล';
    } elseif (empty($errors) && !isValidEmail($email)) {
        $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
    }

    if (empty($errors)) {
        // 🚀 [WRITE] สร้าง reset token (hash เก็บใน DB, หมดอายุ 1 ชั่วโมง)
        $authService = new \App\Services\AuthService(getDB());

        $result = $authService->requestPasswordReset($email);

        if (!$result['success']) {
            $errors[] = $result['error'];
        } else {
            $success = true;

            // 🧠 Demo mode: แสดง reset link เฉพาะ localhost + APP_DEBUG=true
            //    production จะส่งทาง email แทน (ยังไม่ได้ตั้งค่า mail server)
            if ($result['token'] && defined('APP_DEBUG') && APP_DEBUG) {
                $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);
                if ($isLocal) {
                    $resetLink = APP_URL . '/reset_password.php?token=' . $result['token'];
                }
            }
        }
    }
}

$pageTitle = 'ลืมรหัสผ่าน';
require_once __DIR__ . '/includes/header.php';
?>

<div class="min-h-[80vh] flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gray-50 bg-pattern">
    <div class="max-w-md w-full space-y-8 relative z-10">
        <!-- Card -->
        <div class="bg-white rounded-3xl shadow-2xl p-8 sm:p-10 border border-gray-200">
            <div class="text-center mb-8">
                <div class="mx-auto h-16 w-16 bg-amber-500 rounded-2xl flex items-center justify-center shadow-lg shadow-amber-500/30 transform -rotate-3 hover:rotate-0 transition-all duration-300">
                    <i class="bi bi-key text-3xl text-white"></i>
                </div>
                <h2 class="mt-6 text-3xl font-extrabold text-gray-900">
                    ลืมรหัสผ่าน
                </h2>
                <p class="mt-2 text-sm text-gray-600">
                    กรอกอีเมลที่ใช้สมัครสมาชิก แล้วนำบัตรสมาชิกไปติดต่อเคาน์เตอร์ห้องสมุด เจ้าหน้าที่จะตั้งรหัสผ่านใหม่ให้
                </p>
            </div>

            <?php displayFlash(); ?>

            <?php if ($success): ?>
                <?php // 🛡️ [SECURITY] ข้อความต้องเหมือนกันทุกกรณี — อีเมลมีจริงหรือไม่ ขอถี่เกินไปหรือไม่
                      //    ถ้าข้อความต่างกันแม้แต่นิดเดียว จะกลายเป็นเครื่องมือไล่หาว่าใครเป็นสมาชิก
                      // 🧠 และต้องบอก "ทางออกที่ใช้ได้จริง" ด้วย — ระบบนี้ยังไม่มีการส่งอีเมล
                      //    เดิมเขียนว่า "(ต้องพัฒนาต่อให้ส่งทางเมล)" ซึ่งเป็นโน้ตของนักพัฒนา
                      //    หลุดไปถึงผู้ใช้ปลายทางของลูกค้า และไม่ได้บอกว่าต้องทำอะไรต่อ ?>
                <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6 rounded-r-lg">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="bi bi-check-circle-fill text-green-500"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-green-800 font-medium">รับคำขอแล้ว</p>
                            <p class="text-sm text-green-700 mt-1">
                                หากอีเมลนี้มีอยู่ในระบบ เจ้าหน้าที่ห้องสมุดจะตั้งรหัสผ่านใหม่ให้ได้
                            </p>
                        </div>
                    </div>
                </div>

                <div class="bg-gray-50 border border-gray-200 p-4 mb-6 rounded-xl">
                    <p class="text-sm font-medium text-gray-800 mb-1.5">
                        <i class="bi bi-person-workspace mr-1.5 text-gray-500"></i>ขั้นตอนถัดไป
                    </p>
                    <p class="text-sm text-gray-600">
                        ติดต่อเคาน์เตอร์ห้องสมุดพร้อมแสดงบัตรสมาชิก
                        เจ้าหน้าที่จะตั้งรหัสผ่านใหม่ให้ได้ทันที
                    </p>
                </div>

                <?php if ($resetLink): ?>
                    <!-- Demo Mode: แสดง link (ในโหมด production จะส่งทาง email) -->
                    <div class="bg-blue-50 border border-blue-200 p-4 mb-6 rounded-xl">
                        <p class="text-xs text-blue-600 font-medium mb-2">
                            <i class="bi bi-tools mr-1"></i>
                            โหมดพัฒนา (APP_DEBUG=true บน localhost เท่านั้น) — ลิงก์ตั้งรหัสผ่านใหม่
                        </p>
                        <p class="text-xs text-blue-500 mb-2">
                            กล่องนี้ไม่แสดงบนเครื่องจริง · ตั้ง APP_DEBUG=false ใน .env ก่อนส่งมอบ
                        </p>
                        <a href="<?= e($resetLink) ?>" class="text-sm text-blue-700 hover:text-blue-800 break-all underline">
                            <?= e($resetLink) ?>
                        </a>
                    </div>
                <?php endif; ?>

                <div class="text-center">
                    <a href="login.php" class="inline-flex items-center text-primary-600 hover:text-primary-500 font-medium">
                        <i class="bi bi-arrow-left mr-2"></i>
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
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">
                            อีเมล
                        </label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="bi bi-envelope text-gray-400"></i>
                            </div>
                            <input id="email" name="email" type="email" autocomplete="email" required
                                class="focus:ring-primary-500 focus:border-primary-500 block w-full pl-10 sm:text-sm border-gray-300 rounded-xl py-3 transition-colors"
                                placeholder="example@email.com" value="<?= e($email) ?>">
                        </div>
                    </div>

                    <div>
                        <button type="submit" class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-bold rounded-xl text-white bg-amber-500 hover:bg-amber-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500 shadow-lg shadow-amber-500/30 transition-all hover:-translate-y-0.5">
                            <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                                <?php // 🔴 ไม่ใช้ไอคอน "ส่ง" (bi-send) เพราะไม่มีการส่งอะไรออกไปจริง ?>
                                <i class="bi bi-person-badge text-amber-400 group-hover:text-amber-300 transition-colors"></i>
                            </span>
                            <?php // 🔴 ห้ามเขียนว่า "ส่งลิงก์" — ระบบนี้ไม่ส่งอีเมลเลย และตั้งใจไม่ทำ
                                  //    (เหตุผลอยู่ใน docs/LIMITATIONS.md หัวข้อ 6)
                                  //    ปุ่มที่สัญญาว่าจะส่งเมล = สมาชิกนั่งรอเมลที่ไม่มีวันมา
                                  //    แล้วหน้าผลลัพธ์ต้องมาแก้ต่างทีหลังว่า "ไปที่เคาน์เตอร์" ?>
                            แจ้งคำขอตั้งรหัสผ่านใหม่
                        </button>
                    </div>
                </form>

                <div class="mt-8 pt-6 border-t border-gray-100">
                    <div class="text-center">
                        <a href="login.php" class="text-sm text-gray-600 hover:text-primary-600 transition-colors">
                            <i class="bi bi-arrow-left mr-1"></i>
                            กลับไปหน้าเข้าสู่ระบบ
                        </a>
                    </div>
                </div>
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