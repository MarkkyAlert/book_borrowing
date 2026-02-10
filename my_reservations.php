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

require_once __DIR__ . '/bootstrap.php';

// [AUTH] ต้อง login — ดูได้เฉพาะรายการจองของตัวเอง
requireLogin();

$pdo = getDB();
// [AUTH] ใช้ user_id จาก session — ป้องกันดูข้อมูลคนอื่น
$userId = $_SESSION['user_id'];

use App\Repositories\ReservationRepository;
$reservationRepo = new ReservationRepository($pdo);

// Get filter
$statusFilter = $_GET['status'] ?? '';

// Get user's reservations
$reservations = $reservationRepo->findByUser($userId, $statusFilter ?: null);

$pageTitle = 'รายการจองของฉัน';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900">
            <i class="bi bi-bookmark-check text-primary-600 mr-2"></i>
            รายการจองของฉัน
        </h1>
        <p class="mt-2 text-gray-600">ดูและจัดการรายการจองหนังสือของคุณ</p>
    </div>

    <?php displayFlash(); ?>

    <!-- Filter Tabs -->
    <div class="mb-6 border-b border-gray-200">
        <nav class="-mb-px flex space-x-8">
            <a href="?status=" 
               class="<?= $statusFilter === '' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap pb-4 px-1 border-b-2 font-medium text-sm">
                ทั้งหมด
            </a>
            <a href="?status=pending" 
               class="<?= $statusFilter === 'pending' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap pb-4 px-1 border-b-2 font-medium text-sm">
                รอดำเนินการ
            </a>
            <a href="?status=fulfilled" 
               class="<?= $statusFilter === 'fulfilled' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap pb-4 px-1 border-b-2 font-medium text-sm">
                ยืมแล้ว
            </a>
            <a href="?status=cancelled" 
               class="<?= $statusFilter === 'cancelled' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap pb-4 px-1 border-b-2 font-medium text-sm">
                ยกเลิก
            </a>
            <a href="?status=expired" 
               class="<?= $statusFilter === 'expired' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap pb-4 px-1 border-b-2 font-medium text-sm">
                หมดอายุ
            </a>
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
                        'pending' => 'bg-yellow-100 text-yellow-800',
                        'fulfilled' => 'bg-green-100 text-green-800',
                        'cancelled' => 'bg-gray-100 text-gray-800',
                        'expired' => 'bg-red-100 text-red-800'
                    ];
                    $statusLabels = [
                        'pending' => 'รอดำเนินการ',
                        'fulfilled' => 'ยืมแล้ว',
                        'cancelled' => 'ยกเลิก',
                        'expired' => 'หมดอายุ'
                    ];
                    $statusClass = $statusClasses[$reservation['status']] ?? 'bg-gray-100 text-gray-800';
                    $statusLabel = $statusLabels[$reservation['status']] ?? $reservation['status'];
                    $isExpiringSoon = $reservation['status'] === 'pending' && strtotime($reservation['expires_at']) < strtotime('+1 day');
                ?>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 hover:shadow-md transition-shadow">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div class="flex-1">
                            <div class="flex items-start gap-3">
                                <div class="w-12 h-12 bg-primary-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                    <i class="bi bi-book text-xl text-primary-600"></i>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-gray-900">
                                        <a href="book.php?id=<?= $reservation['book_id'] ?>" class="hover:text-primary-600 transition-colors">
                                            <?= e($reservation['book_title']) ?>
                                        </a>
                                    </h3>
                                    <p class="text-sm text-gray-500"><?= e($reservation['book_author']) ?></p>
                                    <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-gray-500">
                                        <span>
                                            <i class="bi bi-calendar3 mr-1"></i>
                                            จองเมื่อ: <?= date('d/m/Y H:i', strtotime($reservation['created_at'])) ?>
                                        </span>
                                        <?php if ($reservation['status'] === 'pending'): ?>
                                            <span class="<?= $isExpiringSoon ? 'text-red-600 font-medium' : '' ?>">
                                                <i class="bi bi-clock mr-1"></i>
                                                หมดอายุ: <?= date('d/m/Y H:i', strtotime($reservation['expires_at'])) ?>
                                                <?php if ($isExpiringSoon): ?>
                                                    <span class="text-red-500">(ใกล้หมดอายุ!)</span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?= $statusClass ?>">
                                <?= $statusLabel ?>
                            </span>
                            
                            <?php if ($reservation['status'] === 'pending'): ?>
                                <button onclick="confirmCancel(<?= $reservation['id'] ?>, '<?= e(addslashes($reservation['book_title'])) ?>')"
                                        class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-red-600 bg-red-50 rounded-lg hover:bg-red-100 transition-colors">
                                    <i class="bi bi-x-circle mr-1"></i>
                                    ยกเลิก
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
<div id="cancelModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
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
