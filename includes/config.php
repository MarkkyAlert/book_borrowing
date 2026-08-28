<?php
/**
 * ระบบยืมคืนหนังสือ - Configuration
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * กำหนดค่าคงที่ (PHP constants) ทั้งระบบ:
 * - อ่านจาก .env ถ้ามี หรือใช้ default ถ้าไม่มี
 * - ลูกค้าแก้ค่าได้โดยสร้าง .env จาก .env.example
 *
 * 🏗️ สถาปัตยกรรม:
 * bootstrap.php → require config.php (โหลดก่อนไฟล์อื่นทั้งหมด)
 *
 * 🔄 Flow: อ่าน .env → parse key=value → define() constants
 *
 * ⚙️ ค่าที่ลูกค้ามักต้องการแก้ (แก้ใน .env):
 * - DEFAULT_BORROW_DAYS  → จำนวนวันยืมเริ่มต้น (default: 7)
 * - MAX_BORROW_BOOKS     → ยืมได้สูงสุดกี่เล่ม (default: 3)
 * - FINE_PER_DAY         → ค่าปรับต่อวัน (default: 10 บาท)
 * - MIN_PASSWORD_LENGTH  → รหัสผ่านขั้นต่ำ (default: 6)
 *
 * ⚠️ ห้ามแก้โดยไม่เข้าใจ:
 * - RATE_LIMIT_* → ป้องกัน brute force (login, register, forgot password)
 * - SESSION_LIFETIME → อายุ session (ป้องกัน session ค้างบน shared PC)
 * - DB_* → credentials ฐานข้อมูล
 */

// 📝 .env parser — อ่านไฟล์ .env แล้ว parse เป็น key=value
//    ถ้าไม่มี .env → ใช้ default ทั้งหมด (ไม่พัง)
$envFile = __DIR__ . '/../.env';
$env = [];

if (file_exists($envFile)) {
    // 📝 อ่านทีละบรรทัด (ข้ามบรรทัดว่าง + ขึ้นบรรทัดใหม่)
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // 📝 ข้าม comment (ขึ้นต้นด้วย #)
        if (str_starts_with(trim($line), '#')) continue;
        if (strpos($line, '=') !== false) {
            // 📝 แยก key=value (ใช้ explode limit 2 เผื่อ value ที่มี = อยู่)
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // 📝 ลบ quotes ออก (เช่น 'value' หรือ "value")
            if (preg_match('/^(["\'])(.*)\\1$/', $value, $m)) {
                $value = $m[2];
            }
            $env[$key] = $value;
        }
    }
}

// 📝 Helper: ดึงค่าจาก $env array หรือใช้ default
//    ตัวอย่าง: env('DB_HOST', 'localhost') → ค่าจาก .env หรือ 'localhost'
function env(string $key, mixed $default = null): mixed {
    global $env;
    return $env[$key] ?? $default;
}

// 🗄️ Database Configuration — แก้ใน .env (ห้าม hardcode ใน code)
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'book_borrowing'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));  // 📝 utf8mb4 รองรับ emoji

// 🌐 Application Settings — ชื่อแอป, URL หลัก, อีเมล admin
define('APP_NAME', env('APP_NAME', 'ระบบยืมคืนหนังสือ'));
define('APP_URL', env('APP_URL', 'http://localhost/book_borrowing'));  // 📝 ไม่ต้องลงท้ายด้วย /
define('ADMIN_EMAIL', env('ADMIN_EMAIL', 'admin@library.com'));

// ⭐ Borrow Settings — ลูกค้ามักต้องการแก้ (แก้ใน .env)
define('DEFAULT_BORROW_DAYS', (int) env('DEFAULT_BORROW_DAYS', 7));   // 📝 จำนวนวันยืมเริ่มต้น
define('MAX_BORROW_BOOKS', (int) env('MAX_BORROW_BOOKS', 3));         // 📝 ยืมได้สูงสุดกี่เล่มต่อคน
define('FINE_PER_DAY', (int) env('FINE_PER_DAY', 10));                // 📝 ค่าปรับต่อวัน (บาท)

// 🛡️ Security Settings — แก้ได้แต่ต้องเข้าใจผลกระทบ
//    ⚠️ ลด rate limit เกินไป อาจโดน brute force ได้
define('MIN_PASSWORD_LENGTH', (int) env('MIN_PASSWORD_LENGTH', 6));
define('RATE_LIMIT_MAX_ATTEMPTS', (int) env('RATE_LIMIT_MAX_ATTEMPTS', 5));     // 📝 จำนวนครั้งสูงสุดต่อ window
define('RATE_LIMIT_WINDOW_MINUTES', (int) env('RATE_LIMIT_WINDOW_MINUTES', 15)); // 📝 ช่วงเวลา rate limit (นาที)
// 🔎 rate limit ของ API ค้นหา แยกจากตัวอื่นเพราะพฤติกรรมต่างกันมาก
//    ผู้ใช้กดค้นหาบ่อยเป็นเรื่องปกติ และห้องสมุดมักออกเน็ตผ่าน IP เดียว (NAT)
//    → ทั้งห้องสมุดแชร์โควตาก้อนเดียว ถ้าตั้งต่ำเกินไปลูกค้าจะใช้งานไม่ได้
define('SEARCH_RATE_LIMIT', (int) env('SEARCH_RATE_LIMIT', 300));   // 📝 จำนวนครั้งสูงสุด
define('SEARCH_RATE_WINDOW', (int) env('SEARCH_RATE_WINDOW', 5));   // 📝 ช่วงเวลา (นาที)

// ⏰ Session Settings
define('SESSION_LIFETIME', (int) env('SESSION_LIFETIME', 3600));  // 📝 อายุ session วินาที (ป้องกัน session ค้างบน shared PC)

// ═══════════════════════════════════════════════════════
// 📄 Pagination — จำนวนรายการต่อหน้า
// ═══════════════════════════════════════════════════════
// 🧠 ทำไมต้องแบ่งหน้า: วัดจริงที่ 2,029 เล่ม หน้าแรกส่ง HTML 5.5 MB
//    และ admin/borrows.php ส่ง 7.8 MB — ฝั่ง server เร็ว (50ms) แต่ browser
//    ต้องวาด 32,000+ DOM node จึงช้า (ดู KNOWN_LIMITATIONS §1.1)
// 📌 ปรับได้ผ่าน .env ถ้าลูกค้าอยากได้หน้าใหญ่/เล็กกว่านี้
define('ITEMS_PER_PAGE', (int) env('ITEMS_PER_PAGE', 20));   // 📝 ตารางฝั่งแอดมิน
define('BOOKS_PER_PAGE', (int) env('BOOKS_PER_PAGE', 12));   // 📝 grid หน้าแรก (4 คอลัมน์ × 3 แถว)

// ═══════════════════════════════════════════════════════
// 🔎 Full-text search
// ═══════════════════════════════════════════════════════
// 🧠 ขนาดชิ้นข้อความที่ใช้ทำ index ค้นหา (ดู buildSearchTokens() ใน functions.php)
//    🔴 ห้ามเปลี่ยนโดยไม่รัน `php database/rebuild_search_index.php` ใหม่ทั้งตาราง
//       ไม่งั้น token ที่เก็บไว้กับคำค้นจะคนละสูตร → ค้นไม่เจอทั้งระบบ
//    📌 ต้องไม่ต่ำกว่า innodb_ft_min_token_size ของ MySQL (default 3)
//       ถ้าตั้ง 2 ต้องไปแก้ my.cnf แล้ว restart ซึ่งบังคับลูกค้าไม่ได้
define('SEARCH_TOKEN_SIZE', 3);

// 🐛 Debug Mode — true = แสดง error ละเอียด (ห้ามเปิดบน production!)
//    ⚠️ APP_DEBUG=true จะแสดง password reset link บนหน้าจอด้วย (forgot_password.php)
define('APP_DEBUG', env('APP_DEBUG', 'false') === 'true');

// 🌏 Timezone — ใช้กับ date(), strtotime() ทั้งระบบ
date_default_timezone_set(env('TIMEZONE', 'Asia/Bangkok'));
