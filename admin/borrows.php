<?php
/**
 * Borrows Management - จัดการยืม-คืน
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../app/Services/BorrowService.php';

use App\Services\BorrowService;

$pdo = getDB();
$borrowService = new BorrowService($pdo);

// Handle return book FIRST (before query)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // CSRF validation
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง กรุณาลองใหม่');
        redirect('borrows.php');
    }
    
    if ($action === 'return') {
        $borrowId = (int) ($_POST['borrow_id'] ?? 0);
        $payNow = isset($_POST['pay_now']);
        
        try {
            // ใช้ BorrowService แทน inline logic
            $result = $borrowService->returnBook($borrowId, $payNow, $_SESSION['user_id']);
            
            if ($result['fine']['amount'] > 0) {
                $flashType = $result['paid'] ? 'success' : 'warning';
            } else {
                $flashType = 'success';
            }
            setFlash($flashType, $result['message']);
            
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }
        redirect('borrows.php');
    }
}

// Get filter parameters
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$filter = $_GET['filter'] ?? '';

// Build query
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR bk.title LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($status === 'borrowing' || $status === 'returned') {
    $where[] = "b.status = ?";
    $params[] = $status;
}

if ($filter === 'overdue') {
    $where[] = "b.status = 'borrowing' AND b.due_date < CURDATE()";
} elseif ($filter === 'due_today') {
    $where[] = "b.status = 'borrowing' AND b.due_date = CURDATE()";
}

$whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get borrows
$sql = "
    SELECT b.*, u.name as user_name, u.email as user_email, u.phone as user_phone,
           bk.title as book_title, bk.author as book_author
    FROM borrows b
    JOIN users u ON b.user_id = u.id
    JOIN books bk ON b.book_id = bk.id
    $whereSQL
    ORDER BY b.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$borrows = $stmt->fetchAll();

$pageTitle = 'จัดการยืม-คืน';
require_once __DIR__ . '/header.php';
?>

<!-- Actions Bar -->
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
    <div>
        <h3 class="text-lg font-bold text-gray-800">รายการยืม-คืนหนังสือ</h3>
        <p class="text-sm text-gray-500">ทั้งหมด <?= count($borrows) ?> รายการ</p>
    </div>
    <a href="borrow_form.php" class="inline-flex items-center px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-xl transition-colors shadow-lg shadow-primary-500/30">
        <i class="bi bi-plus-circle mr-2"></i>บันทึกการยืม
    </a>
</div>

<!-- Filters -->
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
    <div class="text-sm font-bold text-gray-700 mb-4 flex items-center">
        <i class="bi bi-funnel mr-2"></i>ตัวกรอง
    </div>
    <form method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
        <div class="md:col-span-5">
            <label class="block text-xs font-medium text-gray-700 mb-1">ค้นหา</label>
            <input type="text" class="w-full border-gray-300 rounded-lg text-sm focus:ring-primary-500 focus:border-primary-500" name="search" value="<?= e($search) ?>" placeholder="ชื่อผู้ยืม, อีเมล, ชื่อหนังสือ...">
        </div>
        <div class="md:col-span-3">
            <label class="block text-xs font-medium text-gray-700 mb-1">สถานะ</label>
            <select class="w-full border-gray-300 rounded-lg text-sm focus:ring-primary-500 focus:border-primary-500" name="status">
                <option value="">ทั้งหมด</option>
                <option value="borrowing" <?= $status === 'borrowing' ? 'selected' : '' ?>>กำลังยืม</option>
                <option value="returned" <?= $status === 'returned' ? 'selected' : '' ?>>คืนแล้ว</option>
            </select>
        </div>
        <div class="md:col-span-4 flex flex-wrap gap-2">
            <button type="submit" class="bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                <i class="bi bi-search mr-1"></i>ค้นหา
            </button>
            <a href="borrows.php" class="px-3 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm font-medium transition-colors border border-gray-200">ล้าง</a>
            
            <a href="borrows.php?filter=due_today" class="px-3 py-2 rounded-lg text-sm font-medium transition-colors border <?= $filter === 'due_today' ? 'bg-amber-50 text-amber-700 border-amber-200' : 'text-gray-600 hover:bg-gray-50 border-gray-200' ?>">
                <i class="bi bi-calendar-event mr-1 text-amber-500"></i>ครบวันนี้
            </a>
            <a href="borrows.php?filter=overdue" class="px-3 py-2 rounded-lg text-sm font-medium transition-colors border <?= $filter === 'overdue' ? 'bg-red-50 text-red-700 border-red-200' : 'text-gray-600 hover:bg-gray-50 border-gray-200' ?>">
                <i class="bi bi-exclamation-triangle mr-1 text-red-500"></i>เกินกำหนด
            </a>
        </div>
    </form>
</div>

<!-- Borrows Table -->
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
        <?php if (empty($borrows)): ?>
            <div class="text-center py-12 text-gray-400">
                <i class="bi bi-inbox text-6xl mb-4 inline-block text-gray-300"></i>
                <h4 class="text-lg font-medium text-gray-600">ไม่พบรายการยืม</h4>
                <p class="text-sm">ลองปรับเปลี่ยนตัวกรองค้นหา</p>
            </div>
        <?php else: ?>
            <table class="w-full text-sm text-left">
                <thead class="text-xs text-gray-500 uppercase bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="px-6 py-4 font-medium" width="50">#</th>
                        <th class="px-6 py-4 font-medium">หนังสือ</th>
                        <th class="px-6 py-4 font-medium">ผู้ยืม</th>
                        <th class="px-6 py-4 font-medium">วันที่ยืม</th>
                        <th class="px-6 py-4 font-medium">กำหนดคืน</th>
                        <th class="px-6 py-4 font-medium">ค่าปรับ</th>
                        <th class="px-6 py-4 font-medium">สถานะ</th>
                        <th class="px-6 py-4 font-medium" width="100">การจัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($borrows as $index => $borrow): ?>
                        <?php 
                            $isOverdue = $borrow['status'] === 'borrowing' && strtotime($borrow['due_date']) < strtotime('today');
                            // Use stored fine for returned items, calculate for borrowing items
                            if ($borrow['status'] === 'returned') {
                                $fineAmount = (float)($borrow['fine_amount'] ?? 0);
                                $fine = ['days' => 0, 'amount' => $fineAmount];
                            } else {
                                $fine = calculateFine($borrow['due_date'], null);
                            }
                        ?>
                        <tr class="hover:bg-gray-50/50 transition-colors <?= $isOverdue ? 'bg-red-50/30' : '' ?>">
                            <td class="px-6 py-4 text-gray-500"><?= $index + 1 ?></td>
                            <td class="px-6 py-4">
                                <div class="font-bold text-gray-900 line-clamp-1 max-w-[180px]" title="<?= e($borrow['book_title']) ?>"><?= e($borrow['book_title']) ?></div>
                                <div class="text-xs text-gray-500 mt-0.5"><?= e($borrow['book_author']) ?></div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-gray-900"><?= e($borrow['user_name']) ?></div>
                                <div class="text-xs text-gray-500 mt-0.5"><?= e($borrow['user_phone'] ?: $borrow['user_email']) ?></div>
                            </td>
                            <td class="px-6 py-4 text-gray-600"><?= formatDate($borrow['borrow_date']) ?></td>
                            <td class="px-6 py-4 text-gray-600">
                                <?= formatDate($borrow['due_date']) ?>
                                <?php if ($isOverdue): ?>
                                    <div class="text-xs text-red-600 font-semibold mt-1 flex items-center">
                                        <i class="bi bi-clock-history mr-1"></i>
                                        เกิน <?= $fine['days'] ?> วัน
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($fine['amount'] > 0): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-red-100 text-red-700">
                                        <?= formatFine($fine['amount']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <?= getBorrowStatusLabel($borrow['status'], $borrow['due_date']) ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($borrow['status'] === 'borrowing'): ?>
                                    <button type="button" class="btn-return inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-lg text-emerald-700 bg-emerald-100 hover:bg-emerald-200 transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500"
                                            onclick="openReturnModal(this)"
                                            data-borrow-id="<?= $borrow['id'] ?>"
                                            data-book-title="<?= e($borrow['book_title']) ?>"
                                            data-user-name="<?= e($borrow['user_name']) ?>"
                                            data-fine="<?= $fine['amount'] ?>"
                                            data-overdue-days="<?= $fine['days'] ?>">
                                        <i class="bi bi-check-lg mr-1.5"></i>คืน
                                    </button>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400 font-medium italic">ดำเนินการแล้ว</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Return Confirmation Modal (Tailwind CSS) -->
<div id="returnModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <!-- Backdrop -->
    <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity opacity-0" id="modalBackdrop"></div>

    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
        <!-- Modal Panel -->
        <div class="relative transform overflow-hidden rounded-2xl bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" id="modalPanel">
            
            <div class="bg-gradient-to-r from-emerald-500 to-teal-600 px-4 py-4 sm:px-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold leading-6 text-white flex items-center" id="modal-title">
                        <i class="bi bi-check-circle-fill mr-2"></i>ยืนยันการคืนหนังสือ
                    </h3>
                    <button type="button" class="text-white/80 hover:text-white focus:outline-none" onclick="closeReturnModal()">
                        <i class="bi bi-x-lg text-lg"></i>
                    </button>
                </div>
            </div>

            <form method="POST" id="returnForm">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="return">
                <input type="hidden" name="borrow_id" id="modalBorrowId" value="">

                <div class="px-4 py-6 sm:p-6">
                    <div class="flex justify-center mb-5">
                        <div class="h-20 w-20 bg-emerald-100 rounded-full flex items-center justify-center animate-bounce-slow">
                            <i class="bi bi-book text-4xl text-emerald-600"></i>
                        </div>
                    </div>
                    
                    <div class="text-center">
                        <p class="text-sm text-gray-500 mb-1">ต้องการบันทึกการคืนหนังสือ</p>
                        <h4 class="text-xl font-bold text-gray-900 mb-2" id="modalBookTitle">Book Title</h4>
                        <div class="inline-flex items-center px-3 py-1 rounded-full bg-gray-100 text-gray-700 text-sm font-medium mb-4">
                            <i class="bi bi-person mr-1.5"></i>
                            <span id="modalUserName">User Name</span>
                        </div>

                        <div id="modalFineInfo" class="hidden mt-2">
                            <div class="bg-red-50 border border-red-100 rounded-xl p-4">
                                <div class="flex items-center justify-center text-red-700 font-bold text-lg mb-1">
                                    <i class="bi bi-cash-coin mr-2"></i>
                                    ค่าปรับ: <span id="modalFineAmount" class="mx-1.5">0</span> บาท
                                </div>
                                <p class="text-xs text-red-600 mb-3">เกินกำหนด <span id="modalOverdueDays">0</span> วัน</p>
                                
                                <div class="flex items-center justify-center bg-white rounded-lg p-2 border border-red-100 shadow-sm">
                                    <input type="checkbox" id="pay_now" name="pay_now" value="1" class="w-4 h-4 text-emerald-600 border-gray-300 rounded focus:ring-emerald-500 cursor-pointer">
                                    <label for="pay_now" class="ml-2 text-sm font-medium text-gray-700 cursor-pointer select-none">
                                        รับชำระเงินทันที
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-gray-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6 gap-2">
                    <button type="submit" class="inline-flex w-full justify-center rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 transition-all sm:w-auto shadow-emerald-500/30">
                        <i class="bi bi-check-lg mr-1.5 text-lg"></i>ยืนยันคืน
                    </button>
                    <button type="button" class="mt-3 inline-flex w-full justify-center rounded-xl bg-white px-5 py-2.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto" onclick="closeReturnModal()">
                        ยกเลิก
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>

<script>
// Modal Logic
const modal = document.getElementById('returnModal');
const backdrop = document.getElementById('modalBackdrop');
const panel = document.getElementById('modalPanel');

function openReturnModal(btn) {
    const borrowId = btn.dataset.borrowId;
    const bookTitle = btn.dataset.bookTitle;
    const userName = btn.dataset.userName;
    const fine = parseFloat(btn.dataset.fine) || 0;
    const overdueDays = parseInt(btn.dataset.overdueDays) || 0;
    
    document.getElementById('modalBorrowId').value = borrowId;
    document.getElementById('modalBookTitle').textContent = bookTitle;
    document.getElementById('modalUserName').textContent = userName;
    
    const fineInfo = document.getElementById('modalFineInfo');
    if (fine > 0) {
        document.getElementById('modalFineAmount').textContent = fine;
        document.getElementById('modalOverdueDays').textContent = overdueDays;
        fineInfo.classList.remove('hidden');
    } else {
        fineInfo.classList.add('hidden');
    }

    // Show modal with animation
    modal.classList.remove('hidden');
    // Allow browser to render hidden removal before changing opacity
    setTimeout(() => {
        backdrop.classList.remove('opacity-0');
        panel.classList.remove('opacity-0', 'translate-y-4', 'sm:translate-y-0', 'sm:scale-95');
        panel.classList.add('opacity-100', 'translate-y-0', 'sm:scale-100');
    }, 10);
}

function closeReturnModal() {
    // Reverse animation
    backdrop.classList.add('opacity-0');
    panel.classList.remove('opacity-100', 'translate-y-0', 'sm:scale-100');
    panel.classList.add('opacity-0', 'translate-y-4', 'sm:translate-y-0', 'sm:scale-95');

    setTimeout(() => {
        modal.classList.add('hidden');
    }, 300); // Wait for transition
}

// Close on backdrop click
modal.addEventListener('click', function(e) {
    if (e.target === backdrop || e.target === modal) {
        closeReturnModal();
    }
});
</script>
