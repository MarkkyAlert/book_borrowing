<?php
/**
 * Login Page - เข้าสู่ระบบ
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect(APP_URL . '/index.php');
}

$errors = [];
$email = '';

// Process login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Validation
    if (empty($email)) {
        $errors[] = 'กรุณากรอกอีเมล';
    }
    if (empty($password)) {
        $errors[] = 'กรุณากรอกรหัสผ่าน';
    }
    
    if (empty($errors)) {
        // [SECURITY] Rate limiting ป้องกัน brute force attack
        // ใช้ md5(email) เป็น key เพื่อนับแยกตาม email (ไม่ใช่ IP - เพราะ IP อาจ shared)
        // Limit: 5 attempts / 15 นาที ต่อ email
        $attemptKey = 'login_attempts_' . md5($email);
        $attemptTimeKey = 'login_time_' . md5($email);
        
        if (!isset($_SESSION[$attemptKey])) {
            $_SESSION[$attemptKey] = 0;
            $_SESSION[$attemptTimeKey] = time();
        }
        
        // [RATE LIMIT] Reset counter หลัง 15 นาที (900 วินาที)
        if (time() - $_SESSION[$attemptTimeKey] > 900) {
            $_SESSION[$attemptKey] = 0;
            $_SESSION[$attemptTimeKey] = time();
        }
        
        if ($_SESSION[$attemptKey] >= 5) {
            $errors[] = 'ลองผิดหลายครั้งเกินไป กรุณารอ 15 นาที';
        } else {
            // [REFACTORED] ใช้ AuthService แทน SQL Query โดยตรง
            require_once __DIR__ . '/app/Services/AuthService.php';
            $authService = new \App\Services\AuthService(getDB());
            $user = $authService->login($email, $password);
            
            if ($user) {
                // [SECURITY] Reset counter เมื่อ login สำเร็จ
                $_SESSION[$attemptKey] = 0;
                
                // [SECURITY] สำคัญมาก! regenerate session ID ป้องกัน session fixation attack
                // true = ลบ session file เก่าทิ้ง (ไม่ให้ attacker ใช้ session เดิมได้)
                session_regenerate_id(true);
                
                // [AUTH] เก็บข้อมูล user ใน session - ค่าเหล่านี้มาจาก DB เท่านั้น
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                
                setFlash('success', 'เข้าสู่ระบบสำเร็จ ยินดีต้อนรับ ' . $user['name']);
                
                // Redirect based on role
                if ($user['role'] === 'admin') {
                    redirect(APP_URL . '/admin/');
                } else {
                    redirect(APP_URL . '/index.php');
                }
            } else {
                // [SECURITY] นับ attempt ก่อนแจ้ง error (ป้องกัน brute force)
                $_SESSION[$attemptKey]++;
                // [SECURITY] ไม่บอกว่า email หรือ password ผิด - ป้องกัน user enumeration
                $errors[] = 'อีเมลหรือรหัสผ่านไม่ถูกต้อง';
            }
        }
    }
}

$pageTitle = 'เข้าสู่ระบบ';
require_once __DIR__ . '/includes/header.php';
?>

<div class="min-h-[80vh] flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gray-50 bg-pattern">
    <div class="max-w-md w-full space-y-8 relative z-10">
        <!-- Card -->
        <div class="bg-white/80 backdrop-blur-xl rounded-3xl shadow-2xl p-8 sm:p-10 border border-white/50">
            <div class="text-center mb-8">
                <div class="mx-auto h-16 w-16 bg-primary-600 rounded-2xl flex items-center justify-center shadow-lg shadow-primary-500/30 transform rotate-3 hover:rotate-0 transition-all duration-300">
                    <i class="bi bi-book-half text-3xl text-white"></i>
                </div>
                <h2 class="mt-6 text-3xl font-extrabold text-gray-900">
                    เข้าสู่ระบบ
                </h2>
                <p class="mt-2 text-sm text-gray-600">
                    เข้าใช้งานระบบห้องสมุดออนไลน์
                </p>
            </div>
            
            <?php displayFlash(); ?>
            
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
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                        รหัสผ่าน
                    </label>
                    <div class="relative rounded-md shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="bi bi-lock text-gray-400"></i>
                        </div>
                        <input id="password" name="password" type="password" autocomplete="current-password" required 
                            class="focus:ring-primary-500 focus:border-primary-500 block w-full pl-10 sm:text-sm border-gray-300 rounded-xl py-3 transition-colors" 
                            placeholder="••••••••">
                    </div>
                </div>

                <div>
                    <button type="submit" class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-bold rounded-xl text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 shadow-lg shadow-primary-500/30 transition-all hover:-translate-y-0.5">
                        <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                            <i class="bi bi-box-arrow-in-right text-primary-500 group-hover:text-primary-400 transition-colors"></i>
                        </span>
                        เข้าสู่ระบบ
                    </button>
                </div>
            </form>
            
            <div class="mt-6 text-center">
                <a href="forgot_password.php" class="text-sm text-gray-500 hover:text-primary-600 transition-colors">
                    <i class="bi bi-key mr-1"></i>
                    ลืมรหัสผ่าน?
                </a>
            </div>
            
            <div class="mt-6 pt-6 border-t border-gray-100">
                <div class="text-center">
                    <p class="text-sm text-gray-600">
                        ยังไม่มีบัญชีสมาชิก? 
                        <a href="register.php" class="font-bold text-primary-600 hover:text-primary-500 transition-colors">
                            สมัครสมาชิกใหม่
                        </a>
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Demo Credentials -->
        <div class="bg-blue-50/50 rounded-2xl p-4 border border-blue-100 text-center backdrop-blur-sm">
            <p class="text-xs text-blue-600 font-medium">
                <span class="font-bold">Demo Admin:</span> admin@library.com / 123456
            </p>
        </div>
    </div>
</div>

<style>
.bg-pattern {
    background-color: #f9fafb;
    background-image: radial-gradient(#6366f1 0.5px, transparent 0.5px), radial-gradient(#6366f1 0.5px, #f9fafb 0.5px);
    background-size: 20px 20px;
    background-position: 0 0, 10px 10px;
    background-attachment: fixed;
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
