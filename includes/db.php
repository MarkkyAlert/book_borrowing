<?php
/**
 * Database Connection using PDO
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * จัดการการเชื่อมต่อฐานข้อมูล MySQL ผ่าน PDO
 * ใช้ Singleton pattern — connection เดียวตลอด request (ประหยัด resource)
 *
 * 🏗️ สถาปัตยกรรม:
 * bootstrap.php → require db.php → getDB() พร้อมใช้ทั่วระบบ
 *
 * 📍 Entrypoints:
 * - ทุกที่ที่ต้องการ PDO → getDB()
 * - getDBWithoutDatabase() → utility สำหรับเชื่อมต่อโดยไม่ระบุ database
 *
 * 🛡️ Security:
 * - EMULATE_PREPARES=false → native prepared statements (ป้องกัน SQL injection)
 * - production ซ่อน error details (ไม่โชว์ DSN/credentials)
 *
 * ⚠️ ห้ามแก้:
 * - EMULATE_PREPARES ต้องเป็น false เสมอ
 * - ERRMODE_EXCEPTION ต้องเปิดไว้ (ให้ try/catch จับ error ได้)
 */

require_once __DIR__ . '/config.php';

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: สร้างหรือดึง PDO connection (Singleton)
 * ==========================================================================
 *
 * 📤 Output: @return PDO instance ที่เชื่อมต่อแล้ว
 *
 * 🧠 เหตุผล:
 * - Singleton (static $pdo) — สร้างครั้งเดียว ใช้ซ้ำตลอด request
 * - ERRMODE_EXCEPTION: throw exception เมื่อ query error
 * - FETCH_ASSOC: ผลลัพธ์เป็น associative array
 * - EMULATE_PREPARES=false: native prepared statements (ป้องกัน SQL injection)
 *
 * 🛡️ Security: production ซ่อน error details (die ด้วยข้อความทั่วไป)
 * ✅ Use case: ทุก Repository + Service ที่ต้องการ DB
 */
function getDB(): PDO
{
    // 📝 Singleton: static $pdo เก็บค่าข้าม call — สร้างครั้งเดียวต่อ request
    static $pdo = null;
    
    if ($pdo === null) {
        // 📝 DSN: host + dbname + charset (utf8mb4 รองรับ emoji)
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        
        // 📝 PDO options:
        //    ERRMODE_EXCEPTION → throw exception เมื่อ query error (จับได้ด้วย try/catch)
        //    FETCH_ASSOC → ผลลัพธ์เป็น ['col' => 'val'] (ไม่มี index ตัวเลข)
        //    EMULATE_PREPARES=false → 🔴 ห้ามเปลี่ยนเป็น true!
        //       native prepared statements = ป้องกัน SQL injection ระดับ driver
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // 📝 เขียน log เสมอ ไม่ว่าจะโหมดไหน — ผู้ดูแลต้องตามหาสาเหตุได้
            error_log("DB Connection Error: " . $e->getMessage());

            // 🛡️ [SECURITY] production ซ่อนรายละเอียด ป้องกัน DSN/credentials หลุด
            $detail = (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : null;
            renderDatabaseDownPage($detail);
        }
    }
    
    // 📤 คืน PDO instance เดิม (Singleton)
    return $pdo;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: แสดงหน้า "ระบบขัดข้อง" แล้วจบการทำงาน
 * ==========================================================================
 * 🧠 ทำไมต้องมีหน้านี้ (เดิมใช้ `die("ข้อความ")` เฉย ๆ):
 *
 * 1. **ต้องตอบ HTTP 503 ไม่ใช่ 200** — เดิมตอบ 200 ทั้งที่ระบบล่ม
 *    เครื่องมือ monitoring/uptime จะรายงานว่าเว็บปกติดี ไม่มีใครรู้ว่าล่ม
 *    และ Google จะเก็บหน้า error ไปเป็นเนื้อหาจริงของเว็บ
 *    503 + Retry-After บอกว่า "ล่มชั่วคราว เดี๋ยวมาใหม่" ซึ่งตรงกับความจริง
 *
 * 2. **ต้องอ่านออกสำหรับคนทั่วไป** — เดิมเป็นข้อความเปล่า ๆ ไม่มี HTML เลย
 *    ลูกค้าเห็นแล้วคิดว่าเว็บพังยับ ทั้งที่แค่ฐานข้อมูลล่มชั่วคราว
 *
 * 🔴 [ข้อบังคับ] หน้านี้ต้องไม่พึ่งอะไรเลยนอกจากตัวมันเอง
 *    ห้ามเรียกฐานข้อมูล (ล่มอยู่) · ห้ามโหลด CSS/JS จากที่อื่น
 *    ห้ามใช้ header.php (ซึ่งอาจแตะฐานข้อมูล) — CSS จึงฝังไว้ในไฟล์นี้เลย
 *
 * 📥 Input: @param string|null $detail รายละเอียด error (เฉพาะตอน APP_DEBUG=true)
 */
function renderDatabaseDownPage(?string $detail = null): void
{
    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 60');   // 📝 บอก crawler/monitoring ว่าลองใหม่ใน 60 วินาที
    }

    // 📝 คำขอแบบ AJAX/API ไม่ต้องการหน้า HTML เต็ม — ส่งข้อความสั้นพอ
    //    (api/search_books.php เอา response ไปแทรกใน DOM โดยตรง)
    $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['SCRIPT_NAME']) && str_contains($_SERVER['SCRIPT_NAME'], '/api/'));
    if ($isAjax) {
        echo '<div style="text-align:center;padding:2rem;color:#b91c1c">ระบบขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้ง</div>';
        exit;
    }

    // 🛡️ แยกข้อความตามเส้นทาง — ใช้ SCRIPT_NAME แบบเดียวกับตัวตรวจ AJAX ข้างบน
    //    เพราะตอนฐานข้อมูลล่มจะพึ่ง session/role ไม่ได้ (บางระบบเก็บ session ใน DB)
    //    เจ้าหน้าที่เท่านั้นที่ควรเห็นวิธีแก้ ส่วนสมาชิกเห็นแค่ว่าให้ถามที่เคาน์เตอร์
    $isStaffArea = isset($_SERVER['SCRIPT_NAME']) && str_contains($_SERVER['SCRIPT_NAME'], '/admin/');

    $appName = defined('APP_NAME') ? APP_NAME : 'ระบบยืมคืนหนังสือ';
    // 🛡️ escape เสมอ — $detail มาจากข้อความของ MySQL ซึ่งอาจมีอักขระพิเศษ
    $safeName   = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
    $safeDetail = $detail !== null ? htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') : null;

    echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>ระบบขัดข้องชั่วคราว - ' . $safeName . '</title><style>';
    echo 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;';
    echo 'background:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1e293b}';
    echo '.box{max-width:32rem;margin:1rem;padding:2.5rem;background:#fff;border:1px solid #e2e8f0;';
    echo 'border-radius:1rem;box-shadow:0 1px 3px rgba(0,0,0,.08);text-align:center}';
    echo 'h1{font-size:1.5rem;margin:0 0 .75rem}p{margin:.5rem 0;color:#475569;line-height:1.7}';
    echo '.icon{font-size:3rem;line-height:1}';
    echo '.steps{margin-top:1.25rem;padding:1rem 1rem 1rem .25rem;background:#f8fafc;';
    echo 'border:1px solid #e2e8f0;border-radius:.5rem;text-align:left}';
    echo '.steps ol{margin:0;padding-left:1.5rem}.steps li{margin:.5rem 0;line-height:1.7;color:#334155}';
    echo '.muted{color:#64748b;font-size:.9em}';
    echo '.detail{margin-top:1.5rem;padding:1rem;background:#fef2f2;border:1px solid #fecaca;';
    echo 'border-radius:.5rem;text-align:left;font-family:ui-monospace,monospace;font-size:.8rem;';
    echo 'color:#991b1b;word-break:break-all}</style></head><body><div class="box">';
    echo '<div class="icon">🔌</div>';
    echo '<h1>ระบบขัดข้องชั่วคราว</h1>';

    if ($isStaffArea) {
        // 👩‍💼 ฝั่งเจ้าหน้าที่ — คนอ่านคือคนที่แก้ได้ จึงบอกขั้นตอนที่ทำเองได้ทันที
        echo '<p>ขณะนี้ระบบไม่สามารถเชื่อมต่อฐานข้อมูลได้</p>';
        echo '<div class="steps"><ol>';
        echo '<li>เปิด <strong>XAMPP Control Panel</strong> แล้วดูว่า <strong>MySQL</strong> ยังทำงานอยู่ไหม<br>';
        echo 'ถ้าไม่ได้ทำงาน ให้กด <strong>Start</strong> แล้วรีเฟรชหน้านี้ — ระบบจะกลับมาเองทันที ';
        echo '<span class="muted">ข้อมูลไม่หาย ไม่ต้องกู้คืนอะไร</span></li>';
        echo '<li>ถ้ากด Start แล้วยังขึ้นหน้านี้อยู่ ให้ติดต่อผู้ขาย แล้วบอกว่า<br>';
        echo '<strong>&ldquo;หน้าเว็บขึ้นว่าเชื่อมต่อฐานข้อมูลไม่ได้&rdquo;</strong></li>';
        echo '</ol></div>';
    } else {
        // 🛡️ ฝั่งสมาชิก — ห้ามบอกว่าเบื้องหลังใช้อะไร
        //    นักเรียนที่เปิดเว็บมาเจอ "เปิด XAMPP Control Panel" คือทั้งงง
        //    และเท่ากับบอกสแตกของเซิร์ฟเวอร์ให้คนนอกรู้ฟรี ๆ
        echo '<p>ขณะนี้ระบบไม่สามารถให้บริการได้ชั่วคราว</p>';
        echo '<p>กรุณาลองใหม่อีกสักครู่ หรือสอบถามที่เคาน์เตอร์ห้องสมุด</p>';
    }
    if ($safeDetail !== null) {
        echo '<div class="detail"><strong>รายละเอียด (แสดงเพราะเปิด APP_DEBUG):</strong><br>' . $safeDetail;
        echo '<br><br>⚠️ อย่าลืมตั้ง APP_DEBUG=false กลับหลังแก้ปัญหาเสร็จ</div>';
    }
    echo '</div></body></html>';
    exit;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: สร้าง PDO โดยไม่เลือก database (สำหรับ install.php)
 * ==========================================================================
 *
 * 📤 Output: @return PDO instance (ยังไม่ได้เลือก database)
 * @throws PDOException
 * ✅ Use case: install.php → CREATE DATABASE IF NOT EXISTS
 */
function getDBWithoutDatabase(): PDO
{
    // 📝 DSN ไม่มี dbname — ใช้สำหรับ CREATE DATABASE
    $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
    
    // 📝 options เหมือน getDB() — native prepared statements
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    // 📝 ไม่ใช้ Singleton เพราะใช้ครั้งเดียวตอน install
    return new PDO($dsn, DB_USER, DB_PASS, $options);
}
