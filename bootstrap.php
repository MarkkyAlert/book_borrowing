<?php
/**
 * Bootstrap File - จุดเริ่มต้นของ Application
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * ไฟล์นี้ถูก require ที่บรรทัดแรกของทุกหน้า ทำหน้าที่:
 * 1. โหลด config (constants ทั้งหมด)
 * 2. โหลด database connection (PDO singleton)
 * 3. โหลด helper functions (ใช้ได้ทั่วระบบ)
 * 4. ล้าง idempotency keys หมดอายุ
 * 5. ตั้งค่า autoloader สำหรับ class ใน app/
 * 6. ตั้งค่า error reporting ตาม APP_DEBUG
 *
 * 🏗️ สถาปัตยกรรม:
 * ทุกหน้า (.php) → require bootstrap.php → config + db + functions พร้อมใช้
 *
 * 📂 โครงสร้างโปรเจค:
 * - *.php (root)      → หน้าเว็บ public
 * - admin/*.php       → หน้า admin/staff
 * - api/*.php         → JSON API endpoints
 * - app/Services/     → Business logic
 * - app/Repositories/ → Database access (SQL)
 * - includes/         → Config, DB, helper functions
 *
 * 🛡️ Security:
 * - ป้องกันเข้าถึงโดยตรง (basename check)
 * - error reporting ตาม APP_DEBUG (production ซ่อน error)
 *
 * ⚠️ ห้ามแก้:
 * - ลำดับ require (ต้อง config ก่อน db ก่อน functions)
 * - autoloader map (ต้องตรงกับ namespace)
 *
 * การใช้งาน: require_once __DIR__ . '/bootstrap.php';
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
 * ==========================================================================
 * 🎯 Autoloader: โหลด class จาก app/ อัตโนมัติตาม namespace
 * ==========================================================================
 * 🧠 เหตุผล: map namespace prefix → directory
 *   เช่น App\Services\BookService → app/Services/BookService.php
 * ⚠️ ถ้าเพิ่ม namespace ใหม่ ต้องเพิ่มใน $map ด้วย
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
 * ==========================================================================
 * 🎯 Error Reporting: ตั้งค่าตาม APP_DEBUG
 * ==========================================================================
 * 🛡️ production (APP_DEBUG=false): ซ่อน error ทั้งหมด
 */
if (defined('APP_DEBUG') && APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
