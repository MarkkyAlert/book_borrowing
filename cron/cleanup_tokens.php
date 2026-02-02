<?php
/**
 * Cron Job: Cleanup Expired Tokens
 * 
 * ทำหน้าที่: ลบ password reset tokens ที่หมดอายุ
 * 
 * วิธีใช้งาน:
 * 1. ตั้ง cron job: 0 3 * * * php /path/to/book_borrowing/cron/cleanup_tokens.php
 * 2. รันวันละครั้ง ตอนตี 3 (ช่วงที่ใช้งานน้อย)
 * 
 * @package Cron
 */

// Prevent web access
if (php_sapi_name() !== 'cli' && !defined('CRON_ALLOWED')) {
    http_response_code(403);
    exit('Access denied');
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../app/Repositories/PasswordResetRepository.php';

use App\Repositories\PasswordResetRepository;

try {
    $pdo = getDB();
    $resetRepo = new PasswordResetRepository($pdo);
    
    // ลบ tokens ที่หมดอายุ
    $deletedCount = $resetRepo->deleteExpired();
    
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] Deleted expired tokens: {$deletedCount}\n";
    
    // Log to file (optional)
    $logFile = __DIR__ . '/../logs/cron.log';
    if (is_writable(dirname($logFile))) {
        file_put_contents($logFile, "[{$timestamp}] cleanup_tokens: {$deletedCount} deleted\n", FILE_APPEND);
    }
    
} catch (Exception $e) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
