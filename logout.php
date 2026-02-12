<?php
/**
 * Logout - ออกจากระบบ
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/bootstrap.php';

// 🛡️ [SECURITY] POST only + CSRF — ป้องกัน logout via image/link injection
//    ถ้าใช้ GET → attacker อาจฝัง <img src="logout.php"> ในหน้าอื่น แล้ว user จะถูก logout โดยไม่รู้ตัว
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    redirect(APP_URL . '/index.php');
}

// ── ลำดับการทำลาย session (สำคัญ: ต้องทำครบ 3 ขั้นตอน) ──
// 1️⃣ ล้างข้อมูลใน $_SESSION (user_id, role, ...)
$_SESSION = [];

// 2️⃣ ทำลาย session cookie ใน browser — ตั้งค่าหมดอายุเป็นอดีต
//    ไม่ทำขั้นนี้ = session ID เก่ายังค้างใน browser → ถูกขโมยได้
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,     // หมดอายุย้อนหลัง (~12 ชั่วโมง)
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 3️⃣ ทำลาย session file บน server
session_destroy();

// 🔄 เริ่ม session ใหม่เพื่อส่ง flash message ไปหน้า login
session_start();
setFlash('success', 'ออกจากระบบเรียบร้อยแล้ว');

redirect(APP_URL . '/login.php');
