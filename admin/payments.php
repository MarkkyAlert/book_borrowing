<?php
/**
 * Admin: Payment History
 */

require_once __DIR__ . '/../bootstrap.php';
requireStaff();

use App\Repositories\PaymentRepository;
use App\Repositories\BorrowRepository;
use App\Services\BorrowService;

$pdo = getDB();
$paymentRepo = new PaymentRepository($pdo);
$borrowRepo = new BorrowRepository($pdo);
$borrowService = new BorrowService($pdo);

// Handle pay fine POST action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // CSRF check
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง กรุณาลองใหม่');
        redirect('payments.php');
    }
    
    if ($action === 'pay_fine') {
        $borrowId = (int) ($_POST['borrow_id'] ?? 0);
        
        try {
            $result = $borrowService->payFine($borrowId, $_SESSION['user_id']);
            setFlash('success', $result['message']);
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }
        redirect('payments.php');
    }
}

// Calculate Stats via Repository
$totalRevenue = $paymentRepo->getTotalCollected();
$unpaidTotal = $borrowRepo->getTotalUnpaidFines();
$thisMonthRevenue = $paymentRepo->getThisMonthTotal();

// Get unpaid fines list
$unpaidList = $borrowRepo->getUnpaidFinesList(20);

// Search Logic
$search = trim($_GET['search'] ?? '');
$filters = [];
if (!empty($search)) {
    $filters['search'] = $search;
}

// Fetch Payments via Repository
$payments = $paymentRepo->findAll($filters);

$pageTitle = 'ประวัติการชำระเงิน';
require_once __DIR__ . '/header.php';
?>

<style>
@media print {
    .print-stats-summary {
        display: block !important;
        background: white !important;
        border: 1px solid #ddd !important;
        padding: 15px !important;
        margin-bottom: 20px !important;
    }
    .print-stats-summary table {
        width: 100% !important;
    }
    .print-stats-summary td {
        padding: 8px 15px !important;
        border: none !important;
    }
    .screen-only {
        display: none !important;
    }
    .unpaid-section {
        background: white !important;
        border: 1px solid #ddd !important;
    }
    .unpaid-section .bg-red-100\/50,
    .unpaid-section .bg-red-100 {
        background: #fee2e2 !important;
    }
    .unpaid-section .bg-red-500 {
        background: #dc2626 !important;
    }
    .hide-on-print {
        display: none !important;
    }
}
@media screen {
    .print-stats-summary {
        display: none !important;
    }
}
</style>

<!-- Print-only Stats Summary -->
<div class="print-stats-summary">
    <h3 style="font-size: 14px; font-weight: bold; margin-bottom: 10px;">สรุปยอด</h3>
    <table>
        <tr>
            <td><strong>รายได้รวม (ชำระแล้ว):</strong></td>
            <td style="color: green;"><?= number_format($totalRevenue) ?> ฿</td>
            <td><strong>ค้างชำระ:</strong></td>
            <td style="color: red;"><?= number_format($unpaidTotal) ?> ฿</td>
            <td><strong>เดือนนี้:</strong></td>
            <td style="color: blue;"><?= number_format($thisMonthRevenue) ?> ฿</td>
        </tr>
    </table>
</div>

<!-- Stats Cards (Screen only) -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 screen-only">
    <div class="bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl p-5 text-white shadow-lg shadow-green-500/20">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-green-100 text-xs font-medium mb-1">รายได้รวม (ชำระแล้ว)</p>
                <h3 class="text-2xl font-bold"><?= number_format($totalRevenue) ?> ฿</h3>
            </div>
            <div class="p-2 bg-white/20 rounded-lg backdrop-blur-sm">
                <i class="bi bi-cash-coin text-xl"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-gradient-to-br from-red-500 to-rose-600 rounded-2xl p-5 text-white shadow-lg shadow-red-500/20">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-red-100 text-xs font-medium mb-1">ค้างชำระ</p>
                <h3 class="text-2xl font-bold"><?= number_format($unpaidTotal) ?> ฿</h3>
            </div>
            <div class="p-2 bg-white/20 rounded-lg backdrop-blur-sm">
                <i class="bi bi-exclamation-circle text-xl"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-gradient-to-br from-blue-500 to-indigo-600 rounded-2xl p-5 text-white shadow-lg shadow-blue-500/20">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-blue-100 text-xs font-medium mb-1">เดือนนี้</p>
                <h3 class="text-2xl font-bold"><?= number_format($thisMonthRevenue) ?> ฿</h3>
            </div>
            <div class="p-2 bg-white/20 rounded-lg backdrop-blur-sm">
                <i class="bi bi-calendar-check text-xl"></i>
            </div>
        </div>
    </div>
</div>

<!-- Unpaid Fines Section -->
<?php if (!empty($unpaidList)): ?>
<div class="unpaid-section bg-gradient-to-r from-red-50 to-rose-50 rounded-2xl shadow-sm border border-red-200 p-6 mb-6">
    <div class="flex justify-between items-center mb-4">
        <h5 class="font-bold text-red-800 flex items-center">
            <i class="bi bi-exclamation-triangle text-red-500 mr-2"></i>
            รายการค้างชำระ
            <span class="ml-2 px-2 py-0.5 bg-red-500 text-white text-xs rounded-full"><?= count($unpaidList) ?></span>
        </h5>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="text-xs text-red-700 uppercase bg-red-100/50">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">สมาชิก</th>
                    <th class="px-4 py-2 text-left font-medium">หนังสือ</th>
                    <th class="px-4 py-2 text-left font-medium">คืนเมื่อ</th>
                    <th class="px-4 py-2 text-left font-medium">ยอดค้าง</th>
                    <th class="px-4 py-2 text-center font-medium hide-on-print">ดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-red-100">
                <?php foreach ($unpaidList as $item): ?>
                    <tr class="hover:bg-red-50/50 transition-colors">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900"><?= e($item['user_name']) ?></div>
                            <div class="text-xs text-gray-500"><?= e($item['user_phone'] ?? '-') ?></div>
                        </td>
                        <td class="px-4 py-3 text-gray-700 max-w-[200px]">
                            <div class="line-clamp-1"><?= e($item['book_title']) ?></div>
                        </td>
                        <td class="px-4 py-3 text-gray-600 text-xs">
                            <?= $item['return_date'] ? formatDate($item['return_date']) : '-' ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-700">
                                <?= number_format($item['fine_amount']) ?> ฿
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center hide-on-print">
                            <button type="button" onclick="openPayModal(<?= $item['id'] ?>, '<?= e($item['user_name']) ?>', '<?= e($item['book_title']) ?>', <?= $item['fine_amount'] ?>)" 
                                    class="inline-flex items-center px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white text-xs font-medium rounded-lg transition-colors">
                                <i class="bi bi-cash mr-1"></i>รับชำระ
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

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

<!-- Pay Fine Confirmation Modal -->
<div id="payModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity opacity-0" id="payModalBackdrop"></div>
    
    <div class="flex min-h-full items-center justify-center p-4">
        <div id="payModalPanel" class="relative transform overflow-hidden rounded-2xl bg-white text-left shadow-xl transition-all w-full max-w-md opacity-0 translate-y-4">
            <form method="POST" id="payFineForm">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="pay_fine">
                <input type="hidden" name="borrow_id" id="payBorrowId" value="">
                
                <div class="bg-gradient-to-r from-green-500 to-emerald-600 px-6 py-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-bold text-white flex items-center">
                            <i class="bi bi-cash-coin mr-2"></i>ยืนยันรับชำระค่าปรับ
                        </h3>
                        <button type="button" class="text-white/80 hover:text-white" onclick="closePayModal()">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
                
                <div class="p-6">
                    <div class="flex items-center justify-center mb-4">
                        <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center">
                            <i class="bi bi-receipt text-3xl text-green-600"></i>
                        </div>
                    </div>
                    
                    <div class="text-center mb-4">
                        <p class="text-gray-600 text-sm">คุณต้องการรับชำระค่าปรับจาก</p>
                        <p class="font-bold text-gray-900 text-lg mt-1" id="payUserName"></p>
                    </div>
                    
                    <div class="bg-gray-50 rounded-xl p-4 space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">หนังสือ:</span>
                            <span class="font-medium text-gray-900 text-right max-w-[200px] line-clamp-1" id="payBookTitle"></span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">ยอดค่าปรับ:</span>
                            <span class="font-bold text-green-600 text-lg" id="payAmount"></span>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-50 px-6 py-4 flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                    <button type="button" onclick="closePayModal()" class="w-full sm:w-auto px-4 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">
                        ยกเลิก
                    </button>
                    <button type="submit" class="w-full sm:w-auto px-6 py-2.5 text-sm font-medium text-white bg-green-600 rounded-xl hover:bg-green-700 transition-colors shadow-lg shadow-green-500/30">
                        <i class="bi bi-check-lg mr-1"></i>ยืนยันรับชำระ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openPayModal(borrowId, userName, bookTitle, amount) {
    document.getElementById('payBorrowId').value = borrowId;
    document.getElementById('payUserName').textContent = userName;
    document.getElementById('payBookTitle').textContent = bookTitle;
    document.getElementById('payAmount').textContent = amount.toLocaleString() + ' ฿';
    
    const modal = document.getElementById('payModal');
    const backdrop = document.getElementById('payModalBackdrop');
    const panel = document.getElementById('payModalPanel');
    
    modal.classList.remove('hidden');
    setTimeout(() => {
        backdrop.classList.remove('opacity-0');
        panel.classList.remove('opacity-0', 'translate-y-4');
        panel.classList.add('opacity-100', 'translate-y-0');
    }, 10);
}

function closePayModal() {
    const modal = document.getElementById('payModal');
    const backdrop = document.getElementById('payModalBackdrop');
    const panel = document.getElementById('payModalPanel');
    
    backdrop.classList.add('opacity-0');
    panel.classList.remove('opacity-100', 'translate-y-0');
    panel.classList.add('opacity-0', 'translate-y-4');
    
    setTimeout(() => {
        modal.classList.add('hidden');
    }, 300);
}

// Close modal on backdrop click
document.getElementById('payModalBackdrop').addEventListener('click', closePayModal);

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && !document.getElementById('payModal').classList.contains('hidden')) {
        closePayModal();
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
