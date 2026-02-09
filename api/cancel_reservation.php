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

// ========== 5. Process Cancellation (via Service - Single Source of Truth) ==========
try {
    $pdo = getDB();
    $reservationService = new \App\Services\ReservationService($pdo);
    
    // Service handles: lock, ownership check (userId), status update, stock return
    $reservationService->cancelReservation($reservationId, $_SESSION['user_id']);
    
    setFlash('success', 'ยกเลิกการจองเรียบร้อยแล้ว');
} catch (\Exception $e) {
    setFlash('error', $e->getMessage());
}

redirect(APP_URL . '/my_reservations.php');
