<?php
/**
 * Forgot Password - ลืมรหัสผ่าน
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect(APP_URL . '/index.php');
}

$errors = [];
$success = false;
$resetLink = null;
$email = '';

// Process form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    // Validation
    if (empty($email)) {
        $errors[] = 'กรุณากรอกอีเมล';
    } elseif (!isValidEmail($email)) {
        $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
    }
    
    if (empty($errors)) {
        $pdo = getDB();
        
        // Check if email exists
        $stmt = $pdo->prepare("SELECT id, email FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Rate limiting - max 3 requests per email per hour
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM password_resets 
                WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute([$email]);
            $recentRequests = $stmt->fetchColumn();
            
            if ($recentRequests >= 3) {
                $errors[] = 'คุณขอรีเซ็ตรหัสผ่านบ่อยเกินไป กรุณารอ 1 ชั่วโมง';
            } else {
                // Generate secure token
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Save token
                $stmt = $pdo->prepare("
                    INSERT INTO password_resets (email, token, expires_at) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$email, $token, $expiresAt]);
                
                // Generate reset link
                $resetLink = APP_URL . '/reset_password.php?token=' . $token;
                
                // TODO: Send email in production
                // mail($email, 'รีเซ็ตรหัสผ่าน', "คลิกลิงก์นี้เพื่อรีเซ็ตรหัสผ่าน: $resetLink");
                
                $success = true;
            }
        } else {
            // Don't reveal if email exists (security)
            $success = true;
            $resetLink = null;
        }
    }
}

$pageTitle = 'ลืมรหัสผ่าน';
require_once __DIR__ . '/includes/header.php';
?>

<div class="min-h-[80vh] flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gray-50 bg-pattern">
    <div class="max-w-md w-full space-y-8 relative z-10">
        <!-- Card -->
        <div class="bg-white/80 backdrop-blur-xl rounded-3xl shadow-2xl p-8 sm:p-10 border border-white/50">
            <div class="text-center mb-8">
                <div class="mx-auto h-16 w-16 bg-amber-500 rounded-2xl flex items-center justify-center shadow-lg shadow-amber-500/30 transform -rotate-3 hover:rotate-0 transition-all duration-300">
                    <i class="bi bi-key text-3xl text-white"></i>
                </div>
                <h2 class="mt-6 text-3xl font-extrabold text-gray-900">
                    ลืมรหัสผ่าน
                </h2>
                <p class="mt-2 text-sm text-gray-600">
                    กรอกอีเมลที่ใช้สมัครสมาชิก เพื่อรับลิงก์รีเซ็ตรหัสผ่าน
                </p>
            </div>
            
            <?php displayFlash(); ?>
            
            <?php if ($success): ?>
                <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6 rounded-r-lg">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="bi bi-check-circle-fill text-green-500"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-green-700">
                                หากอีเมลนี้มีในระบบ คุณจะได้รับลิงก์รีเซ็ตรหัสผ่าน
                            </p>
                        </div>
                    </div>
                </div>
                
                <?php if ($resetLink): ?>
                <!-- Demo Mode: แสดง link (ในโหมด production จะส่งทาง email) -->
                <div class="bg-blue-50 border border-blue-200 p-4 mb-6 rounded-xl">
                    <p class="text-xs text-blue-600 font-medium mb-2">
                        <i class="bi bi-info-circle mr-1"></i>
                        Demo Mode: ลิงก์รีเซ็ตรหัสผ่าน (ปกติจะส่งทาง email)
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
                                <i class="bi bi-send text-amber-400 group-hover:text-amber-300 transition-colors"></i>
                            </span>
                            ส่งลิงก์รีเซ็ตรหัสผ่าน
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
