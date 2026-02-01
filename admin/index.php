<?php
/**
 * Admin Dashboard
 */

require_once __DIR__ . '/../bootstrap.php';
requireStaff();

use App\Services\DashboardService;
use App\Services\ReservationService;

$pdo = getDB();
$dashboardService = new DashboardService($pdo);

// [AUTO] Expire overdue reservations on dashboard load (fallback for cron)
$reservationService = new ReservationService($pdo);
$reservationService->expireOverdueReservations();

// Get card statistics
$stats = $dashboardService->getCardStats();
$totalBooks = $stats['total_books'];
$availableBooks = $stats['available_books'];
$borrowedBooks = $stats['borrowed_books'];
$totalMembers = $stats['total_members'];
$activeBorrows = $stats['active_borrows'];
$overdueBorrows = $stats['overdue_borrows'];
$pendingReservations = $stats['pending_reservations'];

// Get lists
$recentBorrows = $dashboardService->getRecentBorrows(5);
$recentReservations = $dashboardService->getRecentReservations(5);
$overdueList = $dashboardService->getOverdueList(10);

// Chart data
$monthlyBorrows = $dashboardService->getMonthlyStats(6);
$categoryStats = $dashboardService->getCategoryStats(6);
$totalFines = $dashboardService->getTotalFinesCollected();
$unpaidFines = $dashboardService->getUnpaidFines();
$topBorrowers = $dashboardService->getTopBorrowers(5);
$popularBooks = $dashboardService->getPopularBooks(5);

// Books status for pie chart
$bookStatusData = [
    'available' => (int)$availableBooks,
    'borrowed' => (int)$borrowedBooks
];

// Prepare chart data for JavaScript
$chartLabels = array_column($monthlyBorrows, 'month_name');
$chartBorrows = array_column($monthlyBorrows, 'total_borrows');
$chartReturned = array_column($monthlyBorrows, 'returned');
$chartFines = array_column($monthlyBorrows, 'total_fines');

$categoryLabels = array_column($categoryStats, 'name');
$categoryData = array_column($categoryStats, 'book_count');

$pageTitle = 'Dashboard';
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
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">หนังสือทั้งหมด</p>
                <h3 class="text-2xl font-bold text-gray-800"><?= number_format($totalBooks) ?></h3>
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
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">รอรับของ</p>
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
    <!-- Total Fines Card -->
    <div class="lg:col-span-3">
        <div class="bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl p-6 text-white text-center h-full shadow-lg shadow-green-500/20 flex flex-col justify-center items-center">
            <div class="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center mb-4 text-3xl">
                <i class="bi bi-cash-coin"></i>
            </div>
            <h3 class="text-3xl font-bold mb-1"><?= number_format($totalFines) ?> ฿</h3>
            <p class="text-green-100 text-sm font-medium mb-4">รายได้ค่าปรับ (ชำระแล้ว)</p>
            
            <div class="bg-white/10 rounded-xl p-3 w-full backdrop-blur-sm border border-white/20">
                <div class="flex justify-between items-center text-sm">
                    <span class="text-green-50">ค้างชำระ:</span>
                    <span class="font-bold text-white"><?= number_format($unpaidFines) ?> ฿</span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Monthly Borrows Chart -->
    <div class="lg:col-span-5">
        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 h-full">
            <h6 class="font-bold text-gray-800 mb-4 flex items-center">
                <i class="bi bi-bar-chart text-primary-500 mr-2"></i>สถิติการยืม 6 เดือนล่าสุด
            </h6>
            <div class="relative h-64">
                <canvas id="borrowChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Category Distribution Chart -->
    <div class="lg:col-span-4">
        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 h-full">
            <h6 class="font-bold text-gray-800 mb-4 flex items-center">
                <i class="bi bi-pie-chart text-primary-500 mr-2"></i>หนังสือแยกตามหมวดหมู่
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
    </div>
</div>

<!-- Chart.js Initialization -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
