<?php
/**
 * Admin: Reservations Management
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireAdmin();

$pdo = getDB();

// Handle Actions (Approve / Cancel)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token ไม่ถูกต้อง');
        redirect('reservations.php');
    }

    $resId = (int) $_POST['id'];
    $action = $_POST['action'];

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ? FOR UPDATE");
        $stmt->execute([$resId]);
        $reservation = $stmt->fetch();

        if (!$reservation || $reservation['status'] !== 'pending') {
            throw new Exception("ไม่พบรายการจองหรือสถานะไม่ถูกต้อง");
        }

        if ($action === 'approve') {
            // Create Borrow Record (Start borrowing immediately)
            $stmt = $pdo->prepare("
                INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status)
                VALUES (?, ?, CURDATE(), CURDATE() + INTERVAL 7 DAY, 'borrowing')
            ");
            $stmt->execute([$reservation['user_id'], $reservation['book_id']]);

            // Update Reservation Status
            $stmt = $pdo->prepare("UPDATE reservations SET status = 'fulfilled' WHERE id = ?");
            $stmt->execute([$resId]);

            setFlash('success', 'อนุมัติการจองสำเร็จ! บันทึกเป็น "กำลังยืม" เรียบร้อยแล้ว');

        } elseif ($action === 'cancel') {
            // Restore Stock
            $stmt = $pdo->prepare("UPDATE books SET available = available + 1 WHERE id = ?");
            $stmt->execute([$reservation['book_id']]);

            // Update Reservation Status
            $stmt = $pdo->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$resId]);

            setFlash('success', 'ยกเลิกการจองและคืนสต็อกหนังสือเรียบร้อยแล้ว');
        }

        $pdo->commit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
    }

    redirect('reservations.php');
}

// Fetch Reservations
$statusFilter = $_GET['status'] ?? 'pending';
$params = [];
$sql = "
    SELECT r.*, u.name as user_name, u.email, b.title as book_title, b.cover_image
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    JOIN books b ON r.book_id = b.id
";

if ($statusFilter !== 'all') {
    $sql .= " WHERE r.status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY r.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reservations = $stmt->fetchAll();

$pageTitle = 'จัดการการจอง';
require_once __DIR__ . '/header.php';
?>

<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <div class="p-6 border-b border-gray-200 flex flex-col md:flex-row justify-between items-center gap-4">
        <h5 class="text-xl font-bold text-gray-800 flex items-center">
            <i class="bi bi-bookmark-star mr-3 text-primary-600"></i>
            รายการจองหนังสือ
        </h5>
        
        <div class="flex rounded-md shadow-sm" role="group">
            <a href="reservations.php?status=pending" class="px-4 py-2 text-sm font-medium border border-gray-300 rounded-l-lg <?= $statusFilter === 'pending' ? 'bg-primary-50 text-primary-700 border-primary-300 z-10 ring-1 ring-primary-300' : 'bg-white text-gray-700 hover:bg-gray-50' ?>">
                รออนุมัติ
            </a>
            <a href="reservations.php?status=all" class="px-4 py-2 text-sm font-medium border border-gray-300 border-l-0 rounded-r-lg <?= $statusFilter === 'all' ? 'bg-primary-50 text-primary-700 border-primary-300 z-10 ring-1 ring-primary-300' : 'bg-white text-gray-700 hover:bg-gray-50' ?>">
                ทั้งหมด
            </a>
        </div>
    </div>

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
                                <?php else: ?>
                                    <?= getReservationStatusLabel($res['status']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <?php if ($res['status'] === 'pending'): ?>
                                    <div class="flex justify-end space-x-2">
                                        <form method="POST" class="inline" onsubmit="return confirm('ยืนยันอนุมัติการยืม?');">
                                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                            <input type="hidden" name="id" value="<?= $res['id'] ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors">
                                                <i class="bi bi-check-lg mr-1"></i> อนุมัติ
                                            </button>
                                        </form>
                                        <form method="POST" class="inline" onsubmit="return confirm('ยืนยันยกเลิกการจอง? (สต็อกจะคืนกลับ)');">
                                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                            <input type="hidden" name="id" value="<?= $res['id'] ?>">
                                            <input type="hidden" name="action" value="cancel">
                                            <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-red-700 bg-red-100 hover:bg-red-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors">
                                                <i class="bi bi-x-lg mr-1"></i> ยกเลิก
                                            </button>
                                        </form>
                                    </div>
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

<?php require_once __DIR__ . '/footer.php'; ?>
