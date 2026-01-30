<?php
/**
 * Admin: Payment History
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireStaff(); // Staff can view payments

$pdo = getDB();

// Calculate Total Revenue
$totalRevenue = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments")->fetchColumn();

// Search Logic
$search = trim($_GET['search'] ?? '');
$params = [];
$whereClause = "";

if (!empty($search)) {
    $whereClause = "WHERE (u.name LIKE ? OR bk.title LIKE ? OR staff.name LIKE ?)";
    $params = ["%$search%", "%$search%", "%$search%"];
}

// Fetch Payments
$sql = "
    SELECT p.*, b.borrow_date, b.return_date, 
           u.name as member_name, 
           bk.title as book_title,
           staff.name as staff_name
    FROM payments p
    JOIN borrows b ON p.borrow_id = b.id
    JOIN users u ON b.user_id = u.id
    JOIN books bk ON b.book_id = bk.id
    LEFT JOIN users staff ON p.recorded_by = staff.id
    $whereClause
    ORDER BY p.payment_date DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$pageTitle = 'ประวัติการชำระเงิน';
require_once __DIR__ . '/header.php';
?>

<!-- Stats -->
<div class="mb-6">
    <div class="bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl p-6 text-white shadow-lg shadow-green-500/20 max-w-sm">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-green-100 text-sm font-medium mb-1">รายได้ค่าปรับรวม</p>
                <h3 class="text-3xl font-bold"><?= number_format($totalRevenue) ?> ฿</h3>
            </div>
            <div class="p-3 bg-white/20 rounded-xl backdrop-blur-sm">
                <i class="bi bi-cash-coin text-2xl"></i>
            </div>
        </div>
    </div>
</div>

<!-- Payment History Table -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <div class="p-6 border-b border-gray-200 flex justify-between items-center bg-gray-50/50">
        <h5 class="text-xl font-bold text-gray-800 flex items-center">
            <i class="bi bi-receipt-cutoff mr-3 text-green-600"></i>
            ประวัติการรับชำระเงิน
        </h5>
        
        <button onclick="window.print()" class="text-sm text-gray-500 hover:text-gray-700 transition-colors">
            <i class="bi bi-printer mr-1"></i>พิมพ์รายงาน
        </button>
    </div>

    <!-- Search Box -->
    <div class="p-6 bg-gray-50 border-b border-gray-100">
        <form method="GET" class="flex flex-col sm:flex-row gap-3">
            <div class="relative flex-1">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <i class="bi bi-search text-gray-400"></i>
                </div>
                <input type="text" name="search" value="<?= e($search) ?>" class="block w-full pl-10 h-10 border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 text-sm" placeholder="ค้นหาตามชื่อสมาชิก, หนังสือ, หรือผู้รับเงิน...">
            </div>
            <button type="submit" class="h-10 px-6 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg text-sm transition-colors shadow-sm">
                ค้นหา
            </button>
            <?php if (!empty($search)): ?>
                <a href="payments.php" class="h-10 px-4 flex items-center justify-center bg-white border border-gray-300 text-gray-700 font-medium rounded-lg text-sm hover:bg-gray-50 transition-colors">
                    ล้างค่า
                </a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($payments)): ?>
        <div class="text-center py-12">
            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="bi bi-wallet2 text-3xl text-gray-400"></i>
            </div>
            <p class="text-gray-500 text-lg">ยังไม่มีรายการชำระเงิน</p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">รหัสรายการ</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">วันที่ชำระ</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">รายการ</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ผู้ชำระ</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ยอดเงิน</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ผู้บันทึก</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($payments as $pay): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                #INV-<?= str_pad($pay['id'], 5, '0', STR_PAD_LEFT) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <div class="font-medium"><?= date('d/m/Y', strtotime($pay['payment_date'])) ?></div>
                                <div class="text-xs text-gray-500"><?= date('H:i', strtotime($pay['payment_date'])) ?> น.</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900 line-clamp-1"><?= e($pay['book_title']) ?></div>
                                <div class="text-xs text-gray-500">คืนเมื่อ: <?= formatDate($pay['return_date']) ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= e($pay['member_name']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-green-100 text-green-800">
                                    +<?= number_format($pay['amount']) ?> ฿
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <div class="flex items-center">
                                    <i class="bi bi-person-circle mr-1.5 text-gray-400"></i>
                                    <?= e($pay['staff_name'] ?? 'System') ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
