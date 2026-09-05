<?php
/**
 * Profile Page - โปรไฟล์ผู้ใช้
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้แสดงข้อมูลส่วนตัว + ประวัติยืม + ค่าปรับค้าง + เปลี่ยนรหัสผ่าน
 * - สิทธิ์: ต้อง login (ทุก role)
 * - แสดงข้อมูลเฉพาะของ user ที่ login (ใช้ session user_id)
 * 
 * 📂 Flow:
 * 1. POST action=update_profile   → AuthService::updateProfile() (เปลี่ยนได้แค่ name/phone)
 * 2. POST action=change_password  → AuthService::changePassword() (ต้องยืนยัน password เดิม)
 * 3. GET → แสดง profile info, borrow stats, borrow history, unpaid fines
 * 
 * ⚠️ ระวัง:
 * - email เปลี่ยนไม่ได้ผ่านหน้านี้ (ป้องกัน account takeover)
 * - change_password ต้องยืนยัน password เดิมก่อน (ป้องกันคนที่ขโมย session)
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/bootstrap.php';

// 🔒 [AUTH] ต้อง login — แสดงข้อมูลเฉพาะของตัวเอง (session user_id)
requireLogin();

$pdo = getDB();
// 👤 ดึงข้อมูล user ปัจจุบันจาก DB (ไม่ใช้เฉพาะ session เพราะอาจ stale)
$user = getCurrentUser();
$errors = [];
$success = false;

use App\Repositories\BorrowRepository;
use App\Repositories\ReservationRepository;
use App\Services\AuthService;
$borrowRepo = new BorrowRepository($pdo);
$reservationRepo = new ReservationRepository($pdo);
$authService = new AuthService($pdo);

// ── ดึงข้อมูลสำหรับแสดงผล ──
// 📜 ประวัติการยืมล่าสุด 10 รายการ
$borrowHistory = $borrowRepo->findByUserId($_SESSION['user_id'], 10);

// 📊 สถิติการยืม (active, returned, total)
$borrowStats = $borrowRepo->getStatsByUser($_SESSION['user_id']);
$activeBorrows = $borrowStats['active_borrows'] ?? 0;

// 💰 รายการค่าปรับค้างชำระ
$unpaidFines = $borrowRepo->getUnpaidFinesByUser($_SESSION['user_id']);
$totalUnpaidAmount = array_sum(array_column($unpaidFines, 'fine_amount'));

// 💰 [UAT รอบ 5] ค่าปรับที่ "กำลังเดิน" ของเล่มที่ยังไม่ได้คืน
//
// 🔴 **ห้ามบวกรวมเข้ากับ $totalUnpaidAmount** — เป็นเงินคนละสถานะกัน
//    ค้างชำระ = คืนแล้ว ยอดถูกบันทึก ถึงกำหนดจ่ายแล้ว
//    กำลังเดิน = ยังไม่คืน ยอดยังโตทุกวัน ยังไม่ถูกบันทึกลงฐานข้อมูล
//
//    ระบบมี 6 query ที่นิยามคำว่า "ค้างชำระ" และ tests/test_fine_waiver.php หมวด C
//    บังคับให้ทั้ง 6 ที่ตรงกัน ถ้าเอายอดที่กำลังเดินยัดเข้าไปในตัวเลขนี้
//    นักเรียนจะเห็นเลขหนึ่ง แต่หน้าค่าปรับของเจ้าหน้าที่เห็นอีกเลขสำหรับคนเดียวกัน
//    → กลายเป็นบั๊ก "ตัวเลขเถียงกันข้ามหน้า" ตระกูลเดียวกับที่แก้ไปแล้วใน UAT รอบ 2
//
// 🧠 จึงแสดงเป็นบรรทัดแยก และใช้ calculateFine() ตัวเดียวกับหน้าเจ้าหน้าที่
$borrowService = new \App\Services\BorrowService($pdo);
$runningFineTotal = 0.0;
foreach ($borrowRepo->findAll(['user_id' => $_SESSION['user_id'], 'status' => 'borrowing']) as $b) {
    if ($b['due_date'] < date('Y-m-d')) {
        $runningFineTotal += $borrowService->calculateFine($b['due_date'], null)['amount'];
    }
}

// 🔖 จำนวนรายการจองที่รอดำเนินการ
$pendingReservations = $reservationRepo->findByUser($_SESSION['user_id'], 'pending');

// ── POST: อัปเดตโปรไฟล์ / เปลี่ยนรหัสผ่าน ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ [SECURITY] CSRF
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง กรุณาลองใหม่');
        redirect(APP_URL . '/profile.php');
    }
    
    $action = $_POST['action'] ?? '';
    
    // ── Action: อัปเดตข้อมูลส่วนตัว ──
    // 🛡️ [SECURITY] อัปเดตได้เฉพาะ name/phone — email เปลี่ยนไม่ได้ผ่านหน้านี้
    //    ป้องกัน account takeover: ถ้าเปลี่ยน email ได้ คนที่ขโมย session อาจเปลี่ยน email แล้ว reset password ได้
    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        if (empty($name)) {
            $errors[] = 'กรุณากรอกชื่อ-นามสกุล';
        } elseif ($err = validateMaxLength($name, 100, 'ชื่อ')) {
            $errors[] = $err;
        }
        
        if (!empty($phone) && !isValidPhone($phone)) {
            $errors[] = 'เบอร์โทรต้องเป็นตัวเลข 9-10 หลัก';
        }
        
        if (empty($errors)) {
            $authService->updateProfile($_SESSION['user_id'], [
                'name' => $name,
                'phone' => $phone
            ]);
            $_SESSION['user_name'] = $name;
            setFlash('success', 'อัปเดตข้อมูลสำเร็จ');
            redirect(APP_URL . '/profile.php');
        }
    }
    
    // ── Action: เปลี่ยนรหัสผ่าน ──
    if ($action === 'change_password') {
        // 🛡️ [SECURITY] Rate limiting สำหรับการลองรหัสผ่าน (brute force old password)
        $rateLimitKey = 'password_change';
        
        if (!checkRateLimit($rateLimitKey)) {
            $errors[] = 'ลองผิดหลายครั้งเกินไป กรุณารอ ' . RATE_LIMIT_WINDOW_MINUTES . ' นาที';
        } else {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            // Validation (ใช้ helper function)
            if ($err = validatePassword($newPassword)) {
                $errors[] = $err;
            }
            
            if ($newPassword !== $confirmPassword) {
                $errors[] = 'รหัสผ่านใหม่ไม่ตรงกัน';
            }
            
            if (empty($errors)) {
                $result = $authService->changePassword($_SESSION['user_id'], $currentPassword, $newPassword);
                
                if ($result['success']) {
                    resetRateLimit($rateLimitKey);
                    setFlash('success', 'เปลี่ยนรหัสผ่านสำเร็จ');
                    redirect(APP_URL . '/profile.php');
                } else {
                    incrementRateLimit($rateLimitKey);
                    $errors[] = $result['error'];
                }
            }
        }
    }
    
    // 🔄 โหลดข้อมูล user ใหม่หลัง POST (เผื่อแสดงข้อมูลล่าสุดใน form)
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
            
            <!-- Quick Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <!-- Active Borrows -->
                <a href="<?= APP_URL ?>/my_borrows.php" class="bg-white rounded-2xl shadow-sm border <?= $activeBorrows > 0 ? 'border-blue-200' : 'border-gray-100' ?> p-5 hover:shadow-md transition-all group">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500">หนังสือที่กำลังยืม</p>
                            <p class="text-2xl font-bold text-primary-600"><?= $activeBorrows ?> <span class="text-sm font-normal text-gray-500">เล่ม</span></p>
                        </div>
                        <div class="w-12 h-12 bg-primary-100 rounded-xl flex items-center justify-center group-hover:bg-primary-200 transition-colors">
                            <i class="bi bi-book text-xl text-primary-600"></i>
                        </div>
                    </div>
                </a>
                
                <!-- Pending Reservations -->
                <a href="<?= APP_URL ?>/my_reservations.php" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 hover:shadow-md transition-all group">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500">รายการจองที่รอมารับ</p>
                            <p class="text-2xl font-bold text-primary-600"><?= count($pendingReservations) ?> <span class="text-sm font-normal text-gray-500">รายการ</span></p>
                        </div>
                        <div class="w-12 h-12 bg-primary-100 rounded-xl flex items-center justify-center group-hover:bg-primary-200 transition-colors">
                            <i class="bi bi-bookmark-check text-xl text-primary-600"></i>
                        </div>
                    </div>
                </a>
                
                <!-- Unpaid Fines -->
                <div class="bg-white rounded-2xl shadow-sm border <?= $totalUnpaidAmount > 0 ? 'border-red-200 bg-red-50/30' : 'border-gray-100' ?> p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm <?= $totalUnpaidAmount > 0 ? 'text-red-600' : 'text-gray-500' ?>">ค่าปรับค้างชำระ</p>
                            <p class="text-2xl font-bold <?= $totalUnpaidAmount > 0 ? 'text-red-600' : 'text-green-600' ?>">
                                <?= number_format($totalUnpaidAmount, 2) ?> <span class="text-sm font-normal">บาท</span>
                            </p>
                            <?php // 💰 [UAT รอบ 5] บรรทัดแยก ไม่รวมกับยอดข้างบน (ดูเหตุผลตรงที่คำนวณ) ?>
                            <?php if ($runningFineTotal > 0): ?>
                                <p class="text-xs text-amber-700 mt-1.5 leading-relaxed">
                                    <i class="bi bi-clock-history mr-1"></i>
                                    ค่าปรับถึงวันนี้อีก <span class="font-bold"><?= number_format($runningFineTotal, 2) ?></span> บาท
                                    จากเล่มที่ยังไม่ได้คืน — คืนเร็วยอดหยุดเร็ว
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="w-12 h-12 <?= $totalUnpaidAmount > 0 ? 'bg-red-100' : 'bg-green-100' ?> rounded-xl flex items-center justify-center">
                            <i class="bi bi-cash-coin text-xl <?= $totalUnpaidAmount > 0 ? 'text-red-600' : 'text-green-600' ?>"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($unpaidFines)): ?>
            <!-- Unpaid Fines Detail -->
            <div class="bg-white rounded-2xl shadow-sm border border-red-200 overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-red-100 bg-red-50">
                    <h5 class="font-bold text-red-800 flex items-center">
                        <i class="bi bi-exclamation-triangle mr-2 text-red-600"></i>รายการค่าปรับค้างชำระ
                    </h5>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-500 uppercase bg-gray-50 border-b border-gray-100">
                            <tr>
                                <th class="px-6 py-4 font-medium">หนังสือ</th>
                                <th class="px-6 py-4 font-medium">กำหนดคืน</th>
                                <th class="px-6 py-4 font-medium">วันที่คืน</th>
                                <th class="px-6 py-4 font-medium text-right">ค่าปรับ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($unpaidFines as $fine): ?>
                                <tr class="hover:bg-gray-50/50 transition-colors">
                                    <td class="px-6 py-4 font-medium text-gray-900"><?= e($fine['book_title']) ?></td>
                                    <td class="px-6 py-4 text-gray-600"><?= formatDate($fine['due_date']) ?></td>
                                    <td class="px-6 py-4 text-gray-600"><?= formatDate($fine['return_date']) ?></td>
                                    <td class="px-6 py-4 text-right font-semibold text-red-600"><?= number_format($fine['fine_amount'], 2) ?> บาท</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-red-50">
                            <tr>
                                <td colspan="3" class="px-6 py-3 text-right font-bold text-gray-700">รวมทั้งหมด</td>
                                <td class="px-6 py-3 text-right font-bold text-red-600"><?= number_format($totalUnpaidAmount, 2) ?> บาท</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="px-6 py-4 bg-red-50 border-t border-red-100">
                    <p class="text-sm text-red-700">
                        <i class="bi bi-info-circle mr-1"></i>
                        กรุณาติดต่อเจ้าหน้าที่เพื่อชำระค่าปรับ
                    </p>
                </div>
            </div>
            <?php endif; ?>
            
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
