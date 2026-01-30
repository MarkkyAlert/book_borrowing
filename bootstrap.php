<?php
/**
 * Bootstrap File
 * จุดเริ่มต้นของ Application - โหลด dependencies และ initialize
 * 
 * การใช้งาน:
 * require_once __DIR__ . '/bootstrap.php';
 * 
 * หมายเหตุ: ไฟล์นี้ยังไม่ถูกใช้งานในระบบปัจจุบัน
 * เตรียมไว้สำหรับ Phase 2+ ที่จะ refactor ให้ใช้ระบบใหม่
 */

// Prevent direct access
if (basename($_SERVER['PHP_SELF']) === 'bootstrap.php') {
    http_response_code(403);
    exit('Direct access not allowed');
}

// Define base path
define('BASE_PATH', __DIR__);

// Load settings helper
require_once BASE_PATH . '/app/Config/settings.php';

// Load original config (for backward compatibility)
// config.php ยังคงเป็น source of truth จนกว่าจะ migrate เสร็จ
require_once BASE_PATH . '/includes/config.php';

// Load database connection
require_once BASE_PATH . '/includes/db.php';

// Load helper functions
require_once BASE_PATH . '/includes/functions.php';

/**
 * Simple autoloader for app/ classes
 * รองรับ class ใน app/Services/, app/Repositories/, etc.
 */
spl_autoload_register(function (string $class) {
    // Map namespace to directory
    $map = [
        'App\\Services\\' => BASE_PATH . '/app/Services/',
        'App\\Repositories\\' => BASE_PATH . '/app/Repositories/',
        'App\\Helpers\\' => BASE_PATH . '/app/Helpers/',
        'App\\Config\\' => BASE_PATH . '/app/Config/',
    ];

    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $relativeClass = substr($class, strlen($prefix));
            $file = $dir . str_replace('\\', '/', $relativeClass) . '.php';
            
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }
    }

    return false;
});

/**
 * Error handler for development
 * จะถูกใช้เมื่อ APP_DEBUG=true ใน .env
 */
if (Settings::get('APP_DEBUG', false)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Set timezone
date_default_timezone_set(Settings::get('TIMEZONE', 'Asia/Bangkok'));
