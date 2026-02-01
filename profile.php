<?php
/**
 * Profile Page - โปรไฟล์ผู้ใช้
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';

requireLogin();

$pdo = getDB();
$user = getCurrentUser();
$errors = [];
$success = false;

// [REFACTORED] ใช้ BorrowRepository แทน SQL Query โดยตรง
require_once __DIR__ . '/app/Repositories/BorrowRepository.php';
require_once __DIR__ . '/app/Services/AuthService.php';
$borrowRepo = new \App\Repositories\BorrowRepository($pdo);
$authService = new \App\Services\AuthService($pdo);

$borrowHistory = $borrowRepo->findByUserId($_SESSION['user_id'], 10);

// Process profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง กรุณาลองใหม่');
        redirect(APP_URL . '/profile.php');
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        if (empty($name)) {
            $errors[] = 'กรุณากรอกชื่อ-นามสกุล';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = 'ชื่อต้องไม่เกิน 100 ตัวอักษร';
        }
        
        if (!empty($phone) && !isValidPhone($phone)) {
            $errors[] = 'เบอร์โทรต้องเป็นตัวเลข 9-10 หลัก';
        }
        
        if (empty($errors)) {
            // [REFACTORED] ใช้ AuthService
            $authService->updateProfile($_SESSION['user_id'], [
                'name' => $name,
                'phone' => $phone
            ]);
            $_SESSION['user_name'] = $name;
            setFlash('success', 'อัปเดตข้อมูลสำเร็จ');
            redirect(APP_URL . '/profile.php');
        }
    }
    
    if ($action === 'change_password') {
        // Rate limiting for password attempts
        $attemptKey = 'password_attempts';
        $attemptTimeKey = 'password_attempt_time';
        
        if (!isset($_SESSION[$attemptKey])) {
            $_SESSION[$attemptKey] = 0;
            $_SESSION[$attemptTimeKey] = time();
        }
        
        // Reset after 15 minutes
        if (time() - $_SESSION[$attemptTimeKey] > 900) {
            $_SESSION[$attemptKey] = 0;
            $_SESSION[$attemptTimeKey] = time();
        }
        
        if ($_SESSION[$attemptKey] >= 5) {
            $errors[] = 'ลองผิดหลายครั้งเกินไป กรุณารอ 15 นาที';
        } else {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            // Validation
            if (strlen($newPassword) < 6) {
                $errors[] = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร';
            }
            
            if ($newPassword !== $confirmPassword) {
                $errors[] = 'รหัสผ่านใหม่ไม่ตรงกัน';
            }
            
            if (empty($errors)) {
                // [REFACTORED] ใช้ AuthService
                $result = $authService->changePassword($_SESSION['user_id'], $currentPassword, $newPassword);
                
                if ($result['success']) {
                    $_SESSION[$attemptKey] = 0; // Reset attempts on success
                    setFlash('success', 'เปลี่ยนรหัสผ่านสำเร็จ');
                    redirect(APP_URL . '/profile.php');
                } else {
                    $_SESSION[$attemptKey]++;
                    $errors[] = $result['error'];
                }
            }
        }
    }
    
    // Reload user data
    $user = getCurrentUser();
}

$pageTitle = 'โปรไฟล์';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Profile Info -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden sticky top-24">
                <div class="bg-gradient-to-br from-primary-500 to-primary-700 px-6 py-8 text-center">
                    <div class="inline-block p-1 rounded-full bg-white/20 backdrop-blur-sm mb-4">
                        <div class="h-24 w-24 rounded-full bg-white flex items-center justify-center text-primary-600 text-5xl shadow-inner">
                            <i class="bi bi-person-fill"></i>
                        </div>
                    </div>
                    <h2 class="text-xl font-bold text-white"><?= e($user['name']) ?></h2>
                    <p class="text-primary-100 text-sm mt-1"><?= e($user['email']) ?></p>
                    <div class="mt-4">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $user['role'] === 'admin' ? 'bg-red-100 text-red-800' : 'bg-primary-800/50 text-white' ?>">
                            <?= $user['role'] === 'admin' ? 'ผู้ดูแลระบบ' : 'สมาชิกทั่วไป' ?>
                        </span>
                    </div>
                </div>
                
                <div class="p-6">
                    <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider mb-4">ข้อมูลการติดต่อ</h3>
                    <ul class="space-y-3 text-sm">
                        <li class="flex items-start">
                            <i class="bi bi-envelope text-gray-400 mr-3 mt-0.5"></i>
                            <span class="text-gray-600"><?= e($user['email']) ?></span>
                        </li>
                        <li class="flex items-start">
                            <i class="bi bi-telephone text-gray-400 mr-3 mt-0.5"></i>
                            <span class="text-gray-600"><?= e($user['phone'] ?: '-') ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Edit Forms -->
        <div class="lg:col-span-2 space-y-6">
            <?php displayFlash(); ?>
            
            <?php if (!empty($errors)): ?>
                <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r-lg">
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
            
            <!-- Update Profile -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50">
                    <h5 class="font-bold text-gray-800 flex items-center">
                        <i class="bi bi-person-gear mr-2 text-primary-600"></i>แก้ไขข้อมูลส่วนตัว
                    </h5>
                </div>
                <div class="p-6">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">ชื่อ-นามสกุล</label>
                                <input type="text" id="name" name="name" value="<?= e($user['name']) ?>" required
                                       class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                            </div>
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">เบอร์โทรศัพท์</label>
                                <input type="tel" id="phone" name="phone" value="<?= e($user['phone'] ?? '') ?>"
                                       class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-xl text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors">
                                <i class="bi bi-check-lg mr-1.5"></i>บันทึกข้อมูล
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Change Password -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50">
                    <h5 class="font-bold text-gray-800 flex items-center">
                        <i class="bi bi-key mr-2 text-amber-500"></i>เปลี่ยนรหัสผ่าน
                    </h5>
                </div>
                <div class="p-6">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="change_password">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                            <div>
                                <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">รหัสผ่านปัจจุบัน</label>
                                <input type="password" id="current_password" name="current_password" required
                                       class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 sm:text-sm">
                            </div>
                            <div>
                                <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">รหัสผ่านใหม่</label>
                                <input type="password" id="new_password" name="new_password" required
                                       class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 sm:text-sm">
                            </div>
                            <div>
                                <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">ยืนยันรหัสผ่านใหม่</label>
                                <input type="password" id="confirm_password" name="confirm_password" required
                                       class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 sm:text-sm">
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-xl text-white bg-amber-500 hover:bg-amber-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500 transition-colors">
                                <i class="bi bi-shield-lock mr-1.5"></i>เปลี่ยนรหัสผ่าน
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Borrow History -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50">
                    <h5 class="font-bold text-gray-800 flex items-center">
                        <i class="bi bi-clock-history mr-2 text-blue-500"></i>ประวัติการยืม (ล่าสุด 10 รายการ)
                    </h5>
                </div>
                <div class="overflow-x-auto">
                    <?php if (empty($borrowHistory)): ?>
                        <div class="text-center py-12 text-gray-400">
                            <i class="bi bi-inbox text-5xl mb-3 inline-block text-gray-300"></i>
                            <p>ยังไม่มีประวัติการยืม</p>
                        </div>
                    <?php else: ?>
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs text-gray-500 uppercase bg-gray-50 border-b border-gray-100">
                                <tr>
                                    <th class="px-6 py-4 font-medium">หนังสือ</th>
                                    <th class="px-6 py-4 font-medium">วันที่ยืม</th>
                                    <th class="px-6 py-4 font-medium">กำหนดคืน</th>
                                    <th class="px-6 py-4 font-medium">สถานะ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($borrowHistory as $borrow): ?>
                                    <tr class="hover:bg-gray-50/50 transition-colors">
                                        <td class="px-6 py-4">
                                            <div class="font-medium text-gray-900"><?= e($borrow['book_title']) ?></div>
                                            <div class="text-xs text-gray-500"><?= e($borrow['book_author']) ?></div>
                                        </td>
                                        <td class="px-6 py-4 text-gray-600"><?= formatDate($borrow['borrow_date']) ?></td>
                                        <td class="px-6 py-4 text-gray-600"><?= formatDate($borrow['due_date']) ?></td>
                                        <td class="px-6 py-4"><?= getBorrowStatusLabel($borrow['status'], $borrow['due_date']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
