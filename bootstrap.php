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

// 🛡️ [SECURITY] ป้องกันเข้าถึงไฟล์นี้ตรงๆ (http://...bootstrap.php)
//    ไฟล์นี้ต้องถูก require จากหน้าอื่นเท่านั้น
if (basename($_SERVER['PHP_SELF']) === 'bootstrap.php') {
    http_response_code(403);
    exit('Direct access not allowed');
}

// 📁 กำหนด root path ของโปรเจค — ใช้ทั่วระบบแทน __DIR__ . '/...'
define('BASE_PATH', __DIR__);

// ── โหลดไฟล์หลัก (ลำดับสำคัญ: config → db → functions) ──
// ⚠️ ห้ามสลับลำดับ! config กำหนด constant ที่ db และ functions ต้องใช้
require_once BASE_PATH . '/includes/config.php';    // 1️⃣ constants (DB_HOST, APP_URL, ...)
require_once BASE_PATH . '/includes/db.php';        // 2️⃣ PDO singleton (getDB())
require_once BASE_PATH . '/includes/functions.php';  // 3️⃣ helper functions (e(), redirect(), ...)

// 🧹 [CLEANUP] ล้าง idempotency keys ที่หมดอายุ (>60วินาที) ทุก request
cleanupIdempotencyKeys();

// 🧹 [CLEANUP] ล้าง rate_limits records เก่ากว่า 1 วัน (probabilistic — ~1% ของ requests)
//    ป้องกัน table บวมจาก stale records ที่ไม่ถูกเรียกอีก (เช่น login_md5(email)_IP ของ user ที่ไม่กลับมา)
if (mt_rand(1, 100) === 1) {
    try { getDB()->exec("DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)"); } catch (\Exception $e) {}
}

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
