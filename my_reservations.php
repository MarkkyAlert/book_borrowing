<?php

/**
 * My Reservations - รายการจองของฉัน
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้แสดงรายการจองเฉพาะของ user ที่ login (session user_id)
 * - สิทธิ์: ต้อง login (ทุก role)
 * - ปุ่ม "ยกเลิก" เรียก api/cancel_reservation.php (POST form)
 * 
 * 📂 Flow:
 * GET → ReservationRepository::findByUser(user_id + filters) → แสดงรายการจอง
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/bootstrap.php';

// 🔒 [AUTH] ต้อง login — ดูได้เฉพาะรายการจองของตัวเองเท่านั้น
requireLogin();

$pdo = getDB();
// 🛡️ [AUTH] ใช้ user_id จาก session — ป้องกันดูข้อมูลคนอื่น (ไม่รับ user_id จาก GET/POST)
$userId = $_SESSION['user_id'];

use App\Repositories\ReservationRepository;

$reservationRepo = new ReservationRepository($pdo);

// 📥 รับ filter จาก query string (pending/fulfilled/cancelled/expired)
$statusFilter = $_GET['status'] ?? '';

// 📚 ดึงรายการจองของ user นี้ (พร้อม JOIN book_title, book_author)
$reservations = $reservationRepo->findByUser($userId, $statusFilter ?: null);

$pageTitle = 'รายการจองของฉัน';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
    <!-- Header -->
    <div class="mb-6 sm:mb-8">
        <h1 class="text-xl sm:text-3xl font-bold text-gray-900 flex items-center">
            <i class="bi bi-bookmark-check text-primary-600 mr-2 text-lg sm:text-3xl"></i>
            รายการจองของฉัน
        </h1>
        <p class="mt-1 sm:mt-2 text-sm sm:text-base text-gray-600">ดูและจัดการรายการจองหนังสือของคุณ</p>
    </div>

    <?php displayFlash(); ?>

    <!-- Filter Tabs (scrollable on mobile) -->
    <div class="mb-6 border-b border-gray-200">
        <nav class="-mb-px flex overflow-x-auto scrollbar-hide gap-1 sm:gap-6" style="-webkit-overflow-scrolling: touch;">
            <?php
            $tabs = [
                '' => 'ทั้งหมด',
                'waiting' => 'ต่อคิวรอ',
                'pending' => 'รอมารับ',
                'fulfilled' => 'ยืมแล้ว',
                'cancelled' => 'ยกเลิก',
                'expired' => 'หมดอายุ',
            ];
            foreach ($tabs as $value => $label):
                $isActive = $statusFilter === $value;
            ?>
                <a href="?status=<?= $value ?>"
                    class="<?= $isActive ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap pb-3 sm:pb-4 px-3 sm:px-1 border-b-2 font-medium text-xs sm:text-sm flex-shrink-0">
                    <?= $label ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <!-- Reservations List -->
    <?php if (empty($reservations)): ?>
        <div class="text-center py-16 bg-white rounded-2xl shadow-sm border border-gray-100">
            <div class="w-20 h-20 mx-auto bg-gray-100 rounded-full flex items-center justify-center mb-4">
                <i class="bi bi-bookmark text-4xl text-gray-400"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">ไม่พบรายการจอง</h3>
            <p class="text-gray-500 mb-6">คุณยังไม่มีรายการจองหนังสือ</p>
            <a href="<?= APP_URL ?>" class="inline-flex items-center px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition-colors">
                <i class="bi bi-search mr-2"></i>
                ค้นหาหนังสือ
            </a>
        </div>
    <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($reservations as $reservation): ?>
                <?php
                $statusClasses = [
                    'waiting' => 'bg-indigo-100 text-indigo-800',
                    'pending' => 'bg-yellow-100 text-yellow-800',
                    'fulfilled' => 'bg-green-100 text-green-800',
                    'cancelled' => 'bg-gray-100 text-gray-800',
                    'expired' => 'bg-red-100 text-red-800'
                ];
                $statusLabels = [
                    'waiting' => 'ต่อคิวรอ',
                    'pending' => 'รอมารับ',
                    'fulfilled' => 'ยืมแล้ว',
                    'cancelled' => 'ยกเลิก',
                    'expired' => 'หมดอายุ'
                ];
                $statusClass = $statusClasses[$reservation['status']] ?? 'bg-gray-100 text-gray-800';
                $statusLabel = $statusLabels[$reservation['status']] ?? $reservation['status'];
                $isExpiringSoon = $reservation['status'] === 'pending' && strtotime($reservation['expires_at']) < strtotime('+1 day');
                ?>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 sm:p-5 hover:shadow-md transition-shadow">
                    <!-- Status badge (top-right on mobile) -->
                    <div class="flex items-center justify-between mb-3 sm:hidden">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $statusClass ?>">
                            <?= $statusLabel ?>
                        </span>
                        <?php if ($isExpiringSoon): ?>
                            <span class="text-xs text-red-500 font-medium">
                                <i class="bi bi-exclamation-circle mr-0.5"></i>ใกล้หมดอายุ!
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 sm:gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 sm:w-12 sm:h-12 bg-primary-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                    <i class="bi bi-book text-lg sm:text-xl text-primary-600"></i>
                                </div>
                                <div class="min-w-0">
                                    <h3 class="font-semibold text-gray-900 text-sm sm:text-base truncate">
                                        <a href="book.php?id=<?= $reservation['book_id'] ?>" class="hover:text-primary-600 transition-colors">
                                            <?= e($reservation['book_title']) ?>
                                        </a>
                                    </h3>
                                    <p class="text-xs sm:text-sm text-gray-500"><?= e($reservation['book_author']) ?></p>
                                    <div class="mt-1.5 sm:mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                                        <span>
                                            <i class="bi bi-calendar3 mr-1"></i>
                                            จองเมื่อ: <?= date('d/m/Y H:i', strtotime($reservation['created_at'])) ?>
                                        </span>
                                        <?php if ($reservation['status'] === 'pending'): ?>
                                            <span class="<?= $isExpiringSoon ? 'text-red-600 font-medium' : '' ?>">
                                                <i class="bi bi-clock mr-1"></i>
                                                หมดอายุ: <?= date('d/m/Y H:i', strtotime($reservation['expires_at'])) ?>
                                            </span>
                                        <?php elseif ($reservation['status'] === 'waiting'): ?>
                                            <?php // 🔄 คิวรอไม่มีวันหมดอายุ (expires_at เป็น NULL)
                                                  //    บอกลำดับกับจำนวนวันที่รอแทน ให้เห็นว่าคิวขยับจริง
                                                  $queuedAt = $reservation['queued_at'] ?? $reservation['created_at'];
                                                  $waitDays = max(0, (int) floor((time() - strtotime($queuedAt)) / 86400));
                                                  $pos = $reservationRepo->getQueuePosition((int) $reservation['id']);
                                            ?>
                                            <span class="text-indigo-600 font-medium">
                                                <i class="bi bi-people mr-1"></i>
                                                คิวที่ <?= $pos ?>
                                            </span>
                                            <span>
                                                <i class="bi bi-hourglass mr-1"></i>
                                                รอมาแล้ว <?= $waitDays ?> วัน
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 sm:gap-3 ml-13 sm:ml-0">
                            <!-- Status badge (desktop only) -->
                            <span class="hidden sm:inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?= $statusClass ?>">
                                <?= $statusLabel ?>
                            </span>

                            <?php // 🔄 ยกเลิกได้ทั้งการจองและคิวรอ — คิวไม่มีวันหมดอายุ
                                  //    ถ้าไม่ให้ยกเลิก คนจะติดคิวหนังสือที่ไม่อยากอ่านแล้วตลอดกาล ?>
                            <?php if (in_array($reservation['status'], ['pending', 'waiting'], true)): ?>
                                <button onclick="confirmCancel(<?= $reservation['id'] ?>, '<?= e(addslashes($reservation['book_title'])) ?>')"
                                    class="inline-flex items-center px-3 py-1.5 text-xs sm:text-sm font-medium text-red-600 bg-red-50 rounded-lg hover:bg-red-100 transition-colors">
                                    <i class="bi bi-x-circle mr-1"></i>
                                    <?= $reservation['status'] === 'waiting' ? 'ออกจากคิว' : 'ยกเลิก' ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Cancel Confirmation Modal -->
<div id="cancelModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6">
        <div class="text-center">
            <div class="w-16 h-16 mx-auto bg-red-100 rounded-full flex items-center justify-center mb-4">
                <i class="bi bi-exclamation-triangle text-3xl text-red-600"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-900 mb-2">ยืนยันการยกเลิก</h3>
            <p class="text-gray-600 mb-1">คุณต้องการยกเลิกการจอง</p>
            <p class="font-semibold text-gray-900 mb-4" id="cancelBookTitle"></p>
            <p class="text-sm text-gray-500 mb-6">การดำเนินการนี้ไม่สามารถย้อนกลับได้</p>
        </div>

        <form id="cancelForm" method="POST" action="<?= APP_URL ?>/api/cancel_reservation.php">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="reservation_id" id="cancelReservationId" value="">

            <div class="flex gap-3">
                <button type="button" onclick="closeModal()"
                    class="flex-1 px-4 py-2.5 bg-gray-100 text-gray-700 rounded-xl font-medium hover:bg-gray-200 transition-colors">
                    ไม่ใช่
                </button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 bg-red-600 text-white rounded-xl font-medium hover:bg-red-700 transition-colors">
                    ยืนยันยกเลิก
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function confirmCancel(reservationId, bookTitle) {
        document.getElementById('cancelReservationId').value = reservationId;
        document.getElementById('cancelBookTitle').textContent = '"' + bookTitle + '"';
        document.getElementById('cancelModal').classList.remove('hidden');
        document.getElementById('cancelModal').classList.add('flex');
    }

    function closeModal() {
        document.getElementById('cancelModal').classList.add('hidden');
        document.getElementById('cancelModal').classList.remove('flex');
    }

    // Close modal on backdrop click
    document.getElementById('cancelModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
        }
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>