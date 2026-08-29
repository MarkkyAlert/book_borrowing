<?php
/**
 * Admin: Payment History - ประวัติการชำระค่าปรับ
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้มี 2 ส่วน: (1) รายการค้างชำระ + ปุ่ม "ชำระ" (2) ประวัติการชำระ
 * - สิทธิ์: staff ขึ้นไป
 * 
 * 📂 Flow:
 * 1. POST action=pay_fine → BorrowService::payFine() → lock row + บันทึก payment
 * 2. GET → แสดงรายการค้างชำระ + ประวัติชำระ (filter: search)
 * 
 * ⚠️ ระวัง:
 * - payFine() ใช้ UNIQUE constraint บน borrow_id — ชำระซ้ำจะ error
 * - Idempotency key ป้องกัน double-submit
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/../bootstrap.php';
// 🔒 [AUTH] staff/admin เท่านั้น
requireStaff();

use App\Repositories\PaymentRepository; // ดึงประวัติการชำระ + สถิติรายได้
use App\Repositories\BorrowRepository;  // ดึงรายการค้างชำระ
use App\Services\BorrowService;         // Business logic: payFine (transaction + UNIQUE constraint)

// 📦 สร้าง service/repository instances
$pdo = getDB();
$paymentRepo = new PaymentRepository($pdo);
$borrowRepo = new BorrowRepository($pdo);
$borrowService = new BorrowService($pdo);

// ── POST: บันทึกการชำระค่าปรับ ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // [SECURITY] CSRF — ป้องกันถูกหลอกให้บันทึกการชำระโดยไม่รู้ตัว
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง กรุณาลองใหม่');
        redirectToList('payments.php', LIST_STATE_PAYMENTS);
    }
    
    if ($action === 'pay_fine') {
        $borrowId = (int) ($_POST['borrow_id'] ?? 0);
        
        // [IDEMPOTENCY] ป้องกัน double-submit ด้วย session token
        $idempotencyKey = 'pay_fine_' . $borrowId;
        if (isset($_SESSION['processed_actions'][$idempotencyKey])) {
            setFlash('info', 'รายการนี้ถูกบันทึกไปแล้ว');
            redirectToList('payments.php', LIST_STATE_PAYMENTS);
        }
        
        try {
            // [WRITE] Service จัดการ: lock row, ตรวจชำระซ้ำ (UNIQUE constraint), บันทึก payment
            $result = $borrowService->payFine($borrowId, $_SESSION['user_id']);
            
            // บันทึกว่า process แล้ว
            $_SESSION['processed_actions'][$idempotencyKey] = time();
            
            setFlash('success', $result['message']);
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }
        redirectToList('payments.php', LIST_STATE_PAYMENTS);
    }

    // 💸 ยกเว้นค่าปรับ — ไม่เก็บเงิน แต่ไม่นับเป็นค้างชำระอีก
    if ($action === 'waive_fine') {
        $borrowId = (int) ($_POST['borrow_id'] ?? 0);
        $note     = trim($_POST['waive_note'] ?? '');

        // [IDEMPOTENCY] ป้องกัน double-submit แบบเดียวกับการรับชำระ
        $idempotencyKey = 'waive_fine_' . $borrowId;
        if (isset($_SESSION['processed_actions'][$idempotencyKey])) {
            setFlash('info', 'รายการนี้ถูกบันทึกไปแล้ว');
            redirectToList('payments.php', LIST_STATE_PAYMENTS);
        }

        try {
            // [WRITE] Service จัดการ: lock row, ตรวจสิทธิ์ตามยอด, บังคับเหตุผล, กันยกเว้นซ้ำ
            //    🛡️ ส่ง role จาก session — ห้ามให้หน้าเว็บส่งมาเอง ไม่งั้นปลอมเป็น admin ได้
            $result = $borrowService->waiveFine(
                $borrowId,
                $note,
                (int) $_SESSION['user_id'],
                $_SESSION['role'] ?? 'staff'
            );

            $_SESSION['processed_actions'][$idempotencyKey] = time();
            setFlash('success', $result['message']);
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }
        redirectToList('payments.php', LIST_STATE_PAYMENTS);
    }
}

// ── GET: ดึงข้อมูลสำหรับแสดงผล ──
// 📊 Stats cards: รายได้รวม, ค้างชำระ, เดือนนี้
$totalRevenue = $paymentRepo->getTotalCollected();     // ยอดชำระแล้วทั้งหมด
$unpaidTotal = $borrowRepo->getTotalUnpaidFines();     // ยอดค้างชำระรวม
$thisMonthRevenue = $paymentRepo->getThisMonthTotal(); // ยอดเดือนนี้

$waivedTotal = $borrowRepo->sumWaivedFines();           // ยอดที่ยกเว้นไปทั้งหมด

// 💸 ประวัติการยกเว้น — ให้ผู้ดูแลตรวจย้อนหลังได้ว่าใครยกเว้นอะไรไปบ้าง
$waivedList = $borrowRepo->findWaivedFines(50);

// 🔍 ค้นหา — ใช้ร่วมกันทั้ง 2 ตารางบนหน้านี้
$search = trim($_GET['search'] ?? '');
$page = (int) ($_GET['page'] ?? 1);          // ตารางล่าง: ประวัติการรับชำระ
$unpaidPage = (int) ($_GET['upage'] ?? 1);   // ตารางบน: รายการค้างชำระ

// 🖨️ โหมดพิมพ์ — ต้องได้ครบทุกแถว ไม่ใช่แค่หน้าที่เปิดอยู่
// 🧠 ปุ่ม "พิมพ์รายงาน" เดิมพิมพ์ทั้งตารางเพราะหน้านี้ไม่เคยแบ่งหน้า
//    พอแบ่งหน้าแล้วถ้าไม่ทำอะไร ปุ่มเดิมจะพิมพ์ได้แค่ 20 แถว = ลดความสามารถแบบเงียบ ๆ
//    จึงให้ปุ่มพาไป ?print=1 ซึ่ง render ครบแล้วสั่งพิมพ์เอง
// ⚠️ ต้องนิยามก่อนดึงข้อมูลค้างชำระด้านล่าง เพราะใช้ตัดสินว่าจะ LIMIT ไหม
$printMode = isset($_GET['print']);

// ═══════════════════════════════════════════════════════════════════
// 💰 รายการค้างชำระ — แบ่งหน้าเป็น "คน" ไม่ใช่ "รายการ"
// ═══════════════════════════════════════════════════════════════════
// 🔴 เดิมดึงมาแค่ 50 แถวตายตัวแล้วเอา count() ของ 50 แถวนั้นมาขึ้นป้ายว่ามีกี่คน
//    ผลคือหน้าบอก "46 คน" ทั้งที่จริง 169 คน และคนที่ค้างมากที่สุดไม่โผล่เลย
//    ส่วนยอดเงินมาจากอีก query ที่ไม่มี LIMIT จึงถูก — ป้ายกับยอดเงินขัดกันเอง (F-35)
//
// 🧠 ทำไมแบ่งหน้าเป็น "คน": คนหนึ่งค้างได้ 7–8 ใบ ถ้าแบ่งตามแถวยืม
//    หนี้ของคนเดียวกันจะถูกหั่นข้ามหน้า บรรณารักษ์เห็นยอดไม่ครบของคนที่ยืนอยู่ตรงหน้า
$unpaidStats = $borrowRepo->countUnpaidDebtors($search);

$unpaidPagination = paginate($unpaidStats['people'], $unpaidPage, ITEMS_PER_PAGE);
$debtors = $borrowRepo->getUnpaidDebtors(
    $printMode ? max(1, $unpaidStats['people']) : $unpaidPagination['per_page'],
    $printMode ? 0 : $unpaidPagination['offset'],
    $search
);

// 📄 ดึงใบค้างชำระของคนในหน้านี้ครั้งเดียว แล้วค่อยจัดกลุ่ม (ไม่วน query ทีละคน)
$unpaidItems  = $borrowRepo->getUnpaidItemsByUsers(array_column($debtors, 'user_id'));
$unpaidByUser = [];
foreach ($debtors as $d) {
    $unpaidByUser[(int) $d['user_id']] = [
        'user_id'    => (int) $d['user_id'],
        'user_name'  => $d['user_name'],
        'user_phone' => $d['user_phone'] ?: '-',
        'total_fine' => (float) $d['total_fine'],
        'item_count' => (int) $d['item_count'],
        'items'      => [],
    ];
}
foreach ($unpaidItems as $item) {
    $uid = (int) $item['user_id'];
    if (isset($unpaidByUser[$uid])) {
        $unpaidByUser[$uid]['items'][] = $item;
    }
}

$filters = [];
if (!empty($search)) {
    $filters['search'] = $search;
}

// 📄 นับยอดรวมก่อน (ด้วย filter ชุดเดียวกัน) แล้วคำนวณว่าอยู่หน้าไหน ต้องข้ามกี่แถว
// 🧠 ตั้งชื่อให้ชัดว่าเป็นของตารางไหน — หน้านี้มี 2 ตารางที่แบ่งหน้าแยกกัน
//    ถ้าใช้ $pagination ตัวเดียวร่วมกัน ตัวที่ require ทีหลังจะได้ค่าของตัวแรกไป
$paymentsPagination = paginate($paymentRepo->countAll($filters), $page, ITEMS_PER_PAGE);

if (!$printMode) {
    $filters['limit']  = $paymentsPagination['per_page'];
    $filters['offset'] = $paymentsPagination['offset'];
}

// 📜 ดึงประวัติการชำระ (พร้อม JOIN ชื่อสมาชิก, หนังสือ, ผู้บันทึก)
$payments = $paymentRepo->findAll($filters);

// 📄 filter ที่ต้องติดไปกับลิงก์เปลี่ยนหน้า — ไม่งั้นกดหน้า 2 แล้วคำค้นหาย
$paginationParams = ['search' => $search];

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
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6 screen-only">
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

    <?php // 💸 ยกเว้นไปแล้ว — แยกจาก "รายได้" เพราะไม่ใช่เงินที่เก็บได้ ?>
    <div class="bg-gradient-to-br from-amber-500 to-orange-500 rounded-2xl p-5 text-white shadow-lg shadow-amber-500/20">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-amber-100 text-xs font-medium mb-1">ยกเว้นไปแล้ว</p>
                <h3 class="text-2xl font-bold"><?= number_format($waivedTotal) ?> ฿</h3>
            </div>
            <div class="p-2 bg-white/20 rounded-lg backdrop-blur-sm">
                <i class="bi bi-x-circle text-xl"></i>
            </div>
        </div>
    </div>
</div>

<?php // 💸 ประวัติการยกเว้น — ผู้ดูแลต้องตรวจย้อนหลังได้ว่าใครยกเว้นอะไรเพราะอะไร
      //    ระบบไม่มี audit trail กลาง (KNOWN_LIMITATIONS §4) ตารางนี้จึงทำหน้าที่แทน ?>
<?php if (!empty($waivedList)): ?>
<div class="bg-amber-50/60 rounded-2xl shadow-sm border border-amber-200 p-6 mb-6 screen-only">
    <div class="flex justify-between items-center mb-4">
        <h5 class="font-bold text-amber-800 flex items-center">
            <i class="bi bi-x-circle text-amber-500 mr-2"></i>
            ประวัติการยกเว้นค่าปรับ
            <span class="ml-2 px-2 py-0.5 bg-amber-500 text-white text-xs rounded-full"><?= count($waivedList) ?> รายการ</span>
        </h5>
        <span class="text-xs text-amber-700">รวม <?= number_format($waivedTotal) ?> ฿</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-amber-900 bg-amber-100/70">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">สมาชิก</th>
                    <th class="px-4 py-2 text-left font-medium">หนังสือ</th>
                    <th class="px-4 py-2 text-left font-medium">ยอดที่ยกเว้น</th>
                    <th class="px-4 py-2 text-left font-medium">เหตุผล</th>
                    <th class="px-4 py-2 text-left font-medium">ผู้ยกเว้น</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-amber-100">
                <?php foreach ($waivedList as $w): ?>
                    <tr class="hover:bg-amber-50 transition-colors">
                        <td class="px-4 py-3 font-medium text-gray-900"><?= e($w['user_name']) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= e($w['book_title']) ?></td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800">
                                <?= number_format($w['fine_amount']) ?> ฿
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-600"><?= e($w['fine_waived_note'] ?? '-') ?></td>
                        <td class="px-4 py-3 text-gray-500">
                            <div><?= e($w['waived_by_name'] ?? 'ไม่ทราบ') ?></div>
                            <div class="text-xs"><?= formatDate($w['fine_waived_at']) ?></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Unpaid Fines Section (Grouped by User) -->
<?php if ($unpaidStats['people'] > 0 || $search !== ''): ?>
<div class="unpaid-section bg-gradient-to-r from-red-50 to-rose-50 rounded-2xl shadow-sm border border-red-200 p-6 mb-6">
    <div class="flex flex-col lg:flex-row lg:justify-between lg:items-center gap-4 mb-4">
        <h5 class="font-bold text-red-800 flex items-center flex-wrap gap-2">
            <span class="flex items-center">
                <i class="bi bi-exclamation-triangle text-red-500 mr-2"></i>
                รายการค้างชำระ
            </span>
            <?php // 🔴 ตัวเลขทั้ง 3 ตัวมาจาก query ที่ **ไม่มี LIMIT** ห้ามนับจากแถวที่แสดง
                  //    ไม่งั้นจะกลับไปเป็นบั๊ก F-35 ที่ป้ายบอก 46 คน ทั้งที่จริง 169 คน ?>
            <span class="px-2 py-0.5 bg-red-500 text-white text-xs rounded-full"><?= number_format($unpaidStats['people']) ?> คน</span>
            <span class="px-2 py-0.5 bg-red-700 text-white text-xs rounded-full"><?= number_format($unpaidStats['rows']) ?> รายการ</span>
            <span class="px-2 py-0.5 bg-red-900 text-white text-xs rounded-full"><?= number_format($unpaidStats['total'], 2) ?> ฿</span>
        </h5>

        <?php // 🔍 ช่องค้นหาของ "ส่วนค้างชำระ" โดยเฉพาะ
              //    เดิมหน้านี้มีช่องค้นหาช่องเดียวซึ่งผูกกับตารางประวัติการรับชำระข้างล่าง
              //    พิมพ์ชื่อคนค้างหนี้แล้วส่วนนี้ไม่ขยับสักแถว ?>
        <form method="GET" class="flex gap-2 hide-on-print">
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <i class="bi bi-search text-red-300"></i>
                </div>
                <input type="text" name="search" value="<?= e($search) ?>"
                       class="block w-full sm:w-72 pl-10 h-10 border-red-200 rounded-lg focus:ring-red-500 focus:border-red-500 text-sm"
                       placeholder="ค้นชื่อ, เบอร์โทร, อีเมล หรือชื่อหนังสือ...">
            </div>
            <button type="submit" class="px-4 h-10 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition-colors">ค้นหา</button>
            <?php if ($search !== ''): ?>
                <a href="payments.php" class="px-4 h-10 inline-flex items-center bg-white border border-red-200 text-red-700 text-sm font-medium rounded-lg hover:bg-red-50 transition-colors">ล้าง</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($unpaidByUser)): ?>
        <div class="text-center py-10">
            <i class="bi bi-search text-3xl text-red-200 block mb-2"></i>
            <p class="text-red-700">ไม่พบคนค้างชำระที่ตรงกับ "<?= e($search) ?>"</p>
        </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="text-xs text-red-700 uppercase bg-red-100/50">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">สมาชิก</th>
                    <th class="px-4 py-2 text-center font-medium">จำนวนรายการ</th>
                    <th class="px-4 py-2 text-left font-medium">ยอดค้างรวม</th>
                    <th class="px-4 py-2 text-center font-medium hide-on-print">ดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-red-100">
                <?php foreach ($unpaidByUser as $userId => $userData): ?>
                    <tr class="hover:bg-red-50/50 transition-colors">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900"><?= e($userData['user_name']) ?></div>
                            <div class="text-xs text-gray-500"><?= e($userData['user_phone']) ?></div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-red-200 text-red-800">
                                <?= count($userData['items']) ?> เล่ม
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-700">
                                <?= number_format($userData['total_fine']) ?> ฿
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center hide-on-print">
                            <button type="button" onclick="openUserFinesModal(<?= $userId ?>)" 
                                    class="inline-flex items-center px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-xs font-medium rounded-lg transition-colors">
                                <i class="bi bi-eye mr-1"></i>ดูรายละเอียด
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php // 📄 แถบแบ่งหน้าของ "ส่วนค้างชำระ" — ใช้ ?upage= คนละตัวกับตารางล่างที่ใช้ ?page=
          //    ถ้าใช้ชื่อเดียวกัน กดหน้า 2 ตรงนี้ ตารางประวัติการรับชำระจะเลื่อนตามไปด้วย ?>
    <?php if (!$printMode): ?>
        <?php
        $pagination       = $unpaidPagination;
        $paginationParams = ['search' => $search, 'page' => $page];
        $paginationKey    = 'upage';
        $paginationUnit   = 'คน';
        require __DIR__ . '/../includes/pagination.php';
        ?>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- User Fines Detail Modals -->
<?php foreach ($unpaidByUser as $userId => $userData): ?>
<div id="userFinesModal<?= $userId ?>" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-modal="true">
    <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop"></div>
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="modal-panel relative transform overflow-hidden rounded-2xl bg-white text-left shadow-xl transition-all w-full max-w-2xl opacity-0 translate-y-4">
            <div class="bg-gradient-to-r from-red-500 to-rose-600 px-6 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-white flex items-center">
                            <i class="bi bi-person-circle mr-2"></i><?= e($userData['user_name']) ?>
                        </h3>
                        <p class="text-red-100 text-sm"><?= e($userData['user_phone']) ?></p>
                    </div>
                    <button type="button" class="text-white/80 hover:text-white" onclick="closeUserFinesModal(<?= $userId ?>)">
                        <i class="bi bi-x-lg text-xl"></i>
                    </button>
                </div>
            </div>
            
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <span class="text-gray-600">รายการค้างชำระทั้งหมด</span>
                    <span class="text-xl font-bold text-red-600"><?= number_format($userData['total_fine']) ?> ฿</span>
                </div>
                
                <div class="space-y-3 max-h-80 overflow-y-auto">
                    <?php foreach ($userData['items'] as $item): ?>
                    <div class="bg-gray-50 rounded-xl p-4 border border-gray-100">
                        <div class="flex justify-between items-start">
                            <div class="flex-1">
                                <div class="font-medium text-gray-900"><?= e($item['book_title']) ?></div>
                                <div class="text-xs text-gray-500 mt-1">
                                    คืนเมื่อ: <?= $item['return_date'] ? formatDate($item['return_date']) : '-' ?>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 ml-4">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-sm font-bold bg-red-100 text-red-700">
                                    <?= number_format($item['fine_amount']) ?> ฿
                                </span>
                                <button type="button" onclick="closeUserFinesModal(<?= $userId ?>); setTimeout(() => openPayModal(<?= $item['id'] ?>, '<?= e($item['user_name']) ?>', '<?= e($item['book_title']) ?>', <?= $item['fine_amount'] ?>), 350);" 
                                        class="inline-flex items-center px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white text-xs font-medium rounded-lg transition-colors">
                                    <i class="bi bi-cash mr-1"></i>รับชำระ
                                </button>
                                <?php // 💸 ยกเว้นค่าปรับ — ไม่เก็บเงิน แต่ไม่นับเป็นค้างชำระอีก ?>
                                <button type="button" onclick="closeUserFinesModal(<?= $userId ?>); setTimeout(() => openWaiveModal(<?= $item['id'] ?>, '<?= e($item['user_name']) ?>', '<?= e($item['book_title']) ?>', <?= $item['fine_amount'] ?>), 350);" 
                                        class="inline-flex items-center px-3 py-1.5 bg-white border border-amber-300 text-amber-700 hover:bg-amber-50 text-xs font-medium rounded-lg transition-colors">
                                    <i class="bi bi-x-circle mr-1"></i>ยกเว้น
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="bg-gray-50 px-6 py-4 flex justify-end">
                <button type="button" onclick="closeUserFinesModal(<?= $userId ?>)" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">
                    ปิด
                </button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Payment History Table -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <div class="p-6 border-b border-gray-200 flex justify-between items-center bg-gray-50/50">
        <h5 class="text-xl font-bold text-gray-800 flex items-center">
            <i class="bi bi-receipt-cutoff mr-3 text-green-600"></i>
            ประวัติการรับชำระเงิน
        </h5>
        
        <?php // 🖨️ พาไปหน้าที่ render ครบทุกแถวก่อน แล้วค่อยสั่งพิมพ์ (ดู $printMode ด้านบน)
        //    ใช้ <a> ไม่ใช่ <button> เพื่อให้เปิดแท็บใหม่/คัดลอกลิงก์ได้ตามปกติ
        ?>
        <a href="?<?= http_build_query(array_filter(['search' => $search, 'print' => 1])) ?>"
           class="text-sm text-gray-500 hover:text-gray-700 transition-colors hide-on-print">
            <i class="bi bi-printer mr-1"></i>พิมพ์รายงาน<?= ($paymentsPagination['total'] > $paymentsPagination['per_page'] || $unpaidPagination['total'] > $unpaidPagination['per_page']) ? ' (ทุกหน้า)' : '' ?>
        </a>
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

    <?php // 📄 แถบเลือกหน้า — ซ่อนตอนพิมพ์ (โหมดพิมพ์ render ครบทุกแถวอยู่แล้ว) ?>
    <?php if (!$printMode): ?>
        <div class="px-6 pb-6 hide-on-print">
            <?php
            // 📌 ตั้งให้ถูกตัวก่อน require เสมอ — ส่วนค้างชำระด้านบนก็ require ไฟล์นี้เหมือนกัน
            $pagination       = $paymentsPagination;
            $paginationParams = ['search' => $search, 'upage' => $unpaidPage];
            $paginationUnit   = 'รายการ';
            require __DIR__ . '/../includes/pagination.php';
            ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($printMode): ?>
    <script>
        // 🖨️ เข้ามาด้วย ?print=1 → สั่งพิมพ์ทันทีหลังหน้าโหลดเสร็จ (รูป/ฟอนต์วาดครบแล้ว)
        window.addEventListener('load', () => window.print());
    </script>
<?php endif; ?>

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

<?php // 💸 Modal ยืนยันยกเว้นค่าปรับ — บังคับกรอกเหตุผล ?>
<div id="waiveModal" class="fixed inset-0 z-50 hidden overflow-y-auto" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity opacity-0" id="waiveModalBackdrop"></div>

    <div class="flex min-h-full items-center justify-center p-4">
        <div id="waiveModalPanel" class="relative transform overflow-hidden rounded-2xl bg-white text-left shadow-xl transition-all w-full max-w-md opacity-0 translate-y-4">
            <form method="POST" id="waiveFineForm">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="waive_fine">
                <input type="hidden" name="borrow_id" id="waiveBorrowId" value="">

                <div class="bg-gradient-to-r from-amber-500 to-orange-500 px-6 py-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-bold text-white flex items-center">
                            <i class="bi bi-x-circle mr-2"></i>ยกเว้นค่าปรับ
                        </h3>
                        <button type="button" class="text-white/80 hover:text-white" onclick="closeWaiveModal()">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>

                <div class="p-6">
                    <div class="text-center mb-4">
                        <p class="text-gray-600 text-sm">ยกเว้นค่าปรับให้</p>
                        <p class="font-bold text-gray-900 text-lg mt-1" id="waiveUserName"></p>
                    </div>

                    <div class="bg-gray-50 rounded-xl p-4 space-y-2 mb-4">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">หนังสือ:</span>
                            <span class="font-medium text-gray-900 text-right max-w-[200px] line-clamp-1" id="waiveBookTitle"></span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">ยอดที่จะยกเว้น:</span>
                            <span class="font-bold text-amber-600 text-lg" id="waiveAmount"></span>
                        </div>
                    </div>

                    <label for="waiveNote" class="block text-sm font-medium text-gray-700 mb-1">
                        เหตุผล <span class="text-red-500">*</span>
                    </label>
                    <textarea id="waiveNote" name="waive_note" rows="2" required maxlength="255"
                              class="w-full rounded-xl border-gray-300 focus:border-amber-500 focus:ring-amber-500 shadow-sm text-sm"
                              placeholder="เช่น ห้องสมุดปิดกะทันหัน / สมาชิกเจ็บป่วย / ระบบบันทึกผิด"></textarea>
                    <p class="mt-1 text-xs text-gray-500">
                        บันทึกไว้ตรวจย้อนหลังได้ว่าใครยกเว้นเมื่อไหร่เพราะอะไร
                        <?php if (($_SESSION['role'] ?? '') !== 'admin'): ?>
                            · เจ้าหน้าที่ยกเว้นได้ไม่เกิน <?= number_format(FINE_WAIVE_STAFF_LIMIT) ?> บาท
                        <?php endif; ?>
                    </p>
                </div>

                <div class="bg-gray-50 px-6 py-4 flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                    <button type="button" onclick="closeWaiveModal()" class="w-full sm:w-auto px-4 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">
                        ยกเลิก
                    </button>
                    <button type="submit" class="w-full sm:w-auto px-6 py-2.5 text-sm font-medium text-white bg-amber-600 rounded-xl hover:bg-amber-700 transition-colors shadow-lg shadow-amber-500/30">
                        <i class="bi bi-check-lg mr-1"></i>ยืนยันยกเว้น
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// 💸 เปิด/ปิด modal ยกเว้น — ใช้จังหวะเดียวกับ modal รับชำระ
function openWaiveModal(borrowId, userName, bookTitle, amount) {
    document.getElementById('waiveBorrowId').value = borrowId;
    document.getElementById('waiveUserName').textContent = userName;
    document.getElementById('waiveBookTitle').textContent = bookTitle;
    document.getElementById('waiveAmount').textContent = amount.toLocaleString() + ' ฿';
    document.getElementById('waiveNote').value = '';

    const modal = document.getElementById('waiveModal');
    const backdrop = document.getElementById('waiveModalBackdrop');
    const panel = document.getElementById('waiveModalPanel');

    modal.classList.remove('hidden');
    setTimeout(() => {
        backdrop.classList.remove('opacity-0');
        panel.classList.remove('opacity-0', 'translate-y-4');
        panel.classList.add('opacity-100', 'translate-y-0');
        document.getElementById('waiveNote').focus();
    }, 10);
}

function closeWaiveModal() {
    const modal = document.getElementById('waiveModal');
    const backdrop = document.getElementById('waiveModalBackdrop');
    const panel = document.getElementById('waiveModalPanel');

    backdrop.classList.add('opacity-0');
    panel.classList.add('opacity-0', 'translate-y-4');
    panel.classList.remove('opacity-100', 'translate-y-0');
    setTimeout(() => modal.classList.add('hidden'), 200);
}

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

// User Fines Modal Functions
function openUserFinesModal(userId) {
    const modal = document.getElementById('userFinesModal' + userId);
    const backdrop = modal.querySelector('.modal-backdrop');
    const panel = modal.querySelector('.modal-panel');
    
    modal.classList.remove('hidden');
    setTimeout(() => {
        backdrop.classList.remove('opacity-0');
        panel.classList.remove('opacity-0', 'translate-y-4');
        panel.classList.add('opacity-100', 'translate-y-0');
    }, 10);
}

function closeUserFinesModal(userId) {
    const modal = document.getElementById('userFinesModal' + userId);
    const backdrop = modal.querySelector('.modal-backdrop');
    const panel = modal.querySelector('.modal-panel');
    
    backdrop.classList.add('opacity-0');
    panel.classList.remove('opacity-100', 'translate-y-0');
    panel.classList.add('opacity-0', 'translate-y-4');
    
    setTimeout(() => {
        modal.classList.add('hidden');
    }, 300);
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (!document.getElementById('payModal').classList.contains('hidden')) {
            closePayModal();
        }
        // Close any open user fines modal
        document.querySelectorAll('[id^="userFinesModal"]').forEach(modal => {
            if (!modal.classList.contains('hidden')) {
                const userId = modal.id.replace('userFinesModal', '');
                closeUserFinesModal(userId);
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
