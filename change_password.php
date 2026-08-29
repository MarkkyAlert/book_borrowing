<?php
/**
 * Change Password (บังคับ) - ตั้งรหัสผ่านใหม่ก่อนใช้งานครั้งแรก
 *
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้เป็น "ทางตัน" ของบัญชีที่ยังใช้รหัสผ่านเริ่มต้นของระบบ (F-53)
 *   สมาชิกที่ถูกนำเข้าจากไฟล์ หรือ admin เป็นคนสร้าง/สุ่มรหัสให้ จะถูกส่งมาที่นี่
 *   และไปไหนไม่ได้จนกว่าจะตั้งรหัสผ่านที่มีแต่ตัวเองรู้
 * - สิทธิ์: ต้อง login (ทุก role)
 *
 * 📂 Flow:
 * 1. GET  → แสดงฟอร์ม (ถ้าธงถูกเคลียร์แล้ว → เด้งกลับ profile.php ไม่ต้องเปลี่ยนซ้ำ)
 * 2. POST → AuthService::changePassword() ซึ่งจะเคลียร์ธงให้เองผ่าน updatePassword()
 *
 * ⚠️ ระวัง:
 * - 🔴 `requireLogin(false)` — ต้องยกเว้นด่านบังคับเปลี่ยนรหัส **ที่หน้านี้เท่านั้น**
 *   ถ้าเรียก `requireLogin()` เฉย ๆ ด่านจะเด้งมาหาหน้านี้ซ้ำไม่รู้จบ
 * - ไม่มีเมนู/ลิงก์ออกจากหน้านี้นอกจากปุ่มออกจากระบบ — ตั้งใจ ไม่ใช่ลืมใส่
 * - ยังต้องยืนยัน "รหัสผ่านปัจจุบัน" อยู่ แม้จะเป็นรหัสที่คนอื่นก็รู้
 *   เพราะคนที่ขโมย session มาต้องเปลี่ยนรหัสไม่ได้ถ้าไม่รู้รหัสเดิม
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/bootstrap.php';

// 🔒 [AUTH] ต้อง login — แต่ **ยกเว้น** ด่านบังคับเปลี่ยนรหัส (ไม่งั้นเด้งหาตัวเองวนไม่จบ)
requireLogin(false);

use App\Services\AuthService;

$pdo = getDB();
$authService = new AuthService($pdo);
$errors = [];

// 🔄 ธงถูกเคลียร์ไปแล้ว (เปลี่ยนรหัสสำเร็จ หรือเข้ามาเองทั้งที่ไม่ต้องเปลี่ยน)
//    → ส่งไปหน้าโปรไฟล์ซึ่งมีฟอร์มเปลี่ยนรหัสแบบไม่บังคับอยู่แล้ว
if (!mustChangePassword()) {
    redirect(APP_URL . '/profile.php');
}

// ── POST: ตั้งรหัสผ่านใหม่ ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ [SECURITY] CSRF
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง กรุณาลองใหม่');
        redirect(APP_URL . '/change_password.php');
    }

    // 🛡️ [SECURITY] Rate limit — กันคนเดา "รหัสผ่านปัจจุบัน" รัว ๆ
    //    ใช้ key เดียวกับ profile.php เพราะเป็นการกระทำเดียวกัน (ยืนยันรหัสเดิม)
    $rateLimitKey = 'password_change';

    if (!checkRateLimit($rateLimitKey)) {
        $errors[] = 'ลองผิดหลายครั้งเกินไป กรุณารอ ' . RATE_LIMIT_WINDOW_MINUTES . ' นาที';
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword     = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($err = validatePassword($newPassword)) {
            $errors[] = $err;
        }

        if ($newPassword !== $confirmPassword) {
            $errors[] = 'รหัสผ่านใหม่ไม่ตรงกัน';
        }

        if (empty($errors)) {
            // 🚀 [WRITE] Service ตรวจรหัสเดิม + ห้ามซ้ำรหัสเดิม + ห้ามตั้งกลับเป็นรหัสเริ่มต้น
            //    และ UserRepository::updatePassword() จะเคลียร์ธงให้เองในคำสั่งเดียวกัน
            $result = $authService->changePassword($_SESSION['user_id'], $currentPassword, $newPassword);

            if ($result['success']) {
                resetRateLimit($rateLimitKey);

                // 🔑 เคลียร์ธงใน session ด้วย — ไม่งั้นด่านจะยังเด้งกลับมาที่นี่
                //    (DB เคลียร์ไปแล้ว แต่ session ยังถือค่าเก่าจนกว่าจะ login ใหม่)
                $_SESSION['must_change_password'] = false;

                // 🛡️ regenerate session ID — รหัสผ่านเปลี่ยนแล้ว ควรตัด session เก่าที่อาจถูกขโมย
                session_regenerate_id(true);

                setFlash('success', 'ตั้งรหัสผ่านใหม่เรียบร้อย ยินดีต้อนรับเข้าสู่ระบบ');

                // 🔀 ส่งต่อตาม role เหมือนตอน login ปกติ
                if (isStaff()) {
                    redirect(APP_URL . '/admin/');
                }
                redirect(APP_URL . '/index.php');
            }

            incrementRateLimit($rateLimitKey);
            $errors[] = $result['error'];
        }
    }
}

$pageTitle = 'ตั้งรหัสผ่านใหม่';
require_once __DIR__ . '/includes/header.php';
?>

<div class="min-h-[80vh] flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gray-50">
    <div class="max-w-md w-full">
        <div class="bg-white rounded-3xl shadow-2xl p-8 sm:p-10 border border-gray-200">

            <div class="text-center mb-8">
                <div class="mx-auto h-16 w-16 bg-amber-500 rounded-2xl flex items-center justify-center shadow-lg shadow-amber-500/30">
                    <i class="bi bi-shield-lock-fill text-3xl text-white"></i>
                </div>
                <h2 class="mt-6 text-2xl font-extrabold text-gray-900">ตั้งรหัสผ่านใหม่ก่อนใช้งาน</h2>
            </div>

            <?php // 🔔 ต้องมี — ไม่งั้น flash ที่ตั้งไว้ก่อน redirect มาที่นี่จะหายเงียบ
                  //    (เช่น "คำขอไม่ถูกต้อง" ตอน CSRF ไม่ผ่าน ซึ่ง redirect กลับมาหน้านี้)
                  //    และค่าที่ค้างใน session จะไปโผล่หน้าอื่นผิดจังหวะทีหลัง ?>
            <?php displayFlash(); ?>

            <?php // 🧠 บอกเหตุผลตรง ๆ ว่าทำไมถึงถูกบังคับ — ไม่งั้นผู้ใช้จะรู้สึกว่าระบบกวน ?>
            <div class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-800 leading-relaxed">
                <i class="bi bi-info-circle-fill mr-1"></i>
                บัญชีนี้ยังใช้<strong>รหัสผ่านเริ่มต้นของระบบ</strong> ซึ่งเป็นรหัสเดียวกันกับสมาชิกคนอื่น
                และเจ้าหน้าที่ก็ทราบรหัสนี้ กรุณาตั้งรหัสผ่านที่มีแต่คุณคนเดียวรู้
            </div>

            <?php if (!empty($errors)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
                    <ul class="text-sm text-red-700 space-y-1 list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                            <li><?= e($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">

                <div>
                    <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">
                        รหัสผ่านปัจจุบัน
                    </label>
                    <input type="password" id="current_password" name="current_password" required autofocus
                           autocomplete="current-password"
                           class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    <p class="mt-1 text-xs text-gray-500">รหัสที่ใช้เข้าสู่ระบบเมื่อสักครู่</p>
                </div>

                <div>
                    <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">
                        รหัสผ่านใหม่
                    </label>
                    <input type="password" id="new_password" name="new_password" required
                           autocomplete="new-password" minlength="<?= MIN_PASSWORD_LENGTH ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    <p class="mt-1 text-xs text-gray-500">อย่างน้อย <?= MIN_PASSWORD_LENGTH ?> ตัวอักษร และต้องไม่ใช่รหัสเริ่มต้นเดิม</p>
                </div>

                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">
                        ยืนยันรหัสผ่านใหม่
                    </label>
                    <input type="password" id="confirm_password" name="confirm_password" required
                           autocomplete="new-password" minlength="<?= MIN_PASSWORD_LENGTH ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>

                <button type="submit"
                        class="w-full py-3 px-4 bg-primary-600 hover:bg-primary-700 text-white font-semibold rounded-xl shadow-lg shadow-primary-500/30 transition">
                    <i class="bi bi-check-lg mr-1"></i> บันทึกรหัสผ่านใหม่
                </button>
            </form>

            <?php // 🚪 ทางออกเดียวของหน้านี้ — ออกจากระบบ (ต้อง POST + CSRF ตาม logout.php) ?>
            <form method="POST" action="<?= APP_URL ?>/logout.php" class="mt-6 text-center">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <button type="submit" class="text-sm text-gray-500 hover:text-gray-700 underline">
                    ออกจากระบบ
                </button>
            </form>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
