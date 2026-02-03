<?php
/**
 * API: Cancel Reservation - ยกเลิกการจองโดยผู้ใช้
 * 
 * @method POST
 * @param int reservation_id - ID ของการจอง
 * @return redirect ไปหน้า my_reservations.php พร้อม flash message
 */

require_once __DIR__ . '/../bootstrap.php';

// ========== 1. ตรวจ Auth ==========
if (!isLoggedIn()) {
    setFlash('error', 'กรุณาเข้าสู่ระบบก่อน');
    redirect(APP_URL . '/login.php');
}

// ========== 2. ตรวจ Method ==========
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlash('error', 'Method not allowed');
    redirect(APP_URL . '/my_reservations.php');
}

// ========== 3. ตรวจ CSRF ==========
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'คำขอไม่ถูกต้อง กรุณาลองใหม่');
    redirect(APP_URL . '/my_reservations.php');
}

// ========== 4. Validate Input ==========
$reservationId = (int) ($_POST['reservation_id'] ?? 0);

if ($reservationId <= 0) {
    setFlash('error', 'รหัสการจองไม่ถูกต้อง');
    redirect(APP_URL . '/my_reservations.php');
}

// ========== 5. Process Cancellation ==========
$pdo = getDB();

require_once __DIR__ . '/../app/Repositories/ReservationRepository.php';
require_once __DIR__ . '/../app/Repositories/BookRepository.php';

$reservationRepo = new \App\Repositories\ReservationRepository($pdo);
$bookRepo = new \App\Repositories\BookRepository($pdo);

try {
    $pdo->beginTransaction();
    
    // 5.1 ดึงข้อมูลการจอง + lock
    $reservation = $reservationRepo->findPendingForUpdate($reservationId);
    
    if (!$reservation) {
        $pdo->rollBack();
        setFlash('error', 'ไม่พบรายการจอง หรือไม่อยู่ในสถานะรอดำเนินการ');
        redirect(APP_URL . '/my_reservations.php');
    }
    
    // 5.2 [AUTHORIZATION] ตรวจสอบว่าเป็นเจ้าของการจอง
    if ($reservation['user_id'] !== $_SESSION['user_id']) {
        $pdo->rollBack();
        setFlash('error', 'คุณไม่มีสิทธิ์ยกเลิกการจองนี้');
        redirect(APP_URL . '/my_reservations.php');
    }
    
    // 5.3 อัปเดตสถานะเป็น cancelled
    $updated = $reservationRepo->updateStatus($reservationId, 'cancelled');
    
    if (!$updated) {
        $pdo->rollBack();
        setFlash('error', 'ไม่สามารถยกเลิกการจองได้');
        redirect(APP_URL . '/my_reservations.php');
    }
    
    // 5.4 คืน stock หนังสือ
    $bookRepo->incrementAvailable($reservation['book_id']);
    
    $pdo->commit();
    
    setFlash('success', 'ยกเลิกการจองเรียบร้อยแล้ว');
    
} catch (\Exception $e) {
    $pdo->rollBack();
    setFlash('error', 'เกิดข้อผิดพลาด กรุณาลองใหม่');
}

redirect(APP_URL . '/my_reservations.php');
