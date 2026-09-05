<?php
/**
 * Borrows Management - จัดการยืม-คืน
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้แสดงรายการยืมทั้งหมด + ปุ่ม "คืนหนังสือ"
 * - สิทธิ์: staff ขึ้นไป (requireStaff)
 * 
 * 📂 Flow:
 * 1. POST action=return → BorrowService::returnBook() → คำนวณค่าปรับ, คืน stock, เปลี่ยน status
 * 2. GET → แสดงรายการยืม (filter: search, status, overdue, due_today)
 * 
 * ⚠️ ระวัง:
 * - POST ต้องทำก่อน GET (PRG pattern) — ป้องกัน refresh ซ้ำ
 * - returnBook() ใช้ transaction + row lock — ห้ามเรียก DB โดยตรง
 * - Idempotency key ป้องกัน double-submit
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/../bootstrap.php';
// 🔒 [AUTH] staff/admin เท่านั้น
requireStaff();

use App\Services\BorrowService;

// 📦 สร้าง service instance — BorrowService จัดการ return, fine, stock ให้
$pdo = getDB();
$borrowService = new BorrowService($pdo);

// ── POST: คืนหนังสือ (ทำก่อน fetch data — PRG pattern) ──
// 🧠 ทำ POST ก่อน GET เพื่อให้ข้อมูลที่แสดงเป็น version ล่าสุดหลังคืน
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // [SECURITY] CSRF check ก่อนทำ state change
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง กรุณาลองใหม่');
        redirectToList('borrows.php', LIST_STATE_BORROWS);
    }
    
    // 🔄 ต่ออายุการยืม — เลื่อนกำหนดคืน ไม่แตะสต็อก
    if ($action === 'renew') {
        $borrowId = (int) ($_POST['borrow_id'] ?? 0);

        // [IDEMPOTENCY] ป้องกัน double-submit แบบเดียวกับการคืน
        $idempotencyKey = 'renew_' . $borrowId;
        if (isset($_SESSION['processed_actions'][$idempotencyKey])) {
            setFlash('info', 'รายการนี้ถูกบันทึกไปแล้ว');
            redirectToList('borrows.php', LIST_STATE_BORROWS);
        }

        try {
            // [STATE] borrowing → borrowing (due_date ใหม่)
            //    Service ตรวจ: ยังไม่เกินกำหนด · ยังไม่เต็มโควตา · ไม่มีคนจองรอ
            $result = $borrowService->renewBorrow($borrowId);
            $_SESSION['processed_actions'][$idempotencyKey] = time();
            setFlash('success', $result['message']);
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }
        redirectToList('borrows.php', LIST_STATE_BORROWS);
    }

    // 📚 แจ้งหนังสือหาย / ชำรุด — ปิดรายการยืม + ลดจำนวนในระบบ + คิดค่าชดใช้
    if ($action === 'mark_lost') {
        $borrowId = (int) ($_POST['borrow_id'] ?? 0);
        $type     = $_POST['loss_type'] ?? 'lost';
        $note     = trim($_POST['loss_note'] ?? '');

        // 💰 ราคาที่เจ้าหน้าที่กรอก — เว้นว่าง = ให้ Service ไปหยิบ books.price เอง
        //    🔴 ห้ามแปลงค่าว่างเป็น 0 ที่นี่ ไม่งั้นจะข้ามด่าน "บังคับกรอกราคา" ของ Service
        //       และกลายเป็นทำหนังสือหายแล้วไม่ต้องจ่าย
        $priceRaw = trim((string) ($_POST['loss_price'] ?? ''));
        $price    = ($priceRaw === '') ? null : (float) $priceRaw;

        // [IDEMPOTENCY] กันกดซ้ำ — สำคัญกว่าปกติเพราะ action นี้ลด quantity
        $idempotencyKey = 'mark_lost_' . $borrowId;
        if (isset($_SESSION['processed_actions'][$idempotencyKey])) {
            setFlash('info', 'รายการนี้ถูกบันทึกไปแล้ว');
            redirectToList('borrows.php', LIST_STATE_BORROWS);
        }

        try {
            // [STATE] borrowing → lost | damaged
            //    Service ตรวจ: ประเภทถูกต้อง · มีเหตุผล · รู้ราคา (ห้ามคิด 0 เงียบ ๆ)
            $result = $borrowService->markAsLost($borrowId, $type, $price, $note, $_SESSION['user_id']);
            $_SESSION['processed_actions'][$idempotencyKey] = time();
            setFlash('warning', $result['message']);
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }
        redirectToList('borrows.php', LIST_STATE_BORROWS);
    }

    // ↩️ ย้อนการแจ้งหาย/ชำรุด — หาหนังสือเจอทีหลัง
    if ($action === 'undo_lost') {
        $borrowId = (int) ($_POST['borrow_id'] ?? 0);
        $note     = trim($_POST['undo_note'] ?? '');

        // 🧠 idempotency key คนละตัวกับ mark_lost — ไม่งั้นแจ้งหายแล้วย้อนไม่ได้ในเซสชันเดียว
        $idempotencyKey = 'undo_lost_' . $borrowId;
        if (isset($_SESSION['processed_actions'][$idempotencyKey])) {
            setFlash('info', 'รายการนี้ถูกบันทึกไปแล้ว');
            redirectToList('borrows.php', LIST_STATE_BORROWS);
        }

        try {
            // [STATE] lost | damaged → returned
            $result = $borrowService->undoLost($borrowId, $note, $_SESSION['user_id']);
            $_SESSION['processed_actions'][$idempotencyKey] = time();

            // ⚠️ ถ้าจ่ายค่าชดใช้ไปแล้ว ต้องเตือนให้เด่น — ระบบไม่คืนเงินให้เอง
            setFlash($result['refundNeeded'] ? 'warning' : 'success', $result['message']);
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }
        redirectToList('borrows.php', LIST_STATE_BORROWS);
    }

    // 📞 [UAT รอบ 2 ฎ.7] จดว่าโทรตามแล้ว — วางสายแล้วต้องมีที่จด
    //    ไม่งั้นพรุ่งนี้เปิดมาไม่รู้ว่าโทรใครไปแล้ว ต้องไล่ใหม่ทั้งใบทุกวัน
    if ($action === 'record_contact') {
        $borrowId = (int) ($_POST['borrow_id'] ?? 0);
        $note     = trim($_POST['contact_note'] ?? '');

        // 🧠 ไม่ใส่ idempotency key เหมือน action อื่น — โทรซ้ำหลายรอบเป็นเรื่องปกติ
        //    (โทรวันนี้ไม่รับ พรุ่งนี้โทรใหม่) การกันซ้ำจะทำให้จดครั้งที่ 2 ไม่ได้
        //    ส่วน double-submit ไม่อันตราย เพราะเขียนทับค่าเดิมด้วยค่าเดียวกัน
        try {
            $borrowService->recordContact($borrowId, $note, $_SESSION['user_id']);
            setFlash('success', 'บันทึกว่าโทรตามแล้ว');
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }
        redirectToList('borrows.php', LIST_STATE_BORROWS);
    }

    if ($action === 'return') {
        $borrowId = (int) ($_POST['borrow_id'] ?? 0);
        $payNow = isset($_POST['pay_now']);
        
        // [IDEMPOTENCY] ป้องกัน double-submit ด้วย session token
        $idempotencyKey = 'return_' . $borrowId;
        if (isset($_SESSION['processed_actions'][$idempotencyKey])) {
            // Request นี้ถูก process ไปแล้ว - redirect โดยไม่ทำซ้ำ
            setFlash('info', 'รายการนี้ถูกบันทึกไปแล้ว');
            redirectToList('borrows.php', LIST_STATE_BORROWS);
        }
        
        try {
            // [STATE TRANSITION] borrowing → returned
            // BorrowService จัดการ: คำนวณค่าปรับ, update status, คืน stock, บันทึก payment
            $result = $borrowService->returnBook($borrowId, $payNow, $_SESSION['user_id']);
            
            // [IDEMPOTENCY] บันทึกว่า process แล้ว (หมดอายุใน 5 นาที)
            $_SESSION['processed_actions'][$idempotencyKey] = time();
            
            if ($result['fine']['amount'] > 0) {
                $flashType = $result['paid'] ? 'success' : 'warning';
            } else {
                $flashType = 'success';
            }
            setFlash($flashType, $result['message']);
            
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }
        redirectToList('borrows.php', LIST_STATE_BORROWS);
    }
}

// ── GET: ดึงรายการยืม-คืนตาม filter ──
// 📥 รับ filter จาก query string
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$filter = $_GET['filter'] ?? '';
$page = (int) ($_GET['page'] ?? 1);

require_once __DIR__ . '/../app/Repositories/BorrowRepository.php';
$borrowRepo = new \App\Repositories\BorrowRepository($pdo);

// 🔧 สร้าง filter array — เฉพาะค่าที่ valid เท่านั้น
$filters = ['search' => $search];
// 🧠 ต้องมี lost/damaged ด้วย ไม่งั้นรายการที่แจ้งหายจะกรองดูแยกไม่ได้เลย
if (in_array($status, ['borrowing', 'returned', 'lost', 'damaged'], true)) {
    $filters['status'] = $status;
}
if ($filter === 'overdue') {
    $filters['overdue'] = true;       // แสดงเฉพาะเกินกำหนด
} elseif ($filter === 'due_today') {
    $filters['due_today'] = true;     // แสดงเฉพาะครบกำหนดวันนี้
}

// 📞 [UAT รอบ 4 ข้อ 3] "ยังไม่ได้โทร" — ซ้อนกับตัวกรองด้านบนได้
//    ใช้พารามิเตอร์แยกเพราะคำถามจริงคือ "เกินกำหนด **และ** ยังไม่ได้โทร"
$uncalled = ($_GET['uncalled'] ?? '') === '1';
if ($uncalled) {
    $filters['not_contacted'] = true;
}

// 📄 นับยอดรวมก่อน (ด้วย filter ชุดเดียวกัน) แล้วคำนวณว่าอยู่หน้าไหน ต้องข้ามกี่แถว
// 🧠 ต้องนับก่อนใส่ limit/offset — ไม่งั้นจะได้ยอดแค่ในหน้านั้น
$pagination = paginate($borrowRepo->countAll($filters), $page, ITEMS_PER_PAGE);
$filters['limit']  = $pagination['per_page'];
$filters['offset'] = $pagination['offset'];

// 📊 ดึงข้อมูล "เฉพาะหน้านี้" พร้อม JOIN (book_title, user_name, ฯลฯ)
$borrows = $borrowRepo->findAll($filters);

// 🔖 หนังสือที่มีคนจองรออยู่ — ดึงทีเดียวเป็นชุด ไม่ยิง query ทีละแถว
//    ใช้ตัดสินว่าปุ่ม "ต่ออายุ" ของแถวไหนควรกดได้ (มีคนรอ = ต่อไม่ได้)
$booksWithPendingReservation = array_flip(array_map('intval',
    $pdo->query("SELECT DISTINCT book_id FROM reservations WHERE status = 'pending'")->fetchAll(PDO::FETCH_COLUMN)
));

// 📄 filter ที่ต้องติดไปกับลิงก์เปลี่ยนหน้า — ไม่งั้นกดหน้า 2 แล้วตัวกรองหาย
$paginationParams = ['search' => $search, 'status' => $status, 'filter' => $filter,
                      'uncalled' => $uncalled ? '1' : ''];

$pageTitle = 'จัดการยืม-คืน';
require_once __DIR__ . '/header.php';
?>

<!-- Actions Bar -->
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
    <div>
        <h3 class="text-lg font-bold text-gray-800">รายการยืม-คืนหนังสือ</h3>
        <p class="text-sm text-gray-500">ทั้งหมด <?= number_format($pagination['total']) ?> รายการ</p>
    </div>
    <a href="<?= e(listStateLink('borrow_form.php', LIST_STATE_BORROWS)) ?>" class="inline-flex items-center px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-xl transition-colors shadow-lg shadow-primary-500/30">
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
                <option value="lost" <?= $status === 'lost' ? 'selected' : '' ?>>แจ้งหาย</option>
                <option value="damaged" <?= $status === 'damaged' ? 'selected' : '' ?>>ชำรุด</option>
            </select>
        </div>
        <div class="md:col-span-4 flex flex-wrap gap-2">
            <?php // 🧠 ตัวกรองที่ไม่ได้อยู่ในฟอร์ม ต้องพกไปด้วยตอนกดค้นหา ไม่งั้นกดแล้วหลุด ?>
            <?php if ($uncalled): ?><input type="hidden" name="uncalled" value="1"><?php endif; ?>
            <?php if ($filter !== ''): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
            <button type="submit" class="bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                <i class="bi bi-search mr-1"></i>ค้นหา
            </button>
            <a href="borrows.php" class="px-3 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm font-medium transition-colors border border-gray-200">ล้าง</a>
            
            <a href="borrows.php?filter=due_today" class="px-3 py-2 rounded-lg text-sm font-medium transition-colors border <?= $filter === 'due_today' ? 'bg-amber-50 text-amber-700 border-amber-200' : 'text-gray-600 hover:bg-gray-50 border-gray-200' ?>">
                <i class="bi bi-calendar-event mr-1 text-amber-500"></i>ครบวันนี้
            </a>
            <a href="borrows.php?filter=overdue<?= $uncalled ? '&uncalled=1' : '' ?>" class="px-3 py-2 rounded-lg text-sm font-medium transition-colors border <?= $filter === 'overdue' ? 'bg-red-50 text-red-700 border-red-200' : 'text-gray-600 hover:bg-gray-50 border-gray-200' ?>">
                <i class="bi bi-exclamation-triangle mr-1 text-red-500"></i>เกินกำหนด
            </a>

            <?php // 📞 [UAT รอบ 4 ข้อ 3] สลับเปิด/ปิดได้ และ **คงตัวกรองเดิมไว้**
                  //    กดจากหน้า "เกินกำหนด" ต้องได้ "เกินกำหนด + ยังไม่ได้โทร"
                  //    ไม่ใช่กระโดดกลับไปดูทั้งหมด ซึ่งจะทำให้ต้องกดใหม่ทุกครั้ง ?>
            <a href="borrows.php?<?= http_build_query(array_filter([
                    'search' => $search,
                    'status' => $status,
                    'filter' => $filter,
                    'uncalled' => $uncalled ? null : '1',
               ])) ?>"
               class="px-3 py-2 rounded-lg text-sm font-medium transition-colors border <?= $uncalled ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : 'text-gray-600 hover:bg-gray-50 border-gray-200' ?>">
                <i class="bi bi-telephone-x mr-1 text-indigo-500"></i>ยังไม่ได้โทร<?= $uncalled ? ' ✓' : '' ?>
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
            <table class="w-full text-sm text-left sticky-action">
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
                            // 🧮 ตรวจสอบว่าเกินกำหนดหรือไม่
                            $isOverdue = $borrow['status'] === 'borrowing' && strtotime($borrow['due_date']) < strtotime('today');
                            // 💰 คำนวณค่าปรับ:
                            //   - returned → ใช้ค่าที่บันทึกไว้ใน DB (fine_amount)
                            //   - borrowing → คำนวณแบบ real-time จาก due_date ถึงวันนี้
                            if ($borrow['status'] === 'returned') {
                                $fineAmount = (float)($borrow['fine_amount'] ?? 0);
                                $fine = ['days' => 0, 'amount' => $fineAmount];
                            } else {
                                $fine = $borrowService->calculateFine($borrow['due_date'], null);
                            }
                        ?>
                        <tr class="hover:bg-gray-50/50 transition-colors <?= $isOverdue ? 'bg-red-50/30' : '' ?>">
                            <td class="px-6 py-4 text-gray-500"><?= $pagination['offset'] + $index + 1 ?></td>
                            <td class="px-6 py-4">
                                <div class="font-bold text-gray-900 line-clamp-1 max-w-[180px]" title="<?= e($borrow['book_title']) ?>"><?= e($borrow['book_title']) ?></div>
                                <div class="text-xs text-gray-500 mt-0.5"><?= e($borrow['book_author']) ?></div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-gray-900"><?= e($borrow['user_name']) ?></div>
                                <div class="text-xs text-gray-500 mt-0.5"><?= e($borrow['user_phone'] ?: $borrow['user_email']) ?></div>
                                <?php // 📞 ร่องรอยการโทรตาม — วางไว้ใต้เบอร์ เพราะเป็นเรื่องเดียวกัน ?>
                                <?php if (!empty($borrow['contacted_at'])): ?>
                                    <div class="text-xs text-indigo-600 mt-1 flex items-start" title="<?= e($borrow['contact_note'] ?? '') ?>">
                                        <i class="bi bi-telephone-outbound mr-1 mt-0.5"></i>
                                        <span>
                                            โทรแล้ว <?= formatDate(substr($borrow['contacted_at'], 0, 10)) ?>
                                            <?php if (!empty($borrow['contact_note'])): ?>
                                                <span class="text-gray-500">· <?= e($borrow['contact_note']) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
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
                                    <?php
                                        // 🔄 ต่ออายุได้ไหม — เช็ค 3 เงื่อนไขเดียวกับที่ Service ตรวจ
                                        //    ถ้าไม่ได้ ยังแสดงปุ่มแต่กดไม่ได้ + บอกเหตุผลบน tooltip
                                        //    (แบบเดียวกับปุ่มลบหนังสือ — ผู้ใช้ต้องรู้ว่าทำไมกดไม่ได้)
                                        $renewCount  = (int) ($borrow['renew_count'] ?? 0);
                                        $hasReserver = isset($booksWithPendingReservation[(int) $borrow['book_id']]);
                                        $renewBlock  = null;
                                        if (MAX_RENEW_COUNT < 1)          $renewBlock = 'ระบบปิดการต่ออายุไว้';
                                        elseif ($isOverdue)               $renewBlock = 'เลยกำหนดคืนแล้ว ต่ออายุไม่ได้ — ต้องคืนก่อนแล้วยืมใหม่';
                                        elseif ($renewCount >= MAX_RENEW_COUNT) $renewBlock = 'ต่ออายุครบแล้ว (' . MAX_RENEW_COUNT . ' ครั้ง)';
                                        elseif ($hasReserver)             $renewBlock = 'มีสมาชิกจองหนังสือเล่มนี้รออยู่';
                                    ?>
                                    <?php if ($renewBlock === null): ?>
                                        <button type="button" class="btn-renew inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-lg text-blue-700 bg-blue-100 hover:bg-blue-200 transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                                                onclick="openRenewModal(this)"
                                                data-borrow-id="<?= $borrow['id'] ?>"
                                                data-book-title="<?= e($borrow['book_title']) ?>"
                                                data-user-name="<?= e($borrow['user_name']) ?>"
                                                data-due-date="<?= formatDate($borrow['due_date']) ?>"
                                                data-new-due="<?= formatDate(date('Y-m-d', strtotime($borrow['due_date'] . ' +' . DEFAULT_BORROW_DAYS . ' days'))) ?>">
                                            <i class="bi bi-arrow-clockwise mr-1.5"></i>ต่ออายุ
                                        </button>
                                    <?php else: ?>
                                        <button type="button" disabled title="<?= e($renewBlock) ?>"
                                                class="inline-flex items-center px-3 py-1.5 border border-gray-200 text-xs font-medium rounded-lg text-gray-300 bg-gray-50 cursor-not-allowed">
                                            <i class="bi bi-arrow-clockwise mr-1.5"></i>ต่ออายุ
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn-return inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-lg text-emerald-700 bg-emerald-100 hover:bg-emerald-200 transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500"
                                            onclick="openReturnModal(this)"
                                            data-borrow-id="<?= $borrow['id'] ?>"
                                            data-book-title="<?= e($borrow['book_title']) ?>"
                                            data-user-name="<?= e($borrow['user_name']) ?>"
                                            data-fine="<?= $fine['amount'] ?>"
                                            data-overdue-days="<?= $fine['days'] ?>">
                                        <i class="bi bi-check-lg mr-1.5"></i>คืน
                                    </button>
                                    <?php // 📚 แจ้งหาย/ชำรุด — ลดจำนวนหนังสือในระบบ จึงแยกสีให้เห็นว่าไม่ใช่การคืนปกติ ?>
                                    <button type="button" class="btn-lost inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-lg text-orange-700 bg-orange-100 hover:bg-orange-200 transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500"
                                            onclick="openLostModal(this)"
                                            data-borrow-id="<?= $borrow['id'] ?>"
                                            data-book-title="<?= e($borrow['book_title']) ?>"
                                            data-user-name="<?= e($borrow['user_name']) ?>"
                                            data-price="<?= $borrow['book_price'] !== null ? (float) $borrow['book_price'] : '' ?>">
                                        <i class="bi bi-exclamation-triangle mr-1.5"></i>หาย/ชำรุด
                                    </button>
                                    <?php
                                        // 📞 ขึ้นปุ่มเฉพาะแถวที่มีเหตุให้โทร — เกินกำหนดแล้ว หรือใกล้ครบกำหนด
                                        //    เล่มที่เพิ่งยืมไปไม่ต้องโทร ปุ่มจะรกช่องการจัดการเปล่า ๆ
                                        $daysLeft   = (int) floor((strtotime($borrow['due_date']) - strtotime('today')) / 86400);
                                        $worthCalling = $isOverdue || $daysLeft <= DUE_SOON_DAYS;
                                    ?>
                                    <?php if ($worthCalling): ?>
                                        <button type="button" class="btn-contact inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-lg text-indigo-700 bg-indigo-100 hover:bg-indigo-200 transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                                onclick="openContactModal(this)"
                                                data-borrow-id="<?= $borrow['id'] ?>"
                                                data-book-title="<?= e($borrow['book_title']) ?>"
                                                data-user-name="<?= e($borrow['user_name']) ?>"
                                                data-user-phone="<?= e($borrow['user_phone'] ?? '') ?>"
                                                data-contacted="<?= !empty($borrow['contacted_at']) ? e(formatDate(substr($borrow['contacted_at'], 0, 10))) : '' ?>"
                                                data-note="<?= e($borrow['contact_note'] ?? '') ?>">
                                            <i class="bi bi-telephone mr-1.5"></i>จดว่าโทรแล้ว
                                        </button>
                                    <?php endif; ?>
                                <?php elseif (in_array($borrow['status'], ['lost', 'damaged'], true)): ?>
                                    <?php // ↩️ หาหนังสือเจอทีหลังเป็นเรื่องปกติ — ต้องย้อนได้ แต่ต้องเหลือร่องรอย ?>
                                    <button type="button" class="btn-undo-lost inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-lg text-slate-700 bg-slate-100 hover:bg-slate-200 transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-slate-500"
                                            onclick="openUndoLostModal(this)"
                                            data-borrow-id="<?= $borrow['id'] ?>"
                                            data-book-title="<?= e($borrow['book_title']) ?>"
                                            data-user-name="<?= e($borrow['user_name']) ?>"
                                            data-charge="<?= number_format((float) $borrow['fine_amount'], 2) ?>">
                                        <i class="bi bi-arrow-counterclockwise mr-1.5"></i>ย้อนการแจ้ง
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

<?php // 📄 แถบเลือกหน้า (ไม่แสดงถ้ามีหน้าเดียว) ?>
<?php require __DIR__ . '/../includes/pagination.php'; ?>

<?php // 🔄 Modal ยืนยันต่ออายุ — บอกให้ชัดว่ากำหนดคืนจะเปลี่ยนจากวันไหนเป็นวันไหน ?>
<div id="renewModal" class="fixed inset-0 z-50 hidden overflow-y-auto" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity opacity-0" id="renewBackdrop"></div>

    <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0">
        <div class="relative w-full max-w-md transform overflow-hidden rounded-2xl bg-white text-left shadow-xl transition-all sm:my-8 opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" id="renewPanel">

            <div class="bg-gradient-to-r from-blue-500 to-indigo-600 px-4 py-4 sm:px-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold leading-6 text-white flex items-center">
                        <i class="bi bi-arrow-clockwise mr-2"></i>ยืนยันการต่ออายุ
                    </h3>
                    <button type="button" class="text-white/80 hover:text-white focus:outline-none" onclick="closeRenewModal()">
                        <i class="bi bi-x-lg text-lg"></i>
                    </button>
                </div>
            </div>

            <form method="POST" id="renewForm">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="renew">
                <input type="hidden" name="borrow_id" id="renewBorrowId" value="">

                <div class="px-6 py-5">
                    <div class="flex items-center justify-center mb-4">
                        <div class="w-16 h-16 rounded-full bg-blue-100 flex items-center justify-center">
                            <i class="bi bi-calendar-plus text-3xl text-blue-600"></i>
                        </div>
                    </div>

                    <p class="text-center text-gray-600 text-sm">ต่ออายุการยืมหนังสือ</p>
                    <p class="text-center font-bold text-gray-900 mt-1" id="renewBookTitle"></p>
                    <p class="text-center text-sm text-gray-500 mt-0.5">
                        <i class="bi bi-person mr-1"></i><span id="renewUserName"></span>
                    </p>

                    <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 mt-4">
                        <div class="flex items-center justify-between text-sm">
                            <div class="text-center flex-1">
                                <div class="text-xs text-gray-500 mb-0.5">กำหนดคืนเดิม</div>
                                <div class="font-medium text-gray-700" id="renewOldDue"></div>
                            </div>
                            <i class="bi bi-arrow-right text-blue-400 mx-2"></i>
                            <div class="text-center flex-1">
                                <div class="text-xs text-gray-500 mb-0.5">กำหนดคืนใหม่</div>
                                <div class="font-bold text-blue-700" id="renewNewDue"></div>
                            </div>
                        </div>
                    </div>

                    <p class="mt-3 text-xs text-gray-500 text-center">
                        ต่อได้ <?= MAX_RENEW_COUNT ?> ครั้ง · นับเพิ่มอีก <?= DEFAULT_BORROW_DAYS ?> วันจากกำหนดเดิม
                    </p>
                </div>

                <div class="bg-gray-50 px-6 py-4 flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                    <button type="button" onclick="closeRenewModal()" class="w-full sm:w-auto px-4 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">
                        ยกเลิก
                    </button>
                    <button type="submit" class="w-full sm:w-auto px-6 py-2.5 text-sm font-medium text-white bg-blue-600 rounded-xl hover:bg-blue-700 transition-colors shadow-lg shadow-blue-500/30">
                        <i class="bi bi-check-lg mr-1"></i>ยืนยันต่ออายุ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// 🔄 เปิด/ปิด modal ต่ออายุ — จังหวะเดียวกับ modal คืนหนังสือ
function openRenewModal(btn) {
    document.getElementById('renewBorrowId').value = btn.dataset.borrowId;
    document.getElementById('renewBookTitle').textContent = btn.dataset.bookTitle;
    document.getElementById('renewUserName').textContent = btn.dataset.userName;
    document.getElementById('renewOldDue').textContent = btn.dataset.dueDate;
    document.getElementById('renewNewDue').textContent = btn.dataset.newDue;

    const modal = document.getElementById('renewModal');
    const backdrop = document.getElementById('renewBackdrop');
    const panel = document.getElementById('renewPanel');
    modal.classList.remove('hidden');
    setTimeout(() => {
        backdrop.classList.remove('opacity-0');
        panel.classList.remove('opacity-0', 'translate-y-4', 'sm:scale-95');
    }, 10);
}

function closeRenewModal() {
    const modal = document.getElementById('renewModal');
    const backdrop = document.getElementById('renewBackdrop');
    const panel = document.getElementById('renewPanel');
    backdrop.classList.add('opacity-0');
    panel.classList.add('opacity-0', 'translate-y-4', 'sm:scale-95');
    setTimeout(() => modal.classList.add('hidden'), 200);
}
</script>

<?php // 📚 Modal แจ้งหาย/ชำรุด — ต้องบอกให้ชัดว่าคิดเงินเท่าไร กับใคร และสต็อกจะลด ?>
<div id="lostModal" class="fixed inset-0 z-50 hidden overflow-y-auto" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity opacity-0" id="lostBackdrop"></div>

    <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0">
        <div class="relative w-full max-w-md transform overflow-hidden rounded-2xl bg-white text-left shadow-xl transition-all sm:my-8 opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" id="lostPanel">

            <div class="bg-gradient-to-r from-orange-500 to-amber-600 px-4 py-4 sm:px-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold leading-6 text-white flex items-center">
                        <i class="bi bi-exclamation-triangle mr-2"></i>แจ้งหนังสือหาย / ชำรุด
                    </h3>
                    <button type="button" class="text-white/80 hover:text-white focus:outline-none" onclick="closeLostModal()">
                        <i class="bi bi-x-lg text-lg"></i>
                    </button>
                </div>
            </div>

            <form method="POST" id="lostForm">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="mark_lost">
                <input type="hidden" name="borrow_id" id="lostBorrowId" value="">

                <div class="px-6 py-5 space-y-4">
                    <div>
                        <p class="text-center text-gray-600 text-sm">กำลังแจ้งหนังสือ</p>
                        <p class="text-center font-bold text-gray-900 mt-1" id="lostBookTitle"></p>
                        <p class="text-center text-sm text-gray-500 mt-0.5">
                            <i class="bi bi-person mr-1"></i>ผู้ยืม: <span id="lostUserName"></span>
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">เกิดอะไรขึ้น <span class="text-red-500">*</span></label>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="flex items-center justify-center gap-2 px-3 py-2.5 border border-gray-300 rounded-xl cursor-pointer text-sm has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50 has-[:checked]:text-orange-700 has-[:checked]:font-medium transition-colors">
                                <input type="radio" name="loss_type" value="lost" checked class="text-orange-600 focus:ring-orange-500">
                                หาย
                            </label>
                            <label class="flex items-center justify-center gap-2 px-3 py-2.5 border border-gray-300 rounded-xl cursor-pointer text-sm has-[:checked]:border-purple-500 has-[:checked]:bg-purple-50 has-[:checked]:text-purple-700 has-[:checked]:font-medium transition-colors">
                                <input type="radio" name="loss_type" value="damaged" class="text-purple-600 focus:ring-purple-500">
                                ชำรุดจนใช้ไม่ได้
                            </label>
                        </div>
                        <p class="mt-1.5 text-xs text-gray-500">ชำรุดแต่ยังอ่านได้ ให้กดคืนตามปกติแล้วบันทึกไว้ในหมายเหตุแทน</p>
                    </div>

                    <div>
                        <label for="lostPrice" class="block text-sm font-medium text-gray-700 mb-1.5">
                            ราคาหนังสือ <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <input type="number" name="loss_price" id="lostPrice" step="0.01" min="0" required
                                   class="w-full rounded-xl border-gray-300 focus:border-orange-500 focus:ring-orange-500 shadow-sm pr-12"
                                   placeholder="กรอกราคาหนังสือ">
                            <span class="absolute inset-y-0 right-0 flex items-center pr-4 text-sm text-gray-400 pointer-events-none">บาท</span>
                        </div>
                        <p class="mt-1.5 text-xs text-gray-500" id="lostPriceHint"></p>
                    </div>

                    <div>
                        <label for="lostNote" class="block text-sm font-medium text-gray-700 mb-1.5">
                            รายละเอียด <span class="text-red-500">*</span>
                        </label>
                        <textarea name="loss_note" id="lostNote" rows="2" required maxlength="255"
                                  class="w-full rounded-xl border-gray-300 focus:border-orange-500 focus:ring-orange-500 shadow-sm text-sm"
                                  placeholder="เช่น ผู้ยืมแจ้งว่าทำหายระหว่างเดินทาง"></textarea>
                        <p class="mt-1 text-xs text-gray-500">เป็นเรื่องเงิน ต้องบันทึกไว้ว่าทำไมถึงคิดเงิน</p>
                    </div>

                    <div class="bg-orange-50 border border-orange-200 rounded-xl p-3.5 text-sm">
                        <div class="flex justify-between text-gray-700">
                            <span>ค่าชดใช้ที่จะเรียกเก็บ</span>
                            <span class="font-bold text-orange-700 text-base" id="lostCharge">-</span>
                        </div>
                        <?php if (LOST_BOOK_FEE > 0): ?>
                            <p class="mt-1 text-xs text-gray-500">ราคาหนังสือ + ค่าดำเนินการ <?= number_format((float) LOST_BOOK_FEE, 2) ?> บาท</p>
                        <?php endif; ?>
                        <ul class="mt-2 space-y-0.5 text-xs text-gray-600 list-disc list-inside">
                            <li>จำนวนหนังสือในระบบจะลดลง 1 เล่ม</li>
                            <li>ไม่คิดค่าปรับเกินกำหนดซ้ำ — ค่าชดใช้แทนที่ค่าปรับ</li>
                            <li>ถ้าหาเจอทีหลัง ย้อนได้จากปุ่มในแถวเดียวกัน</li>
                        </ul>
                    </div>
                </div>

                <div class="bg-gray-50 px-6 py-4 flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                    <button type="button" onclick="closeLostModal()" class="w-full sm:w-auto px-4 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">
                        ยกเลิก
                    </button>
                    <button type="submit" class="w-full sm:w-auto px-6 py-2.5 text-sm font-medium text-white bg-orange-600 rounded-xl hover:bg-orange-700 transition-colors shadow-lg shadow-orange-500/30">
                        <i class="bi bi-check-lg mr-1"></i>ยืนยันการแจ้ง
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php // 📞 Modal จดว่าโทรแล้ว — กดโทรจากในนี้ได้เลย แล้วจดผลทันทีตอนวางสาย ?>
<div id="contactModal" class="fixed inset-0 z-50 hidden overflow-y-auto" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity opacity-0" id="contactBackdrop"></div>

    <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0">
        <div class="relative w-full max-w-md transform overflow-hidden rounded-2xl bg-white text-left shadow-xl transition-all sm:my-8 opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" id="contactPanel">

            <div class="bg-gradient-to-r from-indigo-600 to-indigo-700 px-4 py-4 sm:px-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold leading-6 text-white flex items-center">
                        <i class="bi bi-telephone mr-2"></i>จดว่าโทรตามแล้ว
                    </h3>
                    <button type="button" class="text-white/80 hover:text-white focus:outline-none" onclick="closeContactModal()">
                        <i class="bi bi-x-lg text-lg"></i>
                    </button>
                </div>
            </div>

            <form method="POST" id="contactForm">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="record_contact">
                <input type="hidden" name="borrow_id" id="contactBorrowId" value="">

                <div class="px-6 py-5 space-y-4">
                    <div class="text-center">
                        <p class="font-bold text-gray-900" id="contactBookTitle"></p>
                        <p class="text-sm text-gray-500 mt-0.5">
                            <i class="bi bi-person mr-1"></i>ผู้ยืม: <span id="contactUserName"></span>
                        </p>
                        <?php // ☎️ กดเบอร์ในนี้โทรออกได้เลย — ไม่ต้องสลับไปหน้าอื่นหาเบอร์ ?>
                        <a href="#" id="contactPhoneLink" class="inline-flex items-center mt-2.5 px-4 py-2 rounded-xl bg-indigo-50 text-indigo-700 font-bold hover:bg-indigo-100 transition-colors">
                            <i class="bi bi-telephone-outbound mr-2"></i><span id="contactPhoneText"></span>
                        </a>
                        <p id="contactNoPhone" class="hidden mt-2.5 text-sm text-amber-700">
                            <i class="bi bi-exclamation-circle mr-1"></i>สมาชิกคนนี้ไม่ได้ให้เบอร์โทรไว้
                        </p>
                    </div>

                    <?php // 🕘 เคยโทรไปแล้วต้องเห็นก่อนโทรซ้ำ ไม่งั้นโทรทวนซ้ำคนเดิม ?>
                    <div id="contactPrev" class="hidden bg-indigo-50 border border-indigo-200 rounded-xl p-3 text-xs text-indigo-800">
                        <i class="bi bi-clock-history mr-1"></i>โทรครั้งล่าสุด <span class="font-bold" id="contactPrevDate"></span><span id="contactPrevNote"></span>
                    </div>

                    <div>
                        <label for="contactNote" class="block text-sm font-medium text-gray-700 mb-1.5">ผลการโทร</label>
                        <?php // ⚡ ปุ่มลัด — ผลการโทรวนอยู่ไม่กี่แบบ พิมพ์เองทุกครั้งเสียเวลา ?>
                        <div class="flex flex-wrap gap-1.5 mb-2">
                            <?php foreach (['รับสาย จะมาคืน', 'ไม่รับสาย', 'เบอร์ติดต่อไม่ได้', 'ฝากข้อความไว้'] as $preset): ?>
                                <button type="button" onclick="setContactNote(this)" data-note="<?= e($preset) ?>"
                                        class="px-2.5 py-1 text-xs rounded-lg border border-gray-300 text-gray-600 hover:bg-indigo-50 hover:border-indigo-300 hover:text-indigo-700 transition-colors">
                                    <?= e($preset) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <textarea name="contact_note" id="contactNote" rows="2" maxlength="255"
                                  class="w-full rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 shadow-sm text-sm"
                                  placeholder="เช่น รับสาย บอกว่าพรุ่งนี้จะเอามาคืน"></textarea>
                        <p class="mt-1 text-xs text-gray-500">ไม่กรอกก็ได้ — ระบบจดวันที่โทรให้อยู่แล้ว · จดทับครั้งก่อน ไม่เก็บทุกสาย</p>
                    </div>
                </div>

                <div class="bg-gray-50 px-6 py-4 flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                    <button type="button" onclick="closeContactModal()" class="w-full sm:w-auto px-4 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">
                        ยกเลิก
                    </button>
                    <button type="submit" class="w-full sm:w-auto px-6 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-xl hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-500/30">
                        <i class="bi bi-check-lg mr-1"></i>บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php // ↩️ Modal ย้อนการแจ้ง — บอกให้ชัดว่าเงินที่จ่ายไปแล้วระบบไม่คืนให้เอง ?>
<div id="undoLostModal" class="fixed inset-0 z-50 hidden overflow-y-auto" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity opacity-0" id="undoLostBackdrop"></div>

    <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0">
        <div class="relative w-full max-w-md transform overflow-hidden rounded-2xl bg-white text-left shadow-xl transition-all sm:my-8 opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" id="undoLostPanel">

            <div class="bg-gradient-to-r from-slate-600 to-slate-700 px-4 py-4 sm:px-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold leading-6 text-white flex items-center">
                        <i class="bi bi-arrow-counterclockwise mr-2"></i>ย้อนการแจ้งหาย/ชำรุด
                    </h3>
                    <button type="button" class="text-white/80 hover:text-white focus:outline-none" onclick="closeUndoLostModal()">
                        <i class="bi bi-x-lg text-lg"></i>
                    </button>
                </div>
            </div>

            <form method="POST" id="undoLostForm">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="undo_lost">
                <input type="hidden" name="borrow_id" id="undoLostBorrowId" value="">

                <div class="px-6 py-5 space-y-4">
                    <div>
                        <p class="text-center text-gray-600 text-sm">หาหนังสือเจอแล้วใช่ไหม</p>
                        <p class="text-center font-bold text-gray-900 mt-1" id="undoLostBookTitle"></p>
                        <p class="text-center text-sm text-gray-500 mt-0.5">
                            <i class="bi bi-person mr-1"></i>ผู้ยืม: <span id="undoLostUserName"></span>
                        </p>
                    </div>

                    <div>
                        <label for="undoNote" class="block text-sm font-medium text-gray-700 mb-1.5">
                            เหตุผลที่ย้อน <span class="text-red-500">*</span>
                        </label>
                        <textarea name="undo_note" id="undoNote" rows="2" required maxlength="200"
                                  class="w-full rounded-xl border-gray-300 focus:border-slate-500 focus:ring-slate-500 shadow-sm text-sm"
                                  placeholder="เช่น ผู้ยืมนำหนังสือมาคืนแล้ว"></textarea>
                        <p class="mt-1 text-xs text-gray-500">เก็บต่อท้ายบันทึกเดิม ไม่ลบร่องรอยการแจ้งทิ้ง</p>
                    </div>

                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-3.5 text-xs text-gray-600">
                        <ul class="space-y-0.5 list-disc list-inside">
                            <li>หนังสือกลับเข้าระบบ 1 เล่ม</li>
                            <li>ค่าชดใช้ <span class="font-medium" id="undoLostCharge"></span> บาทที่<span class="font-medium">ยังไม่ได้จ่าย</span> จะถูกยกเลิก</li>
                            <li class="text-amber-700">ถ้าจ่ายไปแล้ว ระบบ<span class="font-bold">ไม่คืนเงินให้อัตโนมัติ</span> ต้องคืนเงินเอง</li>
                        </ul>
                    </div>
                </div>

                <div class="bg-gray-50 px-6 py-4 flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                    <button type="button" onclick="closeUndoLostModal()" class="w-full sm:w-auto px-4 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">
                        ยกเลิก
                    </button>
                    <button type="submit" class="w-full sm:w-auto px-6 py-2.5 text-sm font-medium text-white bg-slate-700 rounded-xl hover:bg-slate-800 transition-colors shadow-lg shadow-slate-500/30">
                        <i class="bi bi-check-lg mr-1"></i>ยืนยันการย้อน
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// 📚 ค่าดำเนินการหนังสือหาย — อ่านจากหน้าตั้งค่าระบบ ไม่ hardcode
const LOST_BOOK_FEE = <?= (float) LOST_BOOK_FEE ?>;

// 💰 คำนวณยอดให้เห็นสด ๆ ระหว่างพิมพ์ราคา — ต้องรู้ก่อนกดว่าจะเรียกเก็บเท่าไร
function updateLostCharge() {
    const raw = document.getElementById('lostPrice').value;
    const el  = document.getElementById('lostCharge');
    if (raw === '' || isNaN(parseFloat(raw))) {
        el.textContent = '-';
        return;
    }
    const total = Math.round((Math.max(0, parseFloat(raw)) + LOST_BOOK_FEE) * 100) / 100;
    el.textContent = total.toLocaleString('th-TH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' บาท';
}

function openLostModal(btn) {
    document.getElementById('lostBorrowId').value = btn.dataset.borrowId;
    document.getElementById('lostBookTitle').textContent = btn.dataset.bookTitle;
    document.getElementById('lostUserName').textContent = btn.dataset.userName;

    // 💰 เติมราคาปกให้ถ้ามี — ถ้าไม่มีต้องให้คนกรอกเอง ห้ามปล่อยเป็น 0
    const price = btn.dataset.price;
    const input = document.getElementById('lostPrice');
    const hint  = document.getElementById('lostPriceHint');
    input.value = price !== '' ? price : '';
    hint.textContent = price !== ''
        ? 'เติมจากราคาปกที่บันทึกไว้ แก้ได้ถ้าราคาจริงต่างไป'
        : 'หนังสือเล่มนี้ยังไม่ได้ระบุราคาปก ต้องกรอกเอง';
    hint.className = price !== '' ? 'mt-1.5 text-xs text-gray-500' : 'mt-1.5 text-xs text-amber-600 font-medium';

    document.getElementById('lostNote').value = '';
    updateLostCharge();

    const modal = document.getElementById('lostModal');
    modal.classList.remove('hidden');
    setTimeout(() => {
        document.getElementById('lostBackdrop').classList.remove('opacity-0');
        document.getElementById('lostPanel').classList.remove('opacity-0', 'translate-y-4', 'sm:scale-95');
    }, 10);
}

function closeLostModal() {
    document.getElementById('lostBackdrop').classList.add('opacity-0');
    document.getElementById('lostPanel').classList.add('opacity-0', 'translate-y-4', 'sm:scale-95');
    setTimeout(() => document.getElementById('lostModal').classList.add('hidden'), 200);
}

function openUndoLostModal(btn) {
    document.getElementById('undoLostBorrowId').value = btn.dataset.borrowId;
    document.getElementById('undoLostBookTitle').textContent = btn.dataset.bookTitle;
    document.getElementById('undoLostUserName').textContent = btn.dataset.userName;
    document.getElementById('undoLostCharge').textContent = btn.dataset.charge;
    document.getElementById('undoNote').value = '';

    const modal = document.getElementById('undoLostModal');
    modal.classList.remove('hidden');
    setTimeout(() => {
        document.getElementById('undoLostBackdrop').classList.remove('opacity-0');
        document.getElementById('undoLostPanel').classList.remove('opacity-0', 'translate-y-4', 'sm:scale-95');
    }, 10);
}

function closeUndoLostModal() {
    document.getElementById('undoLostBackdrop').classList.add('opacity-0');
    document.getElementById('undoLostPanel').classList.add('opacity-0', 'translate-y-4', 'sm:scale-95');
    setTimeout(() => document.getElementById('undoLostModal').classList.add('hidden'), 200);
}

// 📞 จดว่าโทรตามแล้ว
function openContactModal(btn) {
    document.getElementById('contactBorrowId').value = btn.dataset.borrowId;
    document.getElementById('contactBookTitle').textContent = btn.dataset.bookTitle;
    document.getElementById('contactUserName').textContent = btn.dataset.userName;

    // ☎️ ไม่มีเบอร์ก็ยังจดได้ (อาจไปตามที่ห้องเรียน) แค่ซ่อนปุ่มโทร
    const phone = (btn.dataset.userPhone || '').trim();
    const link = document.getElementById('contactPhoneLink');
    const noPhone = document.getElementById('contactNoPhone');
    if (phone) {
        link.href = 'tel:' + phone.replace(/[^0-9+]/g, '');
        document.getElementById('contactPhoneText').textContent = phone;
        link.classList.remove('hidden');
        noPhone.classList.add('hidden');
    } else {
        link.classList.add('hidden');
        noPhone.classList.remove('hidden');
    }

    // 🕘 เคยโทรแล้วโชว์ให้เห็น + เติมหมายเหตุเดิมไว้ให้แก้ต่อ
    const prev = document.getElementById('contactPrev');
    if (btn.dataset.contacted) {
        document.getElementById('contactPrevDate').textContent = btn.dataset.contacted;
        document.getElementById('contactPrevNote').textContent = btn.dataset.note ? ' · ' + btn.dataset.note : '';
        prev.classList.remove('hidden');
    } else {
        prev.classList.add('hidden');
    }
    document.getElementById('contactNote').value = btn.dataset.note || '';

    const modal = document.getElementById('contactModal');
    modal.classList.remove('hidden');
    setTimeout(() => {
        document.getElementById('contactBackdrop').classList.remove('opacity-0');
        document.getElementById('contactPanel').classList.remove('opacity-0', 'translate-y-4', 'sm:scale-95');
    }, 10);
}

function closeContactModal() {
    document.getElementById('contactBackdrop').classList.add('opacity-0');
    document.getElementById('contactPanel').classList.add('opacity-0', 'translate-y-4', 'sm:scale-95');
    setTimeout(() => document.getElementById('contactModal').classList.add('hidden'), 200);
}

// ⚡ ปุ่มลัดเติมข้อความ — กดซ้ำปุ่มเดิมเพื่อล้างได้
function setContactNote(btn) {
    const box = document.getElementById('contactNote');
    box.value = (box.value === btn.dataset.note) ? '' : btn.dataset.note;
    box.focus();
}

document.addEventListener('DOMContentLoaded', function () {
    const p = document.getElementById('lostPrice');
    if (p) p.addEventListener('input', updateLostCharge);
});
</script>


<!-- Return Confirmation Modal (Tailwind CSS) -->
<div id="returnModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <!-- Backdrop -->
    <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity opacity-0" id="modalBackdrop"></div>

    <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0">
        <!-- Modal Panel -->
        <div class="relative w-full max-w-md transform overflow-hidden rounded-2xl bg-white text-left shadow-xl transition-all sm:my-8 opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" id="modalPanel">
            
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
                    <button type="submit" class="inline-flex w-full items-center justify-center gap-1.5 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 transition-all sm:w-auto shadow-emerald-500/30">
                        <i class="bi bi-check-lg text-base leading-none"></i>
                        <span class="leading-none">ยืนยันคืน</span>
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
