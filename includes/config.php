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

// ⭐ Borrow Settings — ย้ายไป includes/rules.php แล้ว
//    DEFAULT_BORROW_DAYS · MAX_BORROW_BOOKS · FINE_PER_DAY · RESERVATION_EXPIRE_DAYS
//
// 🧠 ทำไมไม่อยู่ที่นี่: ค่าพวกนี้ให้ลูกค้าแก้เองได้จากหน้า "ตั้งค่าระบบ" (ตาราง settings)
//    แต่ไฟล์นี้ทำงาน **ก่อน** ต่อฐานข้อมูล (bootstrap: config → db → functions)
//    จึงอ่านตาราง settings ตอนนี้ไม่ได้ → ย้ายไปนิยามท้าย functions.php แทน
//
// 📌 ค่าใน .env ยังใช้ได้เหมือนเดิม — เป็น "ชั้นสำรอง" เมื่อตาราง settings ไม่มีค่า
//    ลำดับ: ตาราง settings → .env → ค่า default ใน rules.php

// 🛡️ Security Settings — แก้ได้แต่ต้องเข้าใจผลกระทบ
//    ⚠️ ลด rate limit เกินไป อาจโดน brute force ได้
define('MIN_PASSWORD_LENGTH', (int) env('MIN_PASSWORD_LENGTH', 6));

// 🔑 [F-53] รหัสผ่านเริ่มต้นของสมาชิกที่ถูก "นำเข้า" หรือ "admin สร้างให้"
//    เดิมฝัง '123456' ไว้ในโค้ด (MemberService::importMember) ทำให้ลูกค้าเปลี่ยนไม่ได้
//    🔴 ค่านี้ไม่ใช่ความปลอดภัย — ความปลอดภัยมาจากการ **บังคับเปลี่ยนตอนล็อกอินครั้งแรก**
//       (users.must_change_password) เปลี่ยนค่านี้เป็นอย่างอื่นก็ยังเป็นรหัสร่วมอยู่ดี
//    ใช้ที่: MemberService::importMember() · AuthService::changePassword() (ห้ามตั้งกลับมาเป็นค่านี้)
define('IMPORT_DEFAULT_PASSWORD', (string) env('IMPORT_DEFAULT_PASSWORD', '123456'));
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
date_default_timezone_set(env('TIMEZONE', 'Asia/Bangkok'));// ══════════════════════════════════════════════════════════════════
// 📄 พารามิเตอร์ที่ยอมให้ "พากลับ" หลังบันทึก (F-37)
// ══════════════════════════════════════════════════════════════════
// 🛡️ [SECURITY] เป็น whitelist — อะไรที่ไม่อยู่ในนี้จะถูกทิ้งทั้งหมด
//    ระบบไม่เคยรับ URL จากผู้ใช้ รับแค่ค่าของพารามิเตอร์เหล่านี้แล้วประกอบ URL เอง
// ⚠️ ห้ามใส่ 'print' — บันทึกเสร็จแล้วจะเด้งเข้าโหมดพิมพ์
define('LIST_STATE_BOOKS',        ['page', 'search', 'category', 'status', 'sort', 'is_reference']);
define('LIST_STATE_MEMBERS',      ['page', 'search', 'role', 'status', 'sort']);
define('LIST_STATE_BORROWS',      ['page', 'search', 'status', 'filter']);
define('LIST_STATE_RESERVATIONS', ['page', 'status']);
define('LIST_STATE_PAYMENTS',     ['page', 'upage', 'search']);
define('LIST_STATE_CATEGORIES',   []);   // หน้านี้ไม่มีตัวกรอง/แบ่งหน้า


