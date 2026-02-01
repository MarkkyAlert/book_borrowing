<?php
/**
 * Bootstrap File
 * จุดเริ่มต้นของ Application - โหลด dependencies และ initialize
 * 
 * การใช้งาน:
 * require_once __DIR__ . '/bootstrap.php';
 */

// Prevent direct access
if (basename($_SERVER['PHP_SELF']) === 'bootstrap.php') {
    http_response_code(403);
    exit('Direct access not allowed');
}

// Define base path
define('BASE_PATH', __DIR__);

// Load config (single source of truth)
require_once BASE_PATH . '/includes/config.php';

// Load database connection
require_once BASE_PATH . '/includes/db.php';

// Load helper functions
require_once BASE_PATH . '/includes/functions.php';

// [CLEANUP] ล้าง idempotency keys ที่หมดอายุ
cleanupIdempotencyKeys();

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
 * Error handler based on APP_DEBUG constant
 */
if (defined('APP_DEBUG') && APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
