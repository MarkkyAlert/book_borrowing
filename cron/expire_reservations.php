<?php
/**
 * Cron Job: Expire Overdue Reservations
 * 
 * ทำหน้าที่: ตรวจสอบและ expire การจองที่หมดอายุ พร้อมคืน stock
 * 
 * วิธีใช้งาน:
 * 1. ตั้ง cron job: 0,15,30,45 * * * * php /path/to/book_borrowing/cron/expire_reservations.php
 * 2. หรือเรียกผ่าน Task Scheduler บน Windows
 * 
 * @package Cron
 */

// Prevent web access
if (php_sapi_name() !== 'cli' && !defined('CRON_ALLOWED')) {
    http_response_code(403);
    exit('Access denied');
}

require_once __DIR__ . '/../bootstrap.php';

use App\Services\ReservationService;

try {
    $pdo = getDB();
    $reservationService = new ReservationService($pdo);
    
    $expiredCount = $reservationService->expireOverdueReservations();
    
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] Expired reservations: {$expiredCount}\n";
    
    // Log to file (optional)
    $logFile = __DIR__ . '/../logs/cron.log';
    if (is_writable(dirname($logFile))) {
        file_put_contents($logFile, "[{$timestamp}] expire_reservations: {$expiredCount} expired\n", FILE_APPEND);
    }
    
} catch (Exception $e) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
