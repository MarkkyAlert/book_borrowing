<?php
/**
 * My Borrows - ประวัติการยืมของฉัน
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้แสดงรายการยืมเฉพาะของ user ที่ login (session user_id)
 * - สิทธิ์: ต้อง login (ทุก role)
 * 
 * 📂 Flow:
 * GET → BorrowRepository::findAll(user_id + filters) → แสดงรายการ (filter: status, search)
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/bootstrap.php';

// 🔒 [AUTH] ต้อง login — ดูได้เฉพาะรายการยืมของตัวเองเท่านั้น
requireLogin();

$pdo = getDB();
// 🛡️ [AUTH] ใช้ user_id จาก session — ป้องกันดูข้อมูลคนอื่น (ไม่รับ user_id จาก GET/POST)
$userId = $_SESSION['user_id'];

use App\Repositories\BorrowRepository;
use App\Services\BorrowService;
$borrowRepo = new BorrowRepository($pdo);

// 💰 [UAT รอบ 5] ต้องใช้ Service เพื่อคำนวณ "ค่าปรับถึงวันนี้" ของเล่มที่ยังไม่ได้คืน
//
// 🔴 ปัญหาเดิม: หน้านี้อ่าน borrows.fine_amount จากฐานข้อมูลตรง ๆ ซึ่งระบบเขียนลงไป
//    ตอน**รับคืน**เท่านั้น เล่มที่ยังค้างอยู่จึงเป็น 0.00 เสมอ
//    ผลคือนักเรียนที่เลยกำหนดมา 10 วันเห็นแค่ "(เลยกำหนด 10 วัน)" ไม่มีตัวเลขเงินเลย
//    ทั้งที่เจ้าหน้าที่เปิดหน้าเดียวกันเห็น "100 บาท" ชัด ๆ
//
// 🧠 ใช้ calculateFine() ตัวเดียวกับ admin/borrows.php ไม่คำนวณเอง
//    เพื่อให้ตัวเลขสองฝั่งตรงกันเสมอ **รวมถึงการหักวันที่ห้องสมุดปิด**
//    ถ้าคำนวณเองที่นี่ วันหนึ่งกฎเปลี่ยนแล้วสองฝั่งจะเถียงกันเงียบ ๆ
//
// ⚡ ต้นทุน: สมาชิกยืมได้สูงสุด 3 เล่ม จึงเรียกไม่เกิน 3 ครั้งต่อหน้า
//    และ ClosedDayRepository มี cache ระดับ request อยู่แล้ว
$borrowService = new BorrowService($pdo);

// 📥 รับ filter จาก query string
$statusFilter = $_GET['status'] ?? '';

// 🔧 สร้าง filter array — บังคับดูเฉพาะ user_id ของตัวเองเสมอ
$filters = ['user_id' => $userId];

if ($statusFilter === 'active') {
    $filters['status'] = 'borrowing';  // กำลังยืม
} elseif ($statusFilter === 'returned') {
    $filters['status'] = 'returned';   // คืนแล้ว
} elseif ($statusFilter === 'overdue') {
    $filters['overdue'] = true;        // เกินกำหนด
}

// 📚 ดึงรายการยืมของ user นี้ (พร้อม JOIN book_title, book_author)
$borrows = $borrowRepo->findAll($filters);

// 📊 ดึงสถิติสำหรับ stat cards (active_borrows, returned, total_borrows)
$stats = $borrowRepo->getStatsByUser($userId);

// ⏰ นับจำนวนรายการเกินกำหนด (สำหรับแสดง badge สีแดง)
$overdueCount = 0;
foreach ($borrows as $borrow) {
    if ($borrow['status'] === 'borrowing' && strtotime($borrow['due_date']) < strtotime('today')) {
        $overdueCount++;
    }
}

$pageTitle = 'รายการยืมของฉัน';
require_once __DIR__ . '/includes/header.php';

// ── Helper functions สำหรับใช้ใน template ด้านล่าง ──
// 🧮 ตรวจว่าเกินกำหนดหรือไม่
function isOverdue($borrow): bool {
    return $borrow['status'] === 'borrowing' && strtotime($borrow['due_date']) < strtotime('today');
}

// 🧮 ตรวจว่าครบกำหนดวันนี้หรือไม่
function isDueToday($borrow): bool {
    return $borrow['status'] === 'borrowing' && $borrow['due_date'] === date('Y-m-d');
}

// 📅 คำนวณจำนวนวันที่เหลือ (ลบ = เลยกำหนด, บวก = เหลืออยู่)
function getDaysRemaining($dueDate): int {
    $today = strtotime('today');
    $due = strtotime($dueDate);
    return (int) (($due - $today) / (60 * 60 * 24));
}
?>

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900">
            <i class="bi bi-book text-primary-600 mr-2"></i>
            รายการยืมของฉัน
        </h1>
        <p class="mt-2 text-gray-600">ดูประวัติการยืมและติดตามหนังสือที่กำลังยืมอยู่</p>
    </div>

    <?php displayFlash(); ?>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">กำลังยืม</p>
                    <p class="text-2xl font-bold text-primary-600"><?= $stats['active_borrows'] ?? 0 ?></p>
                </div>
                <div class="w-12 h-12 bg-primary-100 rounded-xl flex items-center justify-center">
                    <i class="bi bi-book text-xl text-primary-600"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-sm border border-red-200 p-5 <?= ($overdueCount > 0) ? 'bg-red-50/50' : '' ?>">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm <?= ($overdueCount > 0) ? 'text-red-600' : 'text-gray-500' ?>">ครบกำหนดคืนแล้ว</p>
                    <p class="text-2xl font-bold <?= ($overdueCount > 0) ? 'text-red-600' : 'text-gray-600' ?>"><?= $overdueCount ?></p>
                </div>
                <div class="w-12 h-12 <?= ($overdueCount > 0) ? 'bg-red-100' : 'bg-gray-100' ?> rounded-xl flex items-center justify-center">
                    <i class="bi bi-exclamation-triangle text-xl <?= ($overdueCount > 0) ? 'text-red-600' : 'text-gray-400' ?>"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">คืนแล้ว</p>
                    <p class="text-2xl font-bold text-green-600"><?= $stats['returned'] ?? 0 ?></p>
                </div>
                <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
                    <i class="bi bi-check-circle text-xl text-green-600"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">ยืมทั้งหมด</p>
                    <p class="text-2xl font-bold text-gray-700"><?= $stats['total_borrows'] ?? 0 ?></p>
                </div>
                <div class="w-12 h-12 bg-gray-100 rounded-xl flex items-center justify-center">
                    <i class="bi bi-collection text-xl text-gray-600"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="mb-6 border-b border-gray-200">
        <nav class="-mb-px flex space-x-8 overflow-x-auto">
            <a href="?status=" 
               class="<?= $statusFilter === '' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap pb-4 px-1 border-b-2 font-medium text-sm">
                ทั้งหมด
            </a>
            <a href="?status=active" 
               class="<?= $statusFilter === 'active' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap pb-4 px-1 border-b-2 font-medium text-sm">
                กำลังยืม
            </a>
            <a href="?status=overdue" 
               class="<?= $statusFilter === 'overdue' ? 'border-red-500 text-red-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap pb-4 px-1 border-b-2 font-medium text-sm">
                ครบกำหนดคืนแล้ว
            </a>
            <a href="?status=returned" 
               class="<?= $statusFilter === 'returned' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap pb-4 px-1 border-b-2 font-medium text-sm">
                คืนแล้ว
            </a>
        </nav>
    </div>

    <!-- Borrows List -->
    <?php if (empty($borrows)): ?>
        <div class="text-center py-16 bg-white rounded-2xl shadow-sm border border-gray-100">
            <div class="w-20 h-20 mx-auto bg-gray-100 rounded-full flex items-center justify-center mb-4">
                <i class="bi bi-book text-4xl text-gray-400"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">ไม่พบรายการยืม</h3>
            <p class="text-gray-500 mb-6"><?= $statusFilter ? 'ไม่มีรายการในหมวดหมู่นี้' : 'คุณยังไม่มีประวัติการยืมหนังสือ' ?></p>
            <a href="<?= APP_URL ?>" class="inline-flex items-center px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition-colors">
                <i class="bi bi-search mr-2"></i>
                ค้นหาหนังสือ
            </a>
        </div>
    <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($borrows as $borrow): ?>
                <?php
                    $isOverdue = isOverdue($borrow);
                    $isDueToday = isDueToday($borrow);
                    $daysRemaining = getDaysRemaining($borrow['due_date']);
                    
                    if ($borrow['status'] === 'returned') {
                        $statusClass = 'bg-green-100 text-green-800';
                        $statusLabel = 'คืนแล้ว';
                    // 📚 หาย/ชำรุด ต้องมาก่อนตัวเช็คเกินกำหนด
                    //    ไม่งั้นเล่มที่แจ้งหายตอนเลยกำหนดจะขึ้นป้าย "ครบกำหนดคืนแล้ว" ทั้งที่ปิดรายการไปแล้ว
                    } elseif ($borrow['status'] === 'lost') {
                        $statusClass = 'bg-orange-100 text-orange-800';
                        $statusLabel = 'แจ้งหาย';
                    } elseif ($borrow['status'] === 'damaged') {
                        $statusClass = 'bg-purple-100 text-purple-800';
                        $statusLabel = 'ชำรุด';
                    } elseif ($isOverdue) {
                        $statusClass = 'bg-red-100 text-red-800';
                        $statusLabel = 'ครบกำหนดคืนแล้ว';
                    } elseif ($isDueToday) {
                        $statusClass = 'bg-amber-100 text-amber-800';
                        $statusLabel = 'ครบกำหนดวันนี้';
                    } else {
                        $statusClass = 'bg-blue-100 text-blue-800';
                        $statusLabel = 'กำลังยืม';
                    }
                ?>
                <div class="bg-white rounded-xl shadow-sm border <?= $isOverdue ? 'border-red-200' : 'border-gray-100' ?> p-5 hover:shadow-md transition-shadow">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div class="flex-1">
                            <div class="flex items-start gap-3">
                                <div class="w-12 h-12 <?= $isOverdue ? 'bg-red-100' : 'bg-primary-100' ?> rounded-lg flex items-center justify-center flex-shrink-0">
                                    <i class="bi bi-book text-xl <?= $isOverdue ? 'text-red-600' : 'text-primary-600' ?>"></i>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-gray-900">
                                        <a href="book.php?id=<?= $borrow['book_id'] ?>" class="hover:text-primary-600 transition-colors">
                                            <?= e($borrow['book_title']) ?>
                                        </a>
                                    </h3>
                                    <p class="text-sm text-gray-500"><?= e($borrow['book_author']) ?></p>
                                    <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-gray-500">
                                        <span>
                                            <i class="bi bi-calendar3 mr-1"></i>
                                            ยืมเมื่อ: <?= date('d/m/Y', strtotime($borrow['borrow_date'])) ?>
                                        </span>
                                        <?php if ($borrow['status'] === 'borrowing'): ?>
                                            <span class="<?= $isOverdue ? 'text-red-600 font-medium' : ($isDueToday ? 'text-amber-600 font-medium' : '') ?>">
                                                <i class="bi bi-clock mr-1"></i>
                                                กำหนดคืน: <?= date('d/m/Y', strtotime($borrow['due_date'])) ?>
                                                <?php if ($isOverdue): ?>
                                                    <span class="text-red-500">(เลยกำหนด <?= abs($daysRemaining) ?> วัน)</span>
                                                    <?php // 💰 [UAT รอบ 5] บอกยอดที่เดินอยู่ ณ วันนี้
                                                          //    ⚠️ ไม่ใช้คำว่า "ค้างชำระ" เพราะยังไม่ถึงกำหนดจ่าย
                                                          //       และยอดนี้ยังไม่ถูกบันทึกลงฐานข้อมูล (บันทึกตอนรับคืน)
                                                          //    🧠 บอกด้วยว่าคืนเร็วยอดหยุด — ไม่งั้นเห็นตัวเลขแล้วท้อ ไม่มาคืนเลย
                                                          $runningFine = $borrowService->calculateFine($borrow['due_date'], null); ?>
                                                    <?php if ($runningFine['amount'] > 0): ?>
                                                        <span class="block sm:inline text-red-600 font-semibold mt-1 sm:mt-0 sm:ml-1">
                                                            <i class="bi bi-cash-coin mr-1"></i>ค่าปรับถึงวันนี้ <?= number_format($runningFine['amount'], 2) ?> บาท
                                                            <span class="font-normal text-gray-500">— คืนเร็วยอดหยุดเร็ว</span>
                                                        </span>
                                                    <?php endif; ?>
                                                <?php elseif ($isDueToday): ?>
                                                    <span class="text-amber-600">(วันนี้!)</span>
                                                <?php else: ?>
                                                    <span class="text-gray-400">(เหลือ <?= $daysRemaining ?> วัน)</span>
                                                <?php endif; ?>
                                            </span>
                                        <?php elseif (in_array($borrow['status'], ['lost', 'damaged'], true)): ?>
                                            <?php // 📚 หาย/ชำรุด — ไม่มี return_date (หนังสือไม่ได้กลับมา)
                                                  //    ถ้าใช้ strtotime(null) จะได้ 01/01/1970 + คำเตือน deprecated บน PHP 8.1+ ?>
                                            <span class="text-orange-600">
                                                <i class="bi bi-exclamation-triangle mr-1"></i>
                                                แจ้ง<?= $borrow['status'] === 'lost' ? 'หาย' : 'ชำรุด' ?>เมื่อ:
                                                <?= !empty($borrow['lost_reported_at']) ? date('d/m/Y', strtotime($borrow['lost_reported_at'])) : '-' ?>
                                            </span>
                                            <?php if ($borrow['fine_amount'] > 0): ?>
                                                <?php if (!empty($borrow['fine_waived_at'])): ?>
                                                    <span class="text-gray-500 font-medium" title="ห้องสมุดยกเว้นค่าชดใช้ให้แล้ว">
                                                        <i class="bi bi-check-circle mr-1"></i>
                                                        ค่าชดใช้ <?= number_format($borrow['fine_amount'], 2) ?> บาท — <span class="text-green-600">ยกเว้นแล้ว</span>
                                                    </span>
                                                <?php else: ?>
                                                    <?php // 💰 เรียกว่า "ค่าชดใช้" ไม่ใช่ "ค่าปรับ" — คนละเรื่องกัน
                                                          //    ค่าปรับคือคืนช้า ค่าชดใช้คือหนังสือหายไปเลย ?>
                                                    <span class="text-red-600 font-medium">
                                                        <i class="bi bi-cash-coin mr-1"></i>
                                                        ค่าชดใช้: <?= number_format($borrow['fine_amount'], 2) ?> บาท
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-green-600">
                                                <i class="bi bi-check-circle mr-1"></i>
                                                คืนเมื่อ: <?= !empty($borrow['return_date']) ? date('d/m/Y', strtotime($borrow['return_date'])) : '-' ?>
                                            </span>
                                            <?php if ($borrow['fine_amount'] > 0): ?>
                                                <?php if (!empty($borrow['fine_waived_at'])): ?>
                                                    <?php // 💸 ยกเว้นแล้ว — ห้ามขึ้นสีแดงเหมือนยอดที่ยังต้องจ่าย
                                                          //    สมาชิกต้องรู้ทันทีว่าไม่ต้องจ่ายแล้ว ?>
                                                    <span class="text-gray-500 font-medium" title="ห้องสมุดยกเว้นค่าปรับให้แล้ว">
                                                        <i class="bi bi-check-circle mr-1"></i>
                                                        ค่าปรับ <?= number_format($borrow['fine_amount'], 2) ?> บาท — <span class="text-green-600">ยกเว้นแล้ว</span>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-red-600 font-medium">
                                                        <i class="bi bi-cash-coin mr-1"></i>
                                                        ค่าปรับ: <?= number_format($borrow['fine_amount'], 2) ?> บาท
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex items-center">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?= $statusClass ?>">
                                <?= $statusLabel ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
