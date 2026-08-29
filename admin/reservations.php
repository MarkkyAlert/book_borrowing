<?php
/**
 * Admin: Reservations Management - จัดการการจอง
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้แสดงรายการจองทั้งหมด + ปุ่ม "อนุมัติ" / "ยกเลิก"
 * - สิทธิ์: staff ขึ้นไป
 * 
 * 📂 Flow:
 * 1. POST action=fulfill → ReservationService::fulfillReservation() → สร้าง borrow + เปลี่ยน status เป็น fulfilled
 * 2. POST action=cancel  → ReservationService::cancelReservation() → คืน stock + เปลี่ยน status เป็น cancelled
 * 3. GET → แสดงรายการจอง (filter: search, status)
 * 
 * ⚠️ ระวัง:
 * - fulfill/cancel ใช้ transaction + row lock — ห้ามเรียก DB โดยตรง
 * - Idempotency key ป้องกัน double-submit (approve ซ้ำ = error)
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/../bootstrap.php';
// 🔒 [AUTH] staff/admin เท่านั้น
requireStaff();

use App\Services\ReservationService;       // Business logic: fulfill, cancel (transaction + stock)
use App\Repositories\ReservationRepository; // ดึงรายการจอง (read-only)

// 📦 สร้าง service/repository instances
$pdo = getDB();
$reservationService = new ReservationService($pdo);
$reservationRepo = new ReservationRepository($pdo);

// ── POST: อนุมัติ / ยกเลิกการจอง ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ [SECURITY] CSRF — ป้องกัน attacker หลอกให้ staff อนุมัติ/ยกเลิกการจอง
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token ไม่ถูกต้อง');
        redirectToList('reservations.php', LIST_STATE_RESERVATIONS);
    }

    $resId = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    // [IDEMPOTENCY] ป้องกัน double-submit
    $idempotencyKey = 'reservation_' . $action . '_' . $resId;
    if (isset($_SESSION['processed_actions'][$idempotencyKey])) {
        setFlash('info', 'รายการนี้ถูกดำเนินการไปแล้ว');
        redirectToList('reservations.php', LIST_STATE_RESERVATIONS);
    }

    try {
        if ($action === 'approve') {
            // [STATE TRANSITION] pending → fulfilled
            //   🚀 fulfillReservation() ทำ: สร้าง borrow record + เปลี่ยน status เป็น fulfilled
            //   ⚠️ ใช้ transaction + row lock ภายใน Service
            $result = $reservationService->fulfillReservation($resId);
            $_SESSION['processed_actions'][$idempotencyKey] = time();
            setFlash('success', $result['message']);

        } elseif ($action === 'cancel') {
            // [STATE TRANSITION] pending → cancelled
            //   📦 cancelReservation() ทำ: คืน stock (available +1) + เปลี่ยน status
            //   🛡️ [BY DESIGN] ไม่ส่ง userId → ข้าม ownership check
            //      staff/admin ยกเลิกการจองของใครก็ได้ (ต่างจาก member ที่ยกเลิกได้เฉพาะของตัวเอง)
            //      ดู api/cancel_reservation.php ที่ส่ง $_SESSION['user_id'] เพื่อเช็ค ownership
            $reservationService->cancelReservation($resId);
            $_SESSION['processed_actions'][$idempotencyKey] = time();
            setFlash('success', 'ยกเลิกการจองและคืนสต็อกหนังสือเรียบร้อยแล้ว');
        }
    } catch (Exception $e) {
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
    }

    redirectToList('reservations.php', LIST_STATE_RESERVATIONS);
}

// ── GET: ดึงรายการจองตาม filter ──
// 📥 default แสดงเฉพาะ "pending" — staff สนใจรายการที่รอดำเนินการเป็นหลัก
$statusFilter = $_GET['status'] ?? 'pending';
$page = (int) ($_GET['page'] ?? 1);

$filters = [];
if ($statusFilter !== 'all') {
    $filters['status'] = $statusFilter;
}

// 📄 นับยอดรวมก่อน (ด้วย filter ชุดเดียวกัน) แล้วคำนวณว่าอยู่หน้าไหน ต้องข้ามกี่แถว
$pagination = paginate($reservationRepo->countAll($filters), $page, ITEMS_PER_PAGE);
$filters['limit']  = $pagination['per_page'];
$filters['offset'] = $pagination['offset'];

$reservations = $reservationRepo->findAll($filters);

// 🔢 [F-42] จำนวน "จองแล้วไม่มารับ" — มาจาก query ที่ไม่มี LIMIT
//    🔴 ห้ามนับจากแถวที่แสดง เพราะจะได้เลขที่ตัดแล้ว (บทเรียนจาก F-35)
$expiredCount = $reservationRepo->countAll(['status' => 'expired']);
$expiredThisMonth = $reservationRepo->countExpiredThisMonth();

// 📄 filter ที่ต้องติดไปกับลิงก์เปลี่ยนหน้า — ไม่งั้นกดหน้า 2 แล้วเด้งกลับไปดู "รออนุมัติ"
$paginationParams = ['status' => $statusFilter];

$pageTitle = 'จัดการการจอง';
require_once __DIR__ . '/header.php';
?>

<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <div class="p-6 border-b border-gray-200 flex flex-col md:flex-row justify-between items-center gap-4">
        <h5 class="text-xl font-bold text-gray-800 flex items-center">
            <i class="bi bi-bookmark-star mr-3 text-primary-600"></i>
            รายการจองหนังสือ
        </h5>
        
        <?php // 🔄 แยก "รอมารับ" กับ "ต่อคิวรอ" ให้ชัด — เป็นคนละงานของเจ้าหน้าที่
              //    รอมารับ = ของกันไว้แล้ว ต้องคอยดูว่าหมดเขตเมื่อไหร่
              //    ต่อคิวรอ = ยังไม่มีของ ไม่ต้องทำอะไร ระบบเลื่อนคิวให้เอง ?>
        <div class="flex rounded-md shadow-sm" role="group">
            <a href="reservations.php?status=pending" class="px-4 py-2 text-sm font-medium border border-gray-300 rounded-l-lg <?= $statusFilter === 'pending' ? 'bg-primary-50 text-primary-700 border-primary-300 z-10 ring-1 ring-primary-300' : 'bg-white text-gray-700 hover:bg-gray-50' ?>">
                รอมารับ
            </a>
            <a href="reservations.php?status=waiting" class="px-4 py-2 text-sm font-medium border border-gray-300 border-l-0 <?= $statusFilter === 'waiting' ? 'bg-primary-50 text-primary-700 border-primary-300 z-10 ring-1 ring-primary-300' : 'bg-white text-gray-700 hover:bg-gray-50' ?>">
                ต่อคิวรอ
            </a>
            <?php // 🔴 [F-42] แท็บ "ไม่มารับ" — บรรณารักษ์ต้องหาให้เจอว่าใครจองแล้วไม่มารับ
                  //    เดิมต้องกด "ทั้งหมด" แล้วไล่หาสถานะ "หมดอายุ" ใน 47 รายการ 3 หน้า
                  //    ยิ่งเพราะ lazy expire เคลียร์ให้ก่อนหน้าจอจะ render สภาพนี้จึงมองไม่เห็นเลย ?>
            <a href="reservations.php?status=expired" class="px-4 py-2 text-sm font-medium border border-gray-300 border-l-0 <?= $statusFilter === 'expired' ? 'bg-primary-50 text-primary-700 border-primary-300 z-10 ring-1 ring-primary-300' : 'bg-white text-gray-700 hover:bg-gray-50' ?>">
                ไม่มารับ
                <?php if ($expiredCount > 0): ?>
                    <span class="ml-1 px-1.5 py-0.5 bg-gray-500 text-white text-xs rounded-full"><?= number_format($expiredCount) ?></span>
                <?php endif; ?>
            </a>
            <a href="reservations.php?status=all" class="px-4 py-2 text-sm font-medium border border-gray-300 border-l-0 rounded-r-lg <?= $statusFilter === 'all' ? 'bg-primary-50 text-primary-700 border-primary-300 z-10 ring-1 ring-primary-300' : 'bg-white text-gray-700 hover:bg-gray-50' ?>">
                ทั้งหมด
            </a>
        </div>
    </div>

    <?php // 📊 [F-42] ตัวเลข "จองแล้วไม่มารับ" — ปัญหานี้เกิดบ่อยแค่ไหน
          //    lazy expire เคลียร์ให้ก่อนหน้าจอ render สภาพนี้จึงมองไม่เห็นระหว่างใช้งานปกติ ?>
    <?php if ($expiredThisMonth > 0 && $statusFilter !== 'expired'): ?>
        <div class="px-6 py-3 bg-gray-50 border-b border-gray-200 text-sm text-gray-600 flex items-center gap-2">
            <i class="bi bi-info-circle text-gray-400"></i>
            เดือนนี้มีการจองที่ไม่มารับ <span class="font-semibold text-gray-800"><?= number_format($expiredThisMonth) ?></span> ครั้ง
            <a href="reservations.php?status=expired" class="text-primary-600 hover:text-primary-700 underline">ดูรายการ</a>
        </div>
    <?php endif; ?>

    <?php if (empty($reservations)): ?>
        <div class="text-center py-12">
            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="bi bi-inbox text-3xl text-gray-400"></i>
            </div>
            <p class="text-gray-500 text-lg">ไม่พบรายการจอง</p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">หนังสือ</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ผู้จอง</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">วันที่จอง</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">หมดอายุ/สถานะ</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($reservations as $res): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-16 w-12">
                                        <?php if ($res['cover_image']): ?>
                                            <img class="h-16 w-12 object-cover rounded shadow-sm" src="../uploads/covers/<?= e($res['cover_image']) ?>" alt="">
                                        <?php else: ?>
                                            <div class="h-16 w-12 bg-gray-200 rounded flex items-center justify-center text-gray-400">
                                                <i class="bi bi-book"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900"><?= e($res['book_title']) ?></div>
                                        <div class="text-xs text-gray-500">ID: <?= $res['book_id'] ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?= e($res['user_name']) ?></div>
                                <div class="text-sm text-gray-500"><?= e($res['email']) ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <div class="flex items-center">
                                    <i class="bi bi-calendar3 mr-2 text-gray-400"></i>
                                    <?= formatDate($res['created_at']) ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($res['status'] === 'pending'): ?>
                                    <?php 
                                        $daysLeft = daysDiff(date('Y-m-d'), $res['expires_at']); 
                                        $textColor = $daysLeft < 0 ? 'text-red-600' : ($daysLeft == 0 ? 'text-amber-600' : 'text-green-600');
                                        $bgColor = $daysLeft < 0 ? 'bg-red-50' : ($daysLeft == 0 ? 'bg-amber-50' : 'bg-green-50');
                                    ?>
                                    <div class="flex flex-col space-y-1">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 w-fit">
                                            <i class="bi bi-hourglass-split mr-1"></i> รอรับของ
                                        </span>
                                        <span class="text-xs <?= $textColor ?> font-medium">
                                            หมดเขต: <?= formatDate($res['expires_at']) ?>
                                            (<?= $daysLeft < 0 ? 'หมดอายุ' : "เหลือ $daysLeft วัน" ?>)
                                        </span>
                                    </div>
                                <?php elseif ($res['status'] === 'waiting'): ?>
                                    <?php // 🔄 คิวรอไม่มี expires_at (เป็น NULL) ห้ามเอาไปคำนวณวัน
                                          //    strtotime(null) จะได้ 1970 + คำเตือน deprecated บน PHP 8.1+
                                          $queuedAt = $res['queued_at'] ?? $res['created_at'];
                                          $waitDays = max(0, (int) floor((time() - strtotime($queuedAt)) / 86400));
                                    ?>
                                    <div class="flex flex-col space-y-1">
                                        <?= getReservationStatusLabel('waiting') ?>
                                        <span class="text-xs text-gray-500">รอมาแล้ว <?= $waitDays ?> วัน · ไม่มีวันหมดอายุ</span>
                                    </div>
                                <?php else: ?>
                                    <?= getReservationStatusLabel($res['status']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <?php if ($res['status'] === 'pending'): ?>
                                    <div class="flex justify-end space-x-2">
                                        <form method="POST" class="inline" onsubmit="return confirmSubmit(this, 'ยืนยันอนุมัติการยืม?', {title: 'อนุมัติการจอง', confirmText: 'อนุมัติ', confirmClass: 'success'});">
                                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                            <input type="hidden" name="id" value="<?= $res['id'] ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors">
                                                <i class="bi bi-check-lg mr-1"></i> อนุมัติ
                                            </button>
                                        </form>
                                        <form method="POST" class="inline" onsubmit="return confirmSubmit(this, 'ยืนยันยกเลิกการจอง?\n(สต็อกจะคืนกลับ)', {title: 'ยกเลิกการจอง', confirmText: 'ยกเลิกการจอง', confirmClass: 'danger'});">
                                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                            <input type="hidden" name="id" value="<?= $res['id'] ?>">
                                            <input type="hidden" name="action" value="cancel">
                                            <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-red-700 bg-red-100 hover:bg-red-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors">
                                                <i class="bi bi-x-lg mr-1"></i> ยกเลิก
                                            </button>
                                        </form>
                                    </div>
                                <?php elseif ($res['status'] === 'expired'): ?>
                                    <?php // 🔄 [F-42] เดิมเขียนว่า "ดำเนินการแล้ว" เฉย ๆ ทำอะไรต่อไม่ได้
                                          //    แต่งานจริงคือ "สมาชิกมาช้า อยากได้อยู่" → ให้จองใหม่ได้เลย
                                          //    ลิงก์พาไปหน้าหนังสือพร้อมค้นชื่อสมาชิกไว้ให้ ?>
                                    <a href="<?= e(listStateLink('borrow_form.php?user_id=' . (int) $res['user_id'] . '&book_id=' . (int) $res['book_id'], LIST_STATE_RESERVATIONS)) ?>"
                                       class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-primary-700 bg-primary-100 hover:bg-primary-200 transition-colors"
                                       title="สมาชิกมาช้าแต่ยังอยากได้ — บันทึกการยืมให้เลย">
                                        <i class="bi bi-arrow-repeat mr-1"></i> ให้ยืมเลย
                                    </a>
                                <?php else: ?>
                                    <span class="text-gray-400 italic text-xs">ดำเนินการแล้ว</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php // 📄 แถบเลือกหน้า (ไม่แสดงถ้ามีหน้าเดียว) ?>
<?php require __DIR__ . '/../includes/pagination.php'; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
