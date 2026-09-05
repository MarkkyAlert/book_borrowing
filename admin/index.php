<?php
/**
 * Admin Dashboard - หน้าหลักสำหรับ admin/staff
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้แสดง summary cards, charts, รายการล่าสุด, และ alerts ต่างๆ
 * - สิทธิ์: staff ขึ้นไป
 * - ข้อมูลทั้งหมดมาจาก DashboardService (read-only aggregator)
 * 
 * 📂 Flow:
 * 1. Expire overdue reservations (lazy — fallback สำหรับ cron ที่อาจไม่รัน)
 * 2. ดึงสถิติ cards (หนังสือ, สมาชิก, ยืม, เกินกำหนด, จอง)
 * 3. ดึง lists (ยืมล่าสุด, จองรอดำเนินการ, เกินกำหนด, ค้างชำระ, stock ใกล้หมด)
 * 4. ดึง chart data (monthly borrow, category distribution)
 * 
 * ⚠️ ระวัง:
 * - expireOverdueReservations() จะถูกเรียกทุกครั้งที่โหลด dashboard
 *   ถ้า cron ทำงานปกติ จะไม่มี expired rows ให้ process (ไม่กระทบ performance)
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB connection)
require_once __DIR__ . '/../bootstrap.php';
// 🔒 [AUTH] ต้องเป็น staff/admin เท่านั้น — member เข้าไม่ได้
requireStaff();

use App\Services\DashboardService;
use App\Services\ReservationService;

// 📦 สร้าง service instances — DashboardService เป็น read-only aggregator ดึงข้อมูลจากหลาย repository
$pdo = getDB();
$dashboardService = new DashboardService($pdo);

// [AUTO] Lazy expiration — หมดอายุการจองที่เกินกำหนด (fallback สำหรับ cron)
// 🧠 ถ้า cron ทำงานปกติ → ไม่มี expired rows = method รันเร็ว (ไม่กระทบ performance)
// ⚠️ ถ้า cron ไม่ทำงาน → dashboard จะเป็นด่านสุดท้ายที่ cleanup ให้
$reservationService = new ReservationService($pdo);
$reservationService->expireOverdueReservations();

// 📊 ดึงสถิติ summary cards — query เดียวได้หลายค่า (optimized)
$stats = $dashboardService->getCardStats();
$totalBooks = $stats['total_books'];
// 📖 [F-50] จำนวนชื่อเรื่อง — คนละตัวกับจำนวนเล่ม ตอนทำสำมะโนต้องใช้ทั้งคู่
$totalTitles = $stats['total_titles'];
$availableBooks = $stats['available_books'];
$borrowedBooks = $stats['borrowed_books'];
$totalMembers = $stats['total_members'];
$activeBorrows = $stats['active_borrows'];
$overdueBorrows = $stats['overdue_borrows'];
$dueSoonBorrows = $stats['due_soon_borrows'];   // 📞 ใกล้ครบกำหนด (กฎ DUE_SOON_DAYS)
$pendingReservations = $stats['pending_reservations'];

// 📋 ดึงรายการล่าสุด + alerts (จำกัดจำนวนเพื่อ performance)
$recentBorrows = $dashboardService->getRecentBorrows(5);       // 5 รายการยืมล่าสุด
$recentReservations = $dashboardService->getRecentReservations(5); // 5 การจองล่าสุด
$overdueList = $dashboardService->getOverdueList(10);           // 10 รายการเกินกำหนด
// 📞 ใกล้ครบกำหนด — ยังโทรตามทันก่อนจะกลายเป็นเกินกำหนด
//    ระบบไม่ส่งอีเมล (ดู docs/LIMITATIONS.md) การเตือนจึงเป็นรายชื่อให้โทร/LINE เอง
//    เอามา 12 รายการพอดีกับ 4 คอลัมน์ × 3 แถว ที่เหลือให้กดไปดูใบเต็มในหน้ารายงาน
$dueSoonList = $dashboardService->getDueSoonList(12);

// 📦 หนังสือที่ถูกยืมหมด (available = 0) — หัวการ์ดข้างล่างพูดว่า "ถูกยืมหมด"
//    🔴 [UAT รอบ 2 บั๊ก B] เดิมใช้ threshold = 2 ซึ่งคือ "ใกล้หมด" ไม่ใช่ "หมด"
//       ทำให้หัวข้อกับข้อมูลพูดคนละเรื่อง (ของจริงตอนเจอ: หมด 7 เล่ม แต่ ≤2 มี 186 เล่ม)
//       ปรับ threshold ให้ตรงกับหัวข้อ — 186 เล่มไม่ใช่ข้อมูลที่บรรณารักษ์ทำอะไรต่อได้
$lowStockBooks = $dashboardService->getLowStockBooks(0, 5);

// 🔢 จำนวน "จริง" สำหรับป้ายบนการ์ด — ห้ามใช้ count($lowStockBooks)
//    เพราะลิสต์ข้างบนถูก LIMIT 5 ป้ายจึงเกิน 5 ไม่ได้เลย (บั๊กเดิม: บอก 5 ตลอด ทั้งที่มี 7)
$outOfStockTotal = $dashboardService->getLowStockCount(0);

// 💰 รายการค้างชำระ — ใช้แสดงบน dashboard + PDF export
$unpaidFinesList = $dashboardService->getUnpaidFinesList(10);

// 📁 สถิติหมวดหมู่ทั้งหมด — ใช้ใน PDF export
$allCategoriesStats = $dashboardService->getAllCategoriesWithStats();

// 📈 ช่วงเวลาสำหรับกราฟ (default: 6 เดือน)
$chartRange = isset($_GET['range']) ? (int)$_GET['range'] : 6;
// [VALIDATION] Whitelist — อนุญาตเฉพาะค่าที่กำหนด ป้องกัน query ช่วงเวลาที่ไม่เหมาะสม
$validRanges = [1, 3, 6, 12];
if (!in_array($chartRange, $validRanges)) {
    $chartRange = 6;
}

// 📊 ดึงข้อมูลสำหรับ Chart.js (กราฟแท่ง, กราฟโดนัท)
$monthlyBorrows = $dashboardService->getMonthlyStats($chartRange); // สถิติรายเดือน (ยืม/คืน/ค่าปรับ)
$categoryStats = $dashboardService->getCategoryStats(6);           // Top 6 หมวดหมู่ที่ถูกยืมบ่อย
$totalFines = $dashboardService->getTotalFinesCollected();         // รายได้ค่าปรับรวม (ชำระแล้ว)
$unpaidFines = $dashboardService->getUnpaidFines();               // ยอดค้างชำระรวม
$topBorrowers = $dashboardService->getTopBorrowers(5);            // Top 5 นักอ่าน
$popularBooks = $dashboardService->getPopularBooks(5);            // Top 5 หนังสือยอดนิยม

// 🥧 ข้อมูลสำหรับ Pie Chart สถานะหนังสือ (พร้อมยืม vs กำลังถูกยืม)
$bookStatusData = [
    'available' => (int)$availableBooks,
    'borrowed' => (int)$borrowedBooks
];

// 🔄 แปลง PHP array → format ที่ Chart.js ใช้ได้ (จะถูก json_encode ใน <script>)
$chartLabels = array_column($monthlyBorrows, 'month_name');   // ชื่อเดือน (Jan, Feb, ...)
$chartBorrows = array_column($monthlyBorrows, 'total_borrows'); // จำนวนยืมแต่ละเดือน
$chartReturned = array_column($monthlyBorrows, 'returned');     // จำนวนคืนแต่ละเดือน
$chartFines = array_column($monthlyBorrows, 'total_fines');     // ค่าปรับแต่ละเดือน

$categoryLabels = array_column($categoryStats, 'name');        // ชื่อหมวดหมู่
$categoryData = array_column($categoryStats, 'borrow_count');  // จำนวนยืมแต่ละหมวดหมู่

$pageTitle = 'ภาพรวม';
require_once __DIR__ . '/header.php';
?>

<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-6 mb-8">
    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 hover:shadow-md transition-shadow">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">สมาชิกทั้งหมด</p>
                <h3 class="text-2xl font-bold text-gray-800"><?= number_format($totalMembers) ?></h3>
            </div>
            <div class="p-3 bg-violet-100 text-violet-600 rounded-xl">
                <i class="bi bi-people text-xl"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 hover:shadow-md transition-shadow">
        <div class="flex justify-between items-start">
            <div>
                <?php // 🔴 [F-50] เดิมเขียนว่า "หนังสือทั้งหมด" เฉย ๆ ซึ่งอ่านได้สองแบบ
                      //    ตัวเลขที่แสดงคือจำนวน **เล่ม** (นับซ้ำเล่มที่มีหลายก๊อปปี้)
                      //    ส่วนจำนวน **ชื่อเรื่อง** ไม่เคยปรากฏบนหน้านี้เลย
                      //    ทั้งที่ตอนทำสำมะโนหนังสือต้องใช้ทั้งสองตัวคู่กัน ?>
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">หนังสือทั้งหมด (เล่ม)</p>
                <h3 class="text-2xl font-bold text-gray-800"><?= number_format($totalBooks) ?></h3>
                <p class="text-xs text-gray-500 mt-1">
                    <i class="bi bi-journals mr-1"></i><?= number_format($totalTitles) ?> ชื่อเรื่อง
                </p>
            </div>
            <div class="p-3 bg-blue-100 text-blue-600 rounded-xl">
                <i class="bi bi-book text-xl"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 hover:shadow-md transition-shadow">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">พร้อมให้ยืม</p>
                <h3 class="text-2xl font-bold text-gray-800"><?= number_format($availableBooks) ?></h3>
            </div>
            <div class="p-3 bg-green-100 text-green-600 rounded-xl">
                <i class="bi bi-check-circle text-xl"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 hover:shadow-md transition-shadow">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">กำลังยืม</p>
                <h3 class="text-2xl font-bold text-gray-800"><?= number_format($activeBorrows) ?></h3>
            </div>
            <div class="p-3 bg-amber-100 text-amber-600 rounded-xl">
                <i class="bi bi-hourglass-split text-xl"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 hover:shadow-md transition-shadow">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">รอมารับ</p>
                <h3 class="text-2xl font-bold text-indigo-600"><?= number_format($pendingReservations) ?></h3>
            </div>
            <div class="p-3 bg-indigo-100 text-indigo-600 rounded-xl">
                <i class="bi bi-bookmark-star text-xl"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 hover:shadow-md transition-shadow">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">เกินกำหนด</p>
                <h3 class="text-2xl font-bold text-red-600"><?= number_format($overdueBorrows) ?></h3>
            </div>
            <div class="p-3 bg-red-100 text-red-600 rounded-xl">
                <i class="bi bi-exclamation-triangle text-xl"></i>
            </div>
        </div>
    </div>
</div>

<!-- Charts Section -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-6 mb-8">
    <!-- Total Fines Card (Compact) -->
    <div class="lg:col-span-3">
        <a href="payments.php" class="block bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl p-5 text-white shadow-lg shadow-green-500/20 hover:shadow-xl transition-shadow h-full">
            <div class="flex items-center justify-between mb-3">
                <div class="p-2 bg-white/20 rounded-lg">
                    <i class="bi bi-cash-coin text-xl"></i>
                </div>
                <i class="bi bi-arrow-right text-green-200"></i>
            </div>
            <h3 class="text-2xl font-bold mb-0.5"><?= number_format($totalFines) ?> ฿</h3>
            <p class="text-green-100 text-xs font-medium mb-3">รายได้ค่าปรับ (ชำระแล้ว)</p>
            
            <div class="bg-white/10 rounded-lg p-2 backdrop-blur-sm border border-white/20">
                <div class="flex justify-between items-center text-xs">
                    <span class="text-green-50">ค้างชำระ:</span>
                    <span class="font-bold text-white"><?= number_format($unpaidFines) ?> ฿</span>
                </div>
            </div>
            <p class="text-green-200 text-xs mt-2 text-center">คลิกดูรายละเอียด →</p>
        </a>
    </div>
    
    <!-- Monthly Borrows Chart -->
    <div class="lg:col-span-5">
        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 h-full">
            <div class="flex justify-between items-center mb-4">
                <h6 class="font-bold text-gray-800 flex items-center">
                    <i class="bi bi-bar-chart text-primary-500 mr-2"></i>สถิติการยืม
                </h6>
                <div class="flex items-center gap-2">
                    <!-- Date Range Selector -->
                    <select id="chartRange" onchange="changeChartRange(this.value)" 
                            class="text-xs border border-gray-200 rounded-lg px-2 py-1.5 bg-gray-50 focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="1" <?= $chartRange == 1 ? 'selected' : '' ?>>1 เดือน</option>
                        <option value="3" <?= $chartRange == 3 ? 'selected' : '' ?>>3 เดือน</option>
                        <option value="6" <?= $chartRange == 6 ? 'selected' : '' ?>>6 เดือน</option>
                        <option value="12" <?= $chartRange == 12 ? 'selected' : '' ?>>1 ปี</option>
                    </select>
                </div>
            </div>
            <div class="relative h-64">
                <canvas id="borrowChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Category Distribution Chart -->
    <div class="lg:col-span-4">
        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 h-full">
            <h6 class="font-bold text-gray-800 mb-4 flex items-center">
                <i class="bi bi-pie-chart text-primary-500 mr-2"></i>Top 6 หมวดหมู่ยอดนิยม
            </h6>
            <div class="relative h-64">
                <canvas id="categoryChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- Top Borrowers -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h5 class="font-bold text-gray-800 flex items-center">
                <i class="bi bi-trophy text-amber-500 mr-2"></i>
                สมาชิกยืมสูงสุด
            </h5>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="text-xs text-gray-500 uppercase bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 font-medium">สมาชิก</th>
                        <th class="px-6 py-3 font-medium">จำนวนยืม (เล่ม)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($topBorrowers)): ?>
                        <tr><td colspan="2" class="px-6 py-4 text-center text-gray-500">ไม่มีข้อมูล</td></tr>
                    <?php else: ?>
                        <?php foreach ($topBorrowers as $idx => $user): ?>
                            <tr class="hover:bg-gray-50/50 transition-colors">
                                <td class="px-6 py-3 flex items-center">
                                    <span class="w-6 h-6 rounded-full bg-gray-100 text-gray-500 flex items-center justify-center text-xs mr-3 font-bold border border-gray-200">
                                        <?= $idx + 1 ?>
                                    </span>
                                    <div>
                                        <div class="font-medium text-gray-900"><?= e($user['name']) ?></div>
                                        <div class="text-xs text-gray-500"><?= e($user['email']) ?></div>
                                    </div>
                                </td>
                                <td class="px-6 py-3 font-bold text-primary-600">
                                    <?= number_format($user['borrow_count']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Popular Books -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h5 class="font-bold text-gray-800 flex items-center">
                <i class="bi bi-fire text-red-500 mr-2"></i>
                หนังสือยอดนิยม
            </h5>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="text-xs text-gray-500 uppercase bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 font-medium">หนังสือ</th>
                        <th class="px-6 py-3 font-medium">ถูกยืม (ครั้ง)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($popularBooks)): ?>
                        <tr><td colspan="2" class="px-6 py-4 text-center text-gray-500">ไม่มีข้อมูล</td></tr>
                    <?php else: ?>
                        <?php foreach ($popularBooks as $idx => $book): ?>
                            <tr class="hover:bg-gray-50/50 transition-colors">
                                <td class="px-6 py-3 flex items-center">
                                    <span class="w-6 h-6 rounded-full bg-gray-100 text-gray-500 flex items-center justify-center text-xs mr-3 font-bold border border-gray-200">
                                        <?= $idx + 1 ?>
                                    </span>
                                    <div>
                                        <div class="font-medium text-gray-900 line-clamp-1"><?= e($book['title']) ?></div>
                                        <div class="text-xs text-gray-500"><?= e($book['author']) ?></div>
                                    </div>
                                </td>
                                <td class="px-6 py-3 font-bold text-emerald-600">
                                    <?= number_format($book['borrow_count']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <!-- Overdue List -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h5 class="font-bold text-gray-800 flex items-center">
                <span class="w-2 h-2 rounded-full bg-red-500 mr-2 animate-pulse"></span>
                รายการเกินกำหนด
            </h5>
            <a href="borrows.php?filter=overdue" class="text-xs font-semibold text-red-600 hover:text-red-700 hover:bg-red-50 px-3 py-1 rounded-full transition-colors">
                ดูทั้งหมด
            </a>
        </div>
        <div class="overflow-x-auto">
            <?php if (empty($overdueList)): ?>
                <div class="text-center py-12 text-gray-400">
                    <i class="bi bi-check-circle text-4xl text-green-500 mb-2 inline-block"></i>
                    <p class="text-sm">ไม่มีรายการเกินกำหนด</p>
                </div>
            <?php else: ?>
                <table class="w-full text-sm text-left">
                    <thead class="text-xs text-gray-500 uppercase bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 font-medium">หนังสือ</th>
                            <th class="px-6 py-3 font-medium">ผู้ยืม</th>
                            <th class="px-6 py-3 font-medium">เกิน (วัน)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($overdueList as $item): ?>
                            <?php $daysOverdue = daysDiff($item['due_date'], date('Y-m-d')); ?>
                            <tr class="hover:bg-red-50/10 transition-colors">
                                <td class="px-6 py-4 font-medium text-gray-900 line-clamp-1 max-w-[200px]"><?= e($item['book_title']) ?></td>
                                <td class="px-6 py-4">
                                    <div class="font-medium text-gray-900"><?= e($item['user_name']) ?></div>
                                    <?php if ($item['phone']): ?>
                                        <div class="text-xs text-gray-500 mt-0.5"><?= e($item['phone']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                        <?= $daysOverdue ?> วัน
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Pending Reservations List -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h5 class="font-bold text-gray-800 flex items-center">
                <i class="bi bi-bookmark-star text-indigo-500 mr-2"></i>
                การจองล่าสุด
            </h5>
            <a href="reservations.php" class="text-xs font-semibold text-indigo-600 hover:text-indigo-700 hover:bg-indigo-50 px-3 py-1 rounded-full transition-colors">
                จัดการ
            </a>
        </div>
        <div class="overflow-x-auto">
            <?php if (empty($recentReservations)): ?>
                <div class="text-center py-12 text-gray-400">
                    <p class="text-sm">ไม่มีรายการจองใหม่</p>
                </div>
            <?php else: ?>
                <table class="w-full text-sm text-left">
                    <thead class="text-xs text-gray-500 uppercase bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 font-medium">หนังสือ</th>
                            <th class="px-6 py-3 font-medium">ผู้จอง</th>
                            <th class="px-6 py-3 font-medium">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($recentReservations as $res): ?>
                            <tr class="hover:bg-indigo-50/30 transition-colors">
                                <td class="px-6 py-4 font-medium text-gray-900 line-clamp-1 max-w-[200px]"><?= e($res['book_title']) ?></td>
                                <td class="px-6 py-4 text-gray-600"><?= e($res['user_name']) ?></td>
                                <td class="px-6 py-4">
                                     <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">
                                        รอรับ
                                     </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Borrows -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h5 class="font-bold text-gray-800 flex items-center">
                <i class="bi bi-clock-history text-primary-500 mr-2"></i>
                การยืมล่าสุด
            </h5>
            <a href="borrows.php" class="text-xs font-semibold text-primary-600 hover:text-primary-700 hover:bg-primary-50 px-3 py-1 rounded-full transition-colors">
                ดูทั้งหมด
            </a>
        </div>
        <div class="overflow-x-auto">
            <?php if (empty($recentBorrows)): ?>
                <div class="text-center py-12 text-gray-400">
                    <p class="text-sm">ยังไม่มีรายการยืม</p>
                </div>
            <?php else: ?>
                <table class="w-full text-sm text-left">
                    <thead class="text-xs text-gray-500 uppercase bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 font-medium">หนังสือ</th>
                            <th class="px-6 py-3 font-medium">ผู้ยืม</th>
                            <th class="px-6 py-3 font-medium">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($recentBorrows as $borrow): ?>
                            <tr class="hover:bg-gray-50/50 transition-colors">
                                <td class="px-6 py-4 font-medium text-gray-900 line-clamp-1 max-w-[200px]"><?= e($borrow['book_title']) ?></td>
                                <td class="px-6 py-4 text-gray-600"><?= e($borrow['user_name']) ?></td>
                                <td class="px-6 py-4">
                                     <?= getBorrowStatusLabel($borrow['status'], $borrow['due_date']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 📞 ใกล้ครบกำหนด — ใบรายชื่อโทรตาม -->
<?php // 🧠 วางเป็นแถบเต็มความกว้างแบบเดียวกับ "หนังสือที่ถูกยืมหมด" ข้างล่าง
      //    ไม่ทำเป็นการ์ดสถิติใบที่ 7 เพราะแถวการ์ดเป็น grid 6 ช่อง ใบที่ 7 จะตกไปอยู่แถวใหม่ตัวเดียว
      //    และตัวเลขเฉย ๆ กดต่อไม่ได้ — สิ่งที่บรรณารักษ์ต้องการคือ "เบอร์โทร" ไม่ใช่ "จำนวน"
      // 🔴 ขึ้นเฉพาะตอนมีรายการจริง ไม่งั้นหน้าภาพรวมจะมีกล่องว่างถาวร ?>
<?php if (!empty($dueSoonList)): ?>
<div class="bg-gradient-to-r from-sky-50 to-blue-50 rounded-2xl shadow-sm border border-sky-200 p-6 mb-8">
    <div class="flex justify-between items-center mb-4">
        <h5 class="font-bold text-sky-800 flex items-center">
            <i class="bi bi-telephone text-sky-500 mr-2"></i>
            ใกล้ครบกำหนด — โทรเตือนได้เลย
            <span class="ml-2 px-2 py-0.5 bg-sky-500 text-white text-xs rounded-full"><?= number_format($dueSoonBorrows) ?></span>
        </h5>
        <div class="flex items-center gap-2">
            <span class="text-xs text-sky-700">ภายใน <?= DUE_SOON_DAYS ?> วัน</span>
            <a href="reports.php?report=due_soon" class="text-xs font-semibold text-sky-600 hover:text-sky-700 hover:bg-sky-100 px-3 py-1 rounded-full transition-colors">
                <i class="bi bi-printer mr-1"></i>ใบรายชื่อทั้งหมด
            </a>
        </div>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
        <?php foreach ($dueSoonList as $item): ?>
            <?php $daysLeft = (int) $item['days_left']; ?>
            <div class="bg-white rounded-xl p-3 border border-sky-100 hover:shadow-md transition-shadow">
                <div class="font-medium text-gray-900 text-sm line-clamp-1"><?= e($item['user_name']) ?></div>
                <?php // 📞 เบอร์โทรคือของที่ต้องใช้จริง ทำเป็นลิงก์กดโทรจากมือถือได้เลย ?>
                <?php if (!empty($item['phone'])): ?>
                    <a href="tel:<?= e($item['phone']) ?>" class="text-xs text-sky-600 hover:underline"><?= e($item['phone']) ?></a>
                <?php else: ?>
                    <span class="text-xs text-gray-400">ไม่มีเบอร์โทร</span>
                <?php endif; ?>
                <div class="text-xs text-gray-500 line-clamp-1 mt-1"><?= e($item['book_title']) ?></div>
                <div class="mt-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold <?= $daysLeft === 0 ? 'bg-amber-100 text-amber-800' : 'bg-sky-100 text-sky-700' ?>">
                        <?= $daysLeft === 0 ? 'ครบกำหนดวันนี้' : 'อีก ' . $daysLeft . ' วัน' ?>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Low Stock Alert -->
<?php if (!empty($lowStockBooks)): ?>
<div class="bg-gradient-to-r from-amber-50 to-orange-50 rounded-2xl shadow-sm border border-amber-200 p-6 mb-8">
    <div class="flex justify-between items-center mb-4">
        <h5 class="font-bold text-amber-800 flex items-center">
            <i class="bi bi-exclamation-triangle text-amber-500 mr-2"></i>
            หนังสือที่ถูกยืมหมด
            <span class="ml-2 px-2 py-0.5 bg-amber-500 text-white text-xs rounded-full"><?= $outOfStockTotal ?></span>
        </h5>
        <?php // 🔴 [UAT รอบ 2 บั๊ก C] เดิมส่ง ?filter=low_stock แต่ books.php อ่าน $status
              //    ค่าจึงตกพื้น กด "ดูทั้งหมด" แล้วได้หนังสือครบทั้ง 405 เล่ม ไม่กรองอะไรเลย
              //    (borrows.php ใช้ filter= จริง — พลาดเฉพาะลิงก์นี้ที่ชี้ไปคนละหน้า) ?>
        <a href="books.php?status=out_of_stock" class="text-xs font-semibold text-amber-600 hover:text-amber-700 hover:bg-amber-100 px-3 py-1 rounded-full transition-colors">
            ดูทั้งหมด<?= $outOfStockTotal > count($lowStockBooks) ? ' (' . $outOfStockTotal . ' เล่ม)' : '' ?>
        </a>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3">
        <?php foreach ($lowStockBooks as $book): ?>
            <div class="bg-white rounded-xl p-3 border border-amber-100 hover:shadow-md transition-shadow">
                <div class="font-medium text-gray-900 text-sm line-clamp-1"><?= e($book['title']) ?></div>
                <div class="text-xs text-gray-500 line-clamp-1"><?= e($book['author']) ?></div>
                <div class="mt-2 flex justify-between items-center">
                    <span class="text-xs text-gray-400"><?= e($book['category_name'] ?? '-') ?></span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold <?= $book['available'] == 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' ?>">
                        <?= $book['available'] ?>/<?= $book['quantity'] ?>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Quick Actions -->
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-8">
    <h5 class="font-bold text-gray-800 mb-4 flex items-center">
        <i class="bi bi-lightning-charge text-yellow-500 mr-2"></i>
        การดำเนินการด่วน
    </h5>
    <div class="flex flex-wrap gap-3">
        <a href="borrow_form.php" class="inline-flex items-center px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-xl transition-colors shadow-lg shadow-primary-500/20">
            <i class="bi bi-plus-circle mr-2"></i>บันทึกการยืม
        </a>
        <a href="book_form.php" class="inline-flex items-center px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-xl transition-colors shadow-lg shadow-emerald-500/20">
            <i class="bi bi-book mr-2"></i>เพิ่มหนังสือ
        </a>
        <a href="categories.php" class="inline-flex items-center px-4 py-2 bg-cyan-600 hover:bg-cyan-700 text-white text-sm font-medium rounded-xl transition-colors shadow-lg shadow-cyan-500/20">
            <i class="bi bi-bookmark mr-2"></i>จัดการหมวดหมู่
        </a>
        <a href="members.php" class="inline-flex items-center px-4 py-2 bg-slate-600 hover:bg-slate-700 text-white text-sm font-medium rounded-xl transition-colors shadow-lg shadow-slate-500/20">
            <i class="bi bi-people mr-2"></i>ดูสมาชิก
        </a>
        <button onclick="exportDashboardPDF()" class="inline-flex items-center px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white text-sm font-medium rounded-xl transition-colors shadow-lg shadow-rose-500/20">
            <i class="bi bi-printer mr-2"></i>พิมพ์สรุป
        </button>

        <?php // 💾 [UAT รอบ 4 ข้อ 5] เดิมปุ่มสำรองข้อมูลอยู่แค่ในหน้าตั้งค่า
              //    ตอนคอมเริ่มมีอาการแปลก ๆ คนจะรีบหา "ปุ่มเซฟ" ที่หน้าแรก ไม่ใช่ไปขุดในหน้าตั้งค่า
              //    ⚠️ ขึ้นเฉพาะผู้ดูแลระบบ — backup.php เรียก requireAdmin()
              //       ถ้าโชว์ให้เจ้าหน้าที่ทั่วไปด้วย กดแล้วจะเจอหน้า "ไม่มีสิทธิ์" เฉย ๆ
              //    🧠 ต้องเป็น POST + CSRF เหมือนในหน้าตั้งค่า จะทำเป็นลิงก์ธรรมดาไม่ได้ ?>
        <?php if (isAdmin()): ?>
            <form method="POST" action="<?= APP_URL ?>/admin/backup.php" class="inline">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium rounded-xl transition-colors shadow-lg shadow-amber-500/20"
                        title="ดาวน์โหลดไฟล์สำรองข้อมูลทั้งระบบ — ควรทำเดือนละครั้งและก่อนปิดเทอม">
                    <i class="bi bi-shield-check mr-2"></i>สำรองข้อมูล
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Chart.js Initialization -->
<script src="<?= APP_URL ?>/assets/vendor/chartjs/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Determine chart background colors based on CSS variables if possible, or hardcode Tailwind colors
    const colors = {
        primary: {
            DEFAULT: '#3b82f6', // blue-500
            light: 'rgba(59, 130, 246, 0.2)', // blue-500/20
        },
        success: {
            DEFAULT: '#10b981', // emerald-500
            light: 'rgba(16, 185, 129, 0.2)',
        }
    };

    // Monthly Borrow Chart
    const borrowCtx = document.getElementById('borrowChart');
    if (borrowCtx) {
        new Chart(borrowCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [{
                    label: 'จำนวนการยืม',
                    data: <?= json_encode(array_map('intval', $chartBorrows)) ?>,
                    backgroundColor: colors.primary.DEFAULT,
                    borderRadius: 6,
                    barPercentage: 0.6,
                    categoryPercentage: 0.8
                }, {
                    label: 'คืนแล้ว',
                    data: <?= json_encode(array_map('intval', $chartReturned)) ?>,
                    backgroundColor: colors.success.DEFAULT,
                    borderRadius: 6,
                    barPercentage: 0.6,
                    categoryPercentage: 0.8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 8
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(17, 24, 39, 0.9)',
                        padding: 12,
                        cornerRadius: 8,
                        titleFont: { size: 13 },
                        bodyFont: { size: 12 }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false,
                            drawBorder: false
                        },
                        ticks: {
                            font: { size: 11 }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            borderDash: [4, 4],
                            drawBorder: false,
                            color: '#f3f4f6'
                        },
                        ticks: {
                            stepSize: 1,
                            font: { size: 11 }
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index',
                },
            }
        });
    }
    
    // Category Distribution Chart
    const categoryCtx = document.getElementById('categoryChart');
    if (categoryCtx) {
        new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($categoryLabels) ?>,
                datasets: [{
                    data: <?= json_encode(array_map('intval', $categoryData)) ?>,
                    backgroundColor: [
                        '#3b82f6', // blue
                        '#10b981', // emerald
                        '#f59e0b', // amber
                        '#ef4444', // red
                        '#8b5cf6', // violet
                        '#06b6d4'  // cyan
                    ],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 8,
                            padding: 15,
                            font: { size: 11 }
                        }
                    }
                },
                cutout: '70%',
            }
        });
    }
});

// Change chart date range
function changeChartRange(range) {
    const url = new URL(window.location.href);
    url.searchParams.set('range', range);
    window.location.href = url.toString();
}

// Export Dashboard to PDF
function exportDashboardPDF() {
    // Show loading
    const btn = event.target.closest('button');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-hourglass-split mr-2"></i>กำลังเตรียม...';   // 📝 ไม่ใช้คำว่า 'สร้าง' เพราะไม่ได้สร้างไฟล์อะไร
    btn.disabled = true;
    
    // Use browser print with PDF styling
    const printContents = `
        <html>
        <head>
            <title>รายงานภาพรวม - <?= date('Y-m-d') ?></title>
            <style>
                body { font-family: 'Sarabun', sans-serif; padding: 20px; }
                h1 { color: #1f2937; border-bottom: 2px solid #3b82f6; padding-bottom: 10px; }
                .stats { display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap; }
                .stat-card { background: #f3f4f6; padding: 15px 25px; border-radius: 8px; text-align: center; }
                .stat-value { font-size: 24px; font-weight: bold; color: #3b82f6; }
                .stat-label { font-size: 12px; color: #6b7280; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { border: 1px solid #e5e7eb; padding: 8px 12px; text-align: left; }
                th { background: #f9fafb; font-weight: 600; }
                .section { margin: 30px 0; }
                .section-title { font-size: 16px; font-weight: bold; color: #374151; margin-bottom: 10px; }
                @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
            </style>
        </head>
        <body>
            <h1>📊 รายงานภาพรวม - <?= e(APP_NAME) ?></h1>
            <p style="color:#6b7280;">วันที่พิมพ์: ${new Date().toLocaleDateString('th-TH', {year:'numeric',month:'long',day:'numeric',hour:'2-digit',minute:'2-digit'})}</p>
            
            <div class="stats">
                <div class="stat-card"><div class="stat-value"><?= number_format($totalMembers) ?></div><div class="stat-label">สมาชิกทั้งหมด</div></div>
                <div class="stat-card"><div class="stat-value"><?= number_format($totalBooks) ?></div><div class="stat-label">หนังสือทั้งหมด (เล่ม)</div></div>
                <div class="stat-card"><div class="stat-value"><?= number_format($totalTitles) ?></div><div class="stat-label">ชื่อเรื่อง</div></div>
                <div class="stat-card"><div class="stat-value"><?= number_format($availableBooks) ?></div><div class="stat-label">พร้อมให้ยืม</div></div>
                <div class="stat-card"><div class="stat-value"><?= number_format($activeBorrows) ?></div><div class="stat-label">กำลังยืม</div></div>
                <div class="stat-card"><div class="stat-value"><?= number_format($overdueBorrows) ?></div><div class="stat-label">เกินกำหนด</div></div>
            </div>
            
            <div class="stats">
                <div class="stat-card"><div class="stat-value" style="color:#10b981;"><?= number_format($totalFines) ?> ฿</div><div class="stat-label">รายได้ค่าปรับ (ชำระแล้ว)</div></div>
                <div class="stat-card"><div class="stat-value" style="color:#ef4444;"><?= number_format($unpaidFines) ?> ฿</div><div class="stat-label">ค้างชำระ</div></div>
            </div>
            
            <div class="section">
                <div class="section-title">🏆 สมาชิกยืมสูงสุด</div>
                <table>
                    <tr><th>#</th><th>ชื่อ</th><th>อีเมล</th><th>จำนวนยืม</th></tr>
                    <?php foreach ($topBorrowers as $idx => $user): ?>
                    <tr><td><?= $idx + 1 ?></td><td><?= e($user['name']) ?></td><td><?= e($user['email']) ?></td><td><?= number_format($user['borrow_count']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($topBorrowers)): ?><tr><td colspan="4" style="text-align:center;color:#9ca3af;">ไม่มีข้อมูล</td></tr><?php endif; ?>
                </table>
            </div>
            
            <div class="section">
                <div class="section-title">🔥 หนังสือยอดนิยม</div>
                <table>
                    <tr><th>#</th><th>ชื่อหนังสือ</th><th>ผู้แต่ง</th><th>ถูกยืม (ครั้ง)</th></tr>
                    <?php foreach ($popularBooks as $idx => $book): ?>
                    <tr><td><?= $idx + 1 ?></td><td><?= e($book['title']) ?></td><td><?= e($book['author']) ?></td><td><?= number_format($book['borrow_count']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($popularBooks)): ?><tr><td colspan="4" style="text-align:center;color:#9ca3af;">ไม่มีข้อมูล</td></tr><?php endif; ?>
                </table>
            </div>
            
            <?php if (!empty($overdueList)): ?>
            <div class="section">
                <div class="section-title" style="color:#dc2626;">⚠️ รายการเกินกำหนด (<?= count($overdueList) ?> รายการ)</div>
                <table>
                    <tr><th>หนังสือ</th><th>ผู้ยืม</th><th>กำหนดคืน</th><th>เกิน (วัน)</th></tr>
                    <?php foreach ($overdueList as $item): 
                        $dueDate = new DateTime($item['due_date']);
                        $today = new DateTime();
                        $overdueDays = $today->diff($dueDate)->days;
                    ?>
                    <tr>
                        <td><?= e($item['book_title']) ?></td>
                        <td><?= e($item['user_name']) ?></td>
                        <td><?= formatDate($item['due_date']) ?></td>
                        <td style="color:#dc2626;font-weight:bold;"><?= $overdueDays ?> วัน</td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($unpaidFinesList)): ?>
            <div class="section">
                <div class="section-title" style="color:#f59e0b;">💰 รายการค้างชำระค่าปรับ</div>
                <table>
                    <tr><th>ผู้ยืม</th><th>หนังสือ</th><th>คืนเมื่อ</th><th>ค่าปรับ</th></tr>
                    <?php foreach ($unpaidFinesList as $item): ?>
                    <tr>
                        <td><?= e($item['user_name']) ?></td>
                        <td><?= e($item['book_title']) ?></td>
                        <td><?= $item['return_date'] ? formatDate($item['return_date']) : '-' ?></td>
                        <td style="color:#dc2626;font-weight:bold;"><?= number_format($item['fine_amount']) ?> ฿</td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($lowStockBooks)): ?>
            <div class="section">
                <?php // แสดงแค่ 5 อันดับแรก — บอกยอดจริงไว้ด้วย ไม่งั้นใบพิมพ์จะรายงานต่ำกว่าความจริง ?>
                <div class="section-title" style="color:#f97316;">📦 หนังสือที่ถูกยืมหมด<?= $outOfStockTotal > count($lowStockBooks) ? ' (แสดง ' . count($lowStockBooks) . ' จาก ' . $outOfStockTotal . ' เล่ม)' : '' ?></div>
                <table>
                    <tr><th>ชื่อหนังสือ</th><th>ผู้แต่ง</th><th>หมวดหมู่</th><th>คงเหลือ</th></tr>
                    <?php foreach ($lowStockBooks as $book): ?>
                    <tr>
                        <td><?= e($book['title']) ?></td>
                        <td><?= e($book['author']) ?></td>
                        <td><?= e($book['category_name'] ?? '-') ?></td>
                        <td style="color:<?= $book['available'] == 0 ? '#dc2626' : '#f97316' ?>;font-weight:bold;"><?= $book['available'] ?>/<?= $book['quantity'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
            
            <div class="section">
                <div class="section-title">📊 สถิติการยืมรายเดือน (<?= $chartRange ?> เดือนล่าสุด)</div>
                <table>
                    <tr><th>เดือน</th><th>จำนวนยืม</th><th>จำนวนคืน</th></tr>
                    <?php 
                    $thaiMonths = ['Jan'=>'ม.ค.','Feb'=>'ก.พ.','Mar'=>'มี.ค.','Apr'=>'เม.ย.','May'=>'พ.ค.','Jun'=>'มิ.ย.','Jul'=>'ก.ค.','Aug'=>'ส.ค.','Sep'=>'ก.ย.','Oct'=>'ต.ค.','Nov'=>'พ.ย.','Dec'=>'ธ.ค.'];
                    foreach ($monthlyBorrows as $stat): 
                        $monthKey = $stat['month_name'] ?? '';
                        $thaiMonth = $thaiMonths[$monthKey] ?? $monthKey;
                    ?>
                    <tr>
                        <td><?= e($thaiMonth) ?> <?= substr($stat['month'] ?? '', 0, 4) ?></td>
                        <td><?= number_format($stat['total_borrows'] ?? $stat['borrow_count'] ?? 0) ?></td>
                        <td><?= number_format($stat['returned'] ?? $stat['return_count'] ?? 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($monthlyBorrows)): ?><tr><td colspan="3" style="text-align:center;color:#9ca3af;">ไม่มีข้อมูล</td></tr><?php endif; ?>
                </table>
            </div>
            
            <div class="section">
                <div class="section-title">📁 สรุปตามหมวดหมู่ (ทั้งหมด <?= count($allCategoriesStats) ?> หมวดหมู่)</div>
                <table>
                    <tr><th>หมวดหมู่</th><th>จำนวนหนังสือ</th><th>ถูกยืม (ครั้ง)</th></tr>
                    <?php foreach ($allCategoriesStats as $cat): ?>
                    <tr>
                        <td><?= e($cat['name']) ?></td>
                        <td><?= number_format($cat['book_count']) ?></td>
                        <td><?= number_format($cat['borrow_count']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($allCategoriesStats)): ?><tr><td colspan="3" style="text-align:center;color:#9ca3af;">ไม่มีข้อมูล</td></tr><?php endif; ?>
                </table>
            </div>
            
            <p style="margin-top:40px;color:#9ca3af;font-size:11px;text-align:center;">
                สร้างโดย <?= e(APP_NAME) ?> | <?= date('Y') ?>
            </p>
        </body>
        </html>
    `;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(printContents);
    printWindow.document.close();
    
    setTimeout(() => {
        printWindow.print();
        btn.innerHTML = originalText;
        btn.disabled = false;
    }, 500);
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
