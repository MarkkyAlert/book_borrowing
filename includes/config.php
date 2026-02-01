<?php
/**
 * ระบบยืมคืนหนังสือ - Configuration
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - ไฟล์นี้กำหนดค่าคงที่ทั้งระบบ (constants)
 * - ค่าจะถูกอ่านจาก .env ถ้ามี หรือใช้ default ถ้าไม่มี
 * - ลูกค้าแก้ค่าได้โดยสร้างไฟล์ .env จาก .env.example
 * 
 * ⚙️ ค่าที่ลูกค้ามักต้องการแก้:
 * - DEFAULT_BORROW_DAYS  → จำนวนวันยืมเริ่มต้น (default: 7)
 * - MAX_BORROW_BOOKS     → ยืมได้สูงสุดกี่เล่ม (default: 3)
 * - FINE_PER_DAY         → ค่าปรับต่อวัน (default: 10 บาท)
 * - MIN_PASSWORD_LENGTH  → รหัสผ่านขั้นต่ำ (default: 6)
 * 
 * ⚠️ ห้ามแก้โดยไม่เข้าใจ:
 * - RATE_LIMIT_* → ป้องกัน brute force
 * - SESSION_LIFETIME → อายุ session
 */

// Load .env parser
$envFile = __DIR__ . '/../.env';
$env = [];

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Remove quotes
            if (preg_match('/^(["\'])(.*)\\1$/', $value, $m)) {
                $value = $m[2];
            }
            $env[$key] = $value;
        }
    }
}

// Helper function to get env value with default
function env(string $key, mixed $default = null): mixed {
    global $env;
    return $env[$key] ?? $default;
}

// Database Configuration
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'book_borrowing'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// Application Settings
define('APP_NAME', env('APP_NAME', 'ระบบยืมคืนหนังสือ'));
define('APP_URL', env('APP_URL', 'http://localhost/book_borrowing'));
define('ADMIN_EMAIL', env('ADMIN_EMAIL', 'admin@library.com'));

// Borrow Settings - ⭐ ลูกค้าสามารถแก้ไขใน .env ได้
define('DEFAULT_BORROW_DAYS', (int) env('DEFAULT_BORROW_DAYS', 7));
define('MAX_BORROW_BOOKS', (int) env('MAX_BORROW_BOOKS', 3));
define('FINE_PER_DAY', (int) env('FINE_PER_DAY', 10));

// Security Settings
define('MIN_PASSWORD_LENGTH', (int) env('MIN_PASSWORD_LENGTH', 6));
define('RATE_LIMIT_MAX_ATTEMPTS', (int) env('RATE_LIMIT_MAX_ATTEMPTS', 5));
define('RATE_LIMIT_WINDOW_MINUTES', (int) env('RATE_LIMIT_WINDOW_MINUTES', 15));

// Session Settings
define('SESSION_LIFETIME', (int) env('SESSION_LIFETIME', 3600));

// Debug Mode
define('APP_DEBUG', env('APP_DEBUG', 'false') === 'true');

// Timezone
date_default_timezone_set(env('TIMEZONE', 'Asia/Bangkok'));
