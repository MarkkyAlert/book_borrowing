<?php
/**
 * ระบบยืมคืนหนังสือ - Configuration
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'book_borrowing');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Application Settings
define('APP_NAME', 'ระบบยืมคืนหนังสือ');
define('APP_URL', 'http://localhost/book');
define('ADMIN_EMAIL', 'admin@library.com');

// Borrow Settings
define('DEFAULT_BORROW_DAYS', 7); // จำนวนวันยืมเริ่มต้น
define('MAX_BORROW_BOOKS', 3);    // จำนวนหนังสือที่ยืมได้สูงสุดต่อคน
define('FINE_PER_DAY', 10);       // ค่าปรับต่อวัน (บาท)

// Session Settings
define('SESSION_LIFETIME', 3600); // 1 hour

// Timezone
date_default_timezone_set('Asia/Bangkok');
