<?php
/**
 * API: Cancel Reservation - ยกเลิกการจองโดยผู้ใช้
 * 
 * @method POST
 * @param int reservation_id - ID ของการจอง
 * @return redirect ไปหน้า my_reservations.php พร้อม flash message
 */

require_once __DIR__ . '/../bootstrap.php';

// 🛡️ [AUTH] ต้อง login ก่อน — ป้องกัน anonymous cancel
if (!isLoggedIn()) {
    setFlash('error', 'กรุณาเข้าสู่ระบบก่อน');
    redirect(APP_URL . '/login.php');
}

// 🛡️ [SECURITY] บังคับ POST — ป้องกันยกเลิกผ่าน GET link
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlash('error', 'Method not allowed');
    redirect(APP_URL . '/my_reservations.php');
}

// 🛡️ [SECURITY] CSRF ป้องกัน attacker หลอกให้ user ยกเลิกโดยไม่รู้ตัว
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'คำขอไม่ถูกต้อง กรุณาลองใหม่');
    redirect(APP_URL . '/my_reservations.php');
}

// 📝 Validate Input — แปลงเป็น int + ตรวจค่า
$reservationId = (int) ($_POST['reservation_id'] ?? 0);

if ($reservationId <= 0) {
    setFlash('error', 'รหัสการจองไม่ถูกต้อง');
    redirect(APP_URL . '/my_reservations.php');
}

// �️ [IDEMPOTENCY] ป้องกัน double-submit (กดยกเลิกซ้ำเร็วๆ)
$idempotencyKey = 'cancel_reservation_' . $reservationId;
if (isset($_SESSION['processed_actions'][$idempotencyKey])) {
    setFlash('info', 'รายการนี้ถูกยกเลิกไปแล้ว');
    redirect(APP_URL . '/my_reservations.php');
}

// �📝 เรียก Service (Single Source of Truth)
try {
    $pdo = getDB();
    $reservationService = new \App\Services\ReservationService($pdo);
    
    // 📝 Service จัดการทั้งหมด: lock, ownership check, status update, stock return
    //    🛡️ ส่ง $_SESSION['user_id'] ไปด้วย — Service จะเช็คว่าเป็นเจ้าของ
    //    ป้องกัน user A ยกเลิก reservation ของ user B
    $reservationService->cancelReservation($reservationId, $_SESSION['user_id']);
    
    // 🛡️ [IDEMPOTENCY] บันทึกว่า process แล้ว
    $_SESSION['processed_actions'][$idempotencyKey] = time();
    
    setFlash('success', 'ยกเลิกการจองเรียบร้อยแล้ว');
} catch (\Exception $e) {
    // ❌ Service throw Exception → แสดง error ผ่าน flash message
    setFlash('error', $e->getMessage());
}

// 📝 redirect กลับหน้า my_reservations.php เสมอ (PRG pattern)
redirect(APP_URL . '/my_reservations.php');
