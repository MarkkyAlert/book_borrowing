<?php
/**
 * Helper Functions - ฟังก์ชันช่วยเหลือทั่วไป
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * รวม helper functions ทั้งหมดที่ใช้ได้ทั่วระบบ
 * โหลดอัตโนมัติผ่าน bootstrap.php — ไม่ต้อง require เอง
 *
 * 🏗️ สถาปัตยกรรม:
 * bootstrap.php → require functions.php → พร้อมใช้ทั่วระบบ
 *
 * 📌 หมวดหมู่ฟังก์ชัน:
 * 🛡️ Security:
 *   - e()                   → escape HTML (ป้องกัน XSS)
 *   - generateCSRFToken()   → สร้าง CSRF token
 *   - validateCSRFToken()   → ตรวจ CSRF token (hash_equals)
 *   - startSession()        → secure session + inactivity timeout
 *   - checkRateLimit()      → ป้องกัน brute force (DB-based)
 * 🔒 Access Control:
 *   - requireLogin/Staff/Admin() → redirect ถ้าไม่มีสิทธิ์
 *   - requireStaffApi/AdminApi() → JSON 403
 * 📦 Flash Messages:
 *   - setFlash() / getFlash() / displayFlash()
 * ✅ Validation (Single Source of Truth):
 *   - validateMemberData() → ตรวจข้อมูลสมาชิก
 *   - validatePassword()   → ตรวจรหัสผ่าน
 *   - hashPassword()       → hash password
 * 🌐 UI Helpers:
 *   - formatDate(), formatFine(), daysDiff()
 *   - getBookStatusLabel(), getBorrowStatusLabel(), getReservationStatusLabel()
 *
 * ⚠️ ห้ามแก้:
 * - e() — ป้องกัน XSS ทั้งระบบ
 * - generateCSRFToken() / validateCSRFToken() — ป้องกัน CSRF
 * - hashPassword() — Single Source of Truth สำหรับ hash
 */

require_once __DIR__ . '/config.php';

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ป้องกัน XSS โดยแปลง HTML entities
 * ==========================================================================
 * ⚠️ ต้องใช้ทุกครั้งที่แสดงผลข้อมูลจาก user/database บน HTML
 *
 * 📥 Input: @param string|null $string
 * 📤 Output: @return string escaped string
 * ✅ Use case: echo e($user['name']);
 */
function e(?string $string): string
{
    // 📝 แปลง < > " ' & เป็น HTML entities
    //    ENT_QUOTES = แปลงทั้ง single + double quotes
    //    null → '' (ป้องกัน error จาก DB null)
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: แปลงข้อความเป็น JS string literal ที่ปลอดภัยใน HTML attribute
 * ==========================================================================
 *
 * 📥 Input: @param string $text ข้อความดิบ (ชื่อหนังสือ ชื่อคน ฯลฯ จาก DB)
 * 📤 Output: @return string รวมเครื่องหมายคำพูดมาให้แล้ว — **ห้ามใส่ '' ครอบซ้ำ**
 *
 * 🔴 [F-47] ทำไมต้องมีตัวนี้: ข้อความในกล่องยืนยันอยู่ใน **JS string ซ้อนใน HTML attribute**
 *    = ต้อง escape สองชั้น ชื่อหนังสือที่มี ' หรือ " จะทำให้ปุ่มพัง**เงียบ ๆ**
 *    (กดแล้วไม่มีอะไรเกิดขึ้น ไม่มี error ให้เห็น เจ้าหน้าที่จะนึกว่าปุ่มเสีย)
 *
 * 🧠 ทำไมไม่ใช้ addslashes() ที่โค้ดเดิมใช้: มันไม่จัดการ backslash กับขึ้นบรรทัดใหม่
 *    ชื่อเรื่องที่มี \ จะทำให้ JS string พังต่อ · json_encode ครอบคลุมครบทุกกรณี
 *    รวมถึง unicode ไทย (ใส่ JSON_UNESCAPED_UNICODE ให้อ่านรู้เรื่องตอน view source)
 *
 * 🧠 ลำดับที่ถูกต้อง: json_encode ก่อน (ชั้น JS) → e() ทีหลัง (ชั้น HTML)
 *    เบราว์เซอร์ถอด entity ให้ตอน parse attribute แล้วค่อยส่งข้อความจริงให้ JS
 *
 * ✅ Use case: <form onsubmit="return confirmSubmit(this, <?= jsString($msg) ?>, {...})">
 */
/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ตรวจว่า "โฟลเดอร์นี้ web server เขียนได้จริงหรือเปล่า" — F-54
 * ==========================================================================
 * 📥 Input: @param string $dir path เต็มของโฟลเดอร์
 * 📤 Output: @return bool
 *
 * 🔴 **ทำไมไม่ใช้ is_writable() เฉย ๆ**
 *    บน macOS ที่ให้สิทธิ์ด้วย ACL (`chmod +a "daemon allow ..."`) `is_writable()`
 *    ดูจาก permission bits เป็นหลัก จึงรายงานผลไม่ตรงกับความจริงได้ทั้งสองทาง
 *    — ซึ่งเป็นสภาพแวดล้อมของคู่มือติดตั้งบน macOS พอดี (ดู INSTALL.md:261)
 *    วิธีที่เชื่อได้คือ **ลองเขียนไฟล์จริงแล้วลบทิ้ง**
 *
 * 🧹 ไฟล์ทดสอบถูกลบเสมอ แม้เขียนสำเร็จ — ใช้ชื่อที่ชนกับของลูกค้าไม่ได้
 *
 * ✅ Use case: install.php (เตือนตอนติดตั้ง) · admin/book_form.php (บอกสาเหตุจริง)
 *    🧠 ทั้งสองที่ใช้ตัวเดียวกัน เพื่อไม่ให้ตัดสินคนละแบบ
 */
function isDirActuallyWritable(string $dir): bool
{
    if (!is_dir($dir)) return false;

    $dir = rtrim($dir, '/');

    // 🧹 เก็บกวาดไฟล์ทดสอบที่อาจค้างจากรอบก่อน
    //    ถ้า process ตายระหว่างเขียนกับลบ (timeout / fatal) ไฟล์จะค้างในโฟลเดอร์ลูกค้า
    //    ไฟล์เล็กและซ่อนอยู่ก็จริง แต่ไม่ควรทิ้งขยะไว้ในโฟลเดอร์ของคนอื่น
    foreach (glob($dir . '/.write_probe_*') ?: [] as $stale) {
        @unlink($stale);
    }

    $probe = $dir . '/.write_probe_' . bin2hex(random_bytes(6));
    $ok = @file_put_contents($probe, 'x') !== false;
    if ($ok) {
        @unlink($probe);
    }
    return $ok;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: คำสั่งที่ต้องรันเมื่อโฟลเดอร์เขียนไม่ได้ — F-54
 * ==========================================================================
 * 🧠 คัดจาก `docs/INSTALL.md` มาไว้ที่เดียว เพื่อให้ข้อความบนหน้าจอ
 *    กับคู่มือไม่พูดคนละอย่างเมื่อมีใครแก้ที่ใดที่หนึ่ง
 * 📤 Output: @return string คำสั่งสำหรับ OS ที่กำลังรันอยู่
 */
function writablePermissionHint(string $relativeDir): string
{
    // 🔴 ต้องเป็น user ที่ **web server รันอยู่** ไม่ใช่เจ้าของไฟล์
    //    get_current_user() คืนเจ้าของสคริปต์ ซึ่งมักเป็นคนละคนกับ web server
    //    (บนเครื่องนี้: ไฟล์เป็นของ pruettipong แต่ Apache รันเป็น daemon)
    //    ใส่ชื่อผิด = ลูกค้าคัดคำสั่งไปรันแล้วยังเขียนไม่ได้เหมือนเดิม แต่คิดว่าทำแล้ว
    $webUser = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
        ? (posix_getpwuid(posix_geteuid())['name'] ?? '')
        : '';

    if (PHP_OS_FAMILY === 'Darwin') {
        // ถ้าหาชื่อ user ไม่ได้ ให้ใส่ตัวยึดที่เห็นชัดว่าต้องแทนที่ ไม่ใช่ปล่อยว่าง
        $user = $webUser !== '' ? $webUser : '<user-ของ-web-server>';
        return 'chmod +a "' . $user . ' allow add_file,delete,add_subdirectory,'
            . 'delete_child,file_inherit,directory_inherit" ' . $relativeDir;
    }

    $user = $webUser !== '' ? $webUser : 'www-data';
    return 'sudo chown -R ' . $user . ' ' . $relativeDir . ' && chmod 755 ' . $relativeDir;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: นับความยาวข้อความแบบ "กี่ตัวที่กินความกว้างจริง"
 * ==========================================================================
 *
 * 🔴 [UAT รอบ 2] บัตรสมาชิกย่อฟอนต์ชื่อยาวเหลือ 10.5px ทั้งที่พื้นที่ยังเหลือ
 *    เพราะใช้ mb_strlen() ตัดสิน ซึ่งนับ **สระบน-ล่างและวรรณยุกต์เป็นตัวอักษรด้วย**
 *    แต่อักขระพวกนี้ซ้อนอยู่บนพยัญชนะ ไม่กินความกว้างแนวนอนเลย
 *
 *    ตัวอย่างจริง: "เด็กหญิงพิมพ์ณดาภรณ์ชนกนันท์ ศรีสมบัติวัฒนโรจน์ประเสริฐ"
 *    mb_strlen นับได้ 55 → เข้าเกณฑ์ "ยาวมาก" → ย่อเหลือ 10.5px
 *    แต่วัดจริงกว้างเท่ากับ 42 ตัว → ใส่ที่ 14px ยังพอสบาย ๆ
 *
 * 🧠 เซตอักขระซ้อน (zero-advance) ของภาษาไทย:
 *      U+0E31        ั
 *      U+0E34–U+0E3A ิ ี ึ ื ุ ู ฺ
 *      U+0E47–U+0E4E ็ ่ ้ ๊ ๋ ์ ํ ๎
 *    ⚠️ **ไม่รวม ำ (U+0E33)** — ตัวนี้กินความกว้างจริง (เป็นนิคหิต + สระอา)
 *       และไม่รวม ะ (U+0E30) กับ า (U+0E32) ซึ่งเป็นสระหลังเต็มตัว
 *
 * 📥 Input: @param string $text ข้อความ (ไทย/ละติน/ผสม)
 * 📤 Output: @return int จำนวนอักขระที่กินความกว้าง
 * ✅ Use case: admin/member_card.php เลือกขนาดฟอนต์ชื่อบนบัตร
 */
function displayNameLength(string $text): int
{
    $stripped = preg_replace('/[\x{0E31}\x{0E34}-\x{0E3A}\x{0E47}-\x{0E4E}]/u', '', $text);

    // 🛡️ preg_replace คืน null ได้ถ้าเจอ UTF-8 พัง — ถอยไปนับแบบเดิมดีกว่าคืน 0
    //    (คืน 0 จะทำให้ชื่อยาวได้ฟอนต์ใหญ่สุดแล้วล้นบัตร ซึ่งแย่กว่าย่อเกินจำเป็น)
    return mb_strlen($stripped ?? $text, 'UTF-8');
}

function jsString(?string $text): string
{
    return e(json_encode($text ?? '', JSON_UNESCAPED_UNICODE));
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: redirect + exit ทันที
 * ==========================================================================
 *
 * 📥 Input: @param string $url (APP_URL prefix)
 * @return never (exit เสมอ)
 * ✅ Use case: redirect(APP_URL . '/login.php');
 */
function redirect(string $url): void
{
    // 📝 ส่ง HTTP 302 redirect + exit ทันที
    //    ⚠️ ต้อง exit เสมอ! ไม่งั้น code ด้านล่างยังทำงานต่อ
    header("Location: $url");
    exit;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ตั้ง flash message (แสดงครั้งเดียวแล้วหายไป)
 * ==========================================================================
 *
 * 📥 Input: @param string $type (success|error|warning|info), @param string $message, @param bool $isHtml
 * ✅ Use case: setFlash('success', 'บันทึกสำเร็จ'); redirect('list.php');
 */
function setFlash(string $type, string $message, bool $isHtml = false): void
{
    // 📝 เก็บใน session → ดึงออกครั้งเดียวโดย getFlash()
    //    isHtml=true → ไม่ escape (ใช้เมื่อส่ง HTML จาก server โดยตรง)
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
        'isHtml' => $isHtml
    ];
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ดึง flash message + ลบออกจาก session (แสดงได้ครั้งเดียว)
 * ==========================================================================
 *
 * 📤 Output: @return array|null {type, message, isHtml} หรือ null
 */
function getFlash(): ?array
{
    if (isset($_SESSION['flash'])) {
        // 📝 ดึง + ลบ → แสดงได้ครั้งเดียว (refresh ไม่เห็นซ้ำ)
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: แสดง flash message เป็น HTML (Tailwind badge)
 * ==========================================================================
 */
function displayFlash(): void
{
    $flash = getFlash();
    if ($flash) {
        $type = $flash['type'];
        // 📝 Map type → Tailwind color class
        $colorClass = match($type) {
            'error', 'danger' => 'bg-red-50 text-red-700 border-red-200',
            'success' => 'bg-green-50 text-green-700 border-green-200',
            'warning' => 'bg-amber-50 text-amber-700 border-amber-200',
            default => 'bg-blue-50 text-blue-700 border-blue-200'
        };
        
        // 📝 Map type → Bootstrap Icons class
        $icon = match($type) {
            'error', 'danger' => 'bi-exclamation-circle-fill',
            'success' => 'bi-check-circle-fill',
            'warning' => 'bi-exclamation-triangle-fill',
            default => 'bi-info-circle-fill'
        };

        echo '<div class="' . $colorClass . ' border-l-4 p-3 sm:p-4 mb-4 sm:mb-6 rounded-r-lg shadow-sm flex items-start gap-2 sm:gap-3 relative overflow-hidden" role="alert">';
        echo '<div class="flex-shrink-0 mt-0.5"><i class="bi ' . $icon . '"></i></div>';
        
        // 📝 isHtml=true → ไม่ escape (เชื่อ server), false → escape (เชื่อ user)
        $content = $flash['isHtml'] ? $flash['message'] : e($flash['message']);
        
        echo '<div class="flex-grow min-w-0 text-sm sm:text-base break-words">' . $content . '</div>';
        echo '<button type="button" class="flex-shrink-0 rounded-lg p-1 inline-flex items-center justify-center h-6 w-6 sm:h-8 sm:w-8 hover:bg-white/25" onclick="this.parentElement.remove()">';
        echo '<span class="sr-only">Close</span>';
        echo '<i class="bi bi-x text-lg"></i>';
        echo '</button>';
        echo '</div>';
    }
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ตรวจสอบ login status
 * ==========================================================================
 *
 * 📤 Output: @return bool
 * 🛡️ Security: ตรวจจาก session เท่านั้น (ไม่เชื่อ cookie/header อื่น)
 */
function isLoggedIn(): bool
{
    // 📝 เช็ค session เท่านั้น — ถ้ามี user_id = login แล้ว
    return isset($_SESSION['user_id']);
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ตรวจว่าเป็น admin
 * ==========================================================================
 * 🛡️ Security: role มาจาก DB ตอน login (ไม่ใช่ user input)
 */
function isAdmin(): bool
{
    // 📝 role มาจาก DB ตอน login (เก็บใน session)
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ตรวจว่าเป็นเจ้าหน้าที่ (admin หรือ staff)
 * ==========================================================================
 */
function isStaff(): bool
{
    // 📝 admin + staff = เจ้าหน้าที่ (admin ก็เป็น staff ด้วย)
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'staff']);
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ผู้ใช้คนนี้ต้องเปลี่ยนรหัสผ่านก่อนใช้งานหรือยัง (F-53)
 * ==========================================================================
 * 🧠 อ่านจาก session ที่ตั้งไว้ตอน login — ไม่ยิง DB ทุก request
 *    (ด่านนี้ถูกเรียกทุกหน้า ถ้ายิง DB จะเพิ่ม query ให้ทั้งระบบโดยไม่จำเป็น)
 *
 * ⚠️ ผลข้างเคียงที่ยอมรับ: คนที่ล็อกอินค้างไว้ *ก่อน* migration รัน จะไม่มีธงใน session
 *    จึงยังใช้งานได้จนกว่าจะ logout — ล็อกอินรอบหน้าถึงจะโดนบังคับ
 *    ไม่ใช่ช่องโหว่ เพราะคนนั้นรู้รหัสผ่านอยู่แล้วและกำลังใช้ session ของตัวเอง
 */
function mustChangePassword(): bool
{
    return !empty($_SESSION['must_change_password']);
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: บังคับ login (ถ้ายังไม่ login → redirect login.php)
 * ==========================================================================
 * ✅ Use case: ใส่บรรทัดแรกของหน้าที่ต้อง login
 *
 * 📥 @param bool $enforcePasswordChange
 *    true  (default) = ถ้ายังไม่เปลี่ยนรหัสเริ่มต้น ให้เด้งไปหน้าเปลี่ยนรหัส
 *    false = ยกเว้นด่านนี้ — ใช้ได้ที่ `change_password.php` **ที่เดียว**
 *            ถ้าหน้านั้นบังคับด้วย จะเด้งหาตัวเองไม่รู้จบ
 */
function requireLogin(bool $enforcePasswordChange = true): void
{
    // 📝 ถ้ายังไม่ login → flash error + redirect ไป login.php
    if (!isLoggedIn()) {
        setFlash('error', 'กรุณาเข้าสู่ระบบก่อน');
        redirect(APP_URL . '/login.php');
    }

    // 🔑 [F-53] ยังใช้รหัสเริ่มต้นที่คนอื่นก็รู้ → ทำอะไรไม่ได้จนกว่าจะเปลี่ยน
    //    🧠 แขวนไว้ที่นี่จุดเดียวเพราะเป็นคอขวดของทุกหน้าที่ต้องล็อกอิน (21 หน้า)
    //       ถ้าไปใส่ทีละหน้า วันหลังมีหน้าใหม่แล้วลืมใส่ = ช่องโหว่เงียบ ๆ
    if ($enforcePasswordChange && mustChangePassword()) {
        redirect(APP_URL . '/change_password.php');
    }
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: บังคับ admin (ไม่ใช่ admin → redirect index.php)
 * ==========================================================================
 * ✅ Use case: หน้า admin-only เช่น settings, reports
 */
function requireAdmin(): void
{
    // 📝 chain: ตรวจ login ก่อน → ตรวจ admin
    requireLogin();
    if (!isAdmin()) {
        setFlash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้ (สำหรับผู้ดูแลระบบเท่านั้น)');
        redirect(APP_URL . '/index.php');
    }
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: บังคับ staff (ไม่ใช่ staff → redirect index.php)
 * ==========================================================================
 * ✅ Use case: หน้า staff เช่น books, borrows
 */
function requireStaff(): void
{
    // 📝 chain: ตรวจ login ก่อน → ตรวจ staff
    requireLogin();
    if (!isStaff()) {
        setFlash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้ (สำหรับเจ้าหน้าที่เท่านั้น)');
        redirect(APP_URL . '/index.php');
    }
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: บังคับ staff สำหรับ API (JSON 403 แทน redirect)
 * ==========================================================================
 */
function requireStaffApi(): void
{
    // 📝 สำหรับ API: ไม่ redirect แต่คืน JSON 403 + exit
    if (!isLoggedIn() || !isStaff()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    requirePasswordChangedApi();
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ด่าน "ต้องเปลี่ยนรหัสก่อน" สำหรับ API (JSON 403 แทน redirect)
 * ==========================================================================
 * 🔴 [F-53] จำเป็นต้องมีแยกต่างหาก เพราะ endpoint ใน `api/` **ไม่ได้เรียก
 *    requireLogin()** — มันเช็ค `isLoggedIn()` เองบ้าง เรียก requireStaffApi() บ้าง
 *    ถ้ากันแค่หน้าเว็บ คนที่ยึดบัญชีด้วยรหัสเริ่มต้นจะยังยิง API ตรง ๆ ได้
 *    (จองหนังสือ / ยกเลิกการจอง ในนามเจ้าของบัญชี) ทั้งที่หน้าเว็บเด้งเขาออกไปแล้ว
 */
function requirePasswordChangedApi(): void
{
    if (mustChangePassword()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'กรุณาเปลี่ยนรหัสผ่านก่อนใช้งาน'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: บังคับ admin สำหรับ API (JSON 403 แทน redirect)
 * ==========================================================================
 */
function requireAdminApi(): void
{
    // 📝 สำหรับ API: ไม่ redirect แต่คืน JSON 403 + exit
    if (!isLoggedIn() || !isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    requirePasswordChangedApi();
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ดึงข้อมูลผู้ใช้ปัจจุบันจาก DB
 * ==========================================================================
 *
 * 📤 Output: @return array|null {id, name, email, phone, role} หรือ null
 * ⚠️ Query DB ทุกครั้ง (ไม่ cache) — ถ้าต้องการแค่ id/role ใช้ $_SESSION แทน
 */
function getCurrentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }
    
    // 📝 Query DB ทุกครั้งที่เรียก (ไม่ cache)
    //    ⚠️ ถ้าต้องการแค่ id/role → ใช้ $_SESSION แทน (เร็วกว่า)
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/../app/Repositories/UserRepository.php';
    
    $userRepo = new \App\Repositories\UserRepository(getDB());
    return $userRepo->findById($_SESSION['user_id']);
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ดึงค่า setting จาก DB (pass-through ไป SettingsRepository)
 * ==========================================================================
 * ✅ Use case: getSetting('org_name', 'ห้องสมุด');
 */
function getSetting($key, $default = '') {
    // 📝 Pass-through → SettingsRepository::get() (key-value store)
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/../app/Repositories/SettingsRepository.php';
    
    $settingsRepo = new \App\Repositories\SettingsRepository(getDB());
    return $settingsRepo->get($key, $default);
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: บันทึกค่า setting ลง DB (upsert ผ่าน SettingsRepository)
 * ==========================================================================
 */
function updateSetting($key, $value) {
    // 📝 Pass-through → SettingsRepository::set() (upsert: ON DUPLICATE KEY UPDATE)
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/../app/Repositories/SettingsRepository.php';
    
    $settingsRepo = new \App\Repositories\SettingsRepository(getDB());
    return $settingsRepo->set($key, $value);
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: จัดรูปแบบวันที่ (default: d/m/Y สำหรับไทย)
 * ==========================================================================
 * ✅ Use case: formatDate('2024-01-15') → "15/01/2024"
 */
function formatDate(?string $date, string $format = 'd/m/Y'): string
{
    // 📝 null/empty → แสดง '-' แทน (ป้องกัน error)
    if (!$date) {
        return '-';
    }
    // 📝 แปลง string → timestamp → format
    return date($format, strtotime($date));
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: คำนวณจำนวนวันระหว่าง 2 วันที่
 * ==========================================================================
 * ✅ Use case: daysDiff('2024-01-01', '2024-01-15') → 14
 */
function daysDiff(string $date1, string $date2): int
{
    $d1 = new DateTime($date1);
    $d2 = new DateTime($date2);
    // 📝 %r = เครื่องหมาย +/- , %a = จำนวนวัน
    //    date2 > date1 → บวก, date2 < date1 → ลบ
    return (int) $d1->diff($d2)->format('%r%a');
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: สร้าง badge สถานะหนังสือ (Tailwind HTML)
 * ==========================================================================
 */
function getBookStatusLabel(string $status): string
{
    return match($status) {
        'available' => '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"><span class="w-1.5 h-1.5 bg-green-500 rounded-full mr-1.5"></span>ว่าง</span>',
        'borrowed' => '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">ถูกยืม</span>',
        default => '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">ไม่ทราบ</span>'
    };
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: สร้าง badge สถานะการยืม + เช็ค overdue (Tailwind HTML)
 * ==========================================================================
 */
function getBorrowStatusLabel(string $status, ?string $dueDate = null): string
{
    if ($status === 'returned') {
        return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"><i class="bi bi-check-circle-fill mr-1"></i>คืนแล้ว</span>';
    }

    // 📚 หาย/ชำรุด — ปิดรายการแล้วเหมือนกัน แต่หนังสือไม่ได้กลับเข้าชั้น
    //    ต้องมาก่อนตัวเช็คเกินกำหนด ไม่งั้นเล่มที่หายตอนเลยกำหนดจะขึ้นว่า "เกินกำหนด"
    if ($status === 'lost') {
        return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800"><i class="bi bi-question-octagon-fill mr-1"></i>แจ้งหาย</span>';
    }
    if ($status === 'damaged') {
        return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800"><i class="bi bi-bandaid-fill mr-1"></i>ชำรุด</span>';
    }
    
    if ($dueDate && strtotime($dueDate) < strtotime('today')) {
        return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800"><i class="bi bi-exclamation-circle-fill mr-1"></i>เกินกำหนด</span>';
    }
    
    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800"><i class="bi bi-clock-fill mr-1"></i>กำลังยืม</span>';
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: สร้าง badge สถานะการจอง (Tailwind HTML)
 * ==========================================================================
 */
function getReservationStatusLabel(string $status): string
{
    return match($status) {
        // 🔄 waiting = ต่อคิวรอ ยังไม่ได้ของ · pending = ของพร้อมแล้ว รอมารับ — คนละเรื่องกัน
        'waiting' => '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800"><i class="bi bi-people-fill mr-1"></i>ต่อคิวรอ</span>',
        // 🔤 [F-46] ใช้คำเดียวกันทุกหน้า — เดิมเรียก 3 ชื่อ (รออนุมัติ / รอรับของ / รอดำเนินการ)
        //    "รอรับของ" ไม่ใช่คำที่ห้องสมุดใช้ — ห้องสมุดไม่มี "ของ" มีแต่หนังสือ
        'pending' => '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800"><i class="bi bi-hourglass-split mr-1"></i>รอมารับ</span>',
        'fulfilled' => '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"><i class="bi bi-check-circle-fill mr-1"></i>รับแล้ว</span>',
        'expired' => '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800"><i class="bi bi-x-circle-fill mr-1"></i>หมดอายุ</span>',
        'cancelled' => '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800"><i class="bi bi-x-circle-fill mr-1"></i>ยกเลิก</span>',
        default => '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-50 text-gray-600">ไม่ทราบ</span>'
    };
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ตรวจรูปแบบ email (FILTER_VALIDATE_EMAIL)
 * ==========================================================================
 */
function isValidEmail(string $email): bool
{
    // 📝 ใช้ PHP built-in filter (RFC 5322)
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ตรวจเบอร์โทรไทย (ตัวเลข 9-10 หลัก)
 * ==========================================================================
 */
function isValidPhone(string $phone): bool
{
    // 📝 เบอร์ไทย: 9-10 หลัก ตัวเลขล้วน (เช่น 0812345678)
    return preg_match('/^[0-9]{9,10}$/', $phone);
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ตรวจชื่อ (Single Source of Truth)
 * ==========================================================================
 * 📤 Output: @return string|null (null = valid)
 */
function validateName(string $name, int $maxLen = 100): ?string
{
    if (empty(trim($name))) {
        return 'กรุณากรอกชื่อ';
    }
    if (mb_strlen($name) > $maxLen) {
        return "ชื่อต้องไม่เกิน $maxLen ตัวอักษร";
    }
    return null;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ตรวจความยาวสูงสุด (Single Source of Truth)
 * ==========================================================================
 * 📤 Output: @return string|null (null = valid)
 */
function validateMaxLength(string $value, int $max, string $fieldName): ?string
{
    if (mb_strlen($value) > $max) {
        return "{$fieldName}ต้องไม่เกิน {$max} ตัวอักษร";
    }
    return null;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ตรวจรหัสผ่าน (Single Source of Truth)
 * ==========================================================================
 * 📥 Input: @param string $password, @param bool $allowEmpty (edit mode)
 * 📤 Output: @return string|null (null = valid)
 */
function validatePassword(string $password, bool $allowEmpty = false): ?string
{
    if (!$allowEmpty && empty($password)) {
        return 'กรุณากรอกรหัสผ่าน';
    }
    if (!empty($password) && strlen($password) < MIN_PASSWORD_LENGTH) {
        return 'รหัสผ่านต้องมีอย่างน้อย ' . MIN_PASSWORD_LENGTH . ' ตัวอักษร';
    }
    return null;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ตรวจข้อมูลสมาชิก (Single Source of Truth)
 * ==========================================================================
 * รวม validation: name, email, phone, password ไว้ที่เดียว
 * ใช้ร่วมกันทั้ง register.php, member_form.php, MemberService, AuthService
 *
 * 📥 Input: @param array $data {name, email, phone?, password?}, @param bool $isEdit
 * 📤 Output: @return array error messages (empty = valid)
 */
function validateMemberData(array $data, bool $isEdit = false, bool $requireEmail = false): array
{
    $errors = [];

    // Name
    if (empty(trim($data['name'] ?? ''))) {
        $errors[] = 'กรุณากรอกชื่อ-นามสกุล';
    } elseif ($err = validateMaxLength($data['name'], 100, 'ชื่อ')) {
        $errors[] = $err;
    }

    // Email
    // 🧠 [UAT รอบ 2 ข้อ ฒ.2] เดิมบังคับกรอกเสมอ ทำให้สมาชิกที่ไม่มีอีเมลจริง
    //    (ผู้สูงอายุ เด็กเล็ก) สมัครไม่ได้เลย บรรณารักษ์ต้องกรอกอีเมลปลอมให้เอง
    //    เว้นว่างได้ → MemberService จะสร้างอีเมลภายในให้ (ดู INTERNAL_EMAIL_DOMAIN)
    //
    // 🔴 แต่ $requireEmail = true สำหรับ **การสมัครเองผ่านหน้าเว็บสาธารณะ**
    //    คนที่สมัครออนไลน์ใช้คอมอยู่แล้ว และถ้าไม่มีอีเมลเขาจะไม่รู้รหัสประจำตัว
    //    ที่ระบบตั้งให้ไว้ล็อกอินครั้งถัดไป — กลายเป็นบัญชีที่เจ้าตัวเข้าไม่ได้
    //    การเว้นอีเมลจึงใช้ได้เฉพาะตอนเจ้าหน้าที่กรอกให้ที่เคาน์เตอร์ (บอกรหัสให้ได้ทันที)
    //    (เจอตอนรันชุดทดสอบ: เคส VL-01 จับได้ว่าหน้าสมัครสาธารณะหลุด)
    $emailGiven = trim($data['email'] ?? '');
    if ($emailGiven === '') {
        if ($requireEmail) {
            $errors[] = 'กรุณากรอกอีเมล';
        }
    } elseif (!isValidEmail($emailGiven)) {
        $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
    }

    // Phone (optional)
    if (!empty($data['phone']) && !isValidPhone($data['phone'])) {
        $errors[] = 'เบอร์โทรต้องเป็นตัวเลข 9-10 หลัก';
    }

    // Password (skip if not provided in data)
    if (array_key_exists('password', $data)) {
        if ($err = validatePassword($data['password'], $isEdit)) {
            $errors[] = $err;
        }
    }

    return $errors;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ตรวจข้อมูลหนังสือ (Single Source of Truth)
 * ==========================================================================
 * รวม validation: title, author ไว้ที่เดียว
 * ใช้ร่วมกันทั้ง admin/book_form.php และ admin/import_books.php
 *
 * 📥 Input: @param array $data {title, author}
 * 📤 Output: @return array error messages (empty = valid)
 */
function validateBookData(array $data): array
{
    $errors = [];

    // Title
    if (empty(trim($data['title'] ?? ''))) {
        $errors[] = 'กรุณากรอกชื่อหนังสือ';
    } elseif (mb_strlen($data['title']) > 200) {
        $errors[] = 'ชื่อหนังสือต้องไม่เกิน 200 ตัวอักษร';
    }

    // Author
    if (empty(trim($data['author'] ?? ''))) {
        $errors[] = 'กรุณากรอกชื่อผู้แต่ง';
    } elseif (mb_strlen($data['author']) > 100) {
        $errors[] = 'ชื่อผู้แต่งต้องไม่เกิน 100 ตัวอักษร';
    }

    // ISBN — ไม่บังคับกรอก แต่ถ้ากรอกต้องไม่ยาวเกินคอลัมน์
    // 🧠 ทำไมต้องตรวจ: คอลัมน์เป็น VARCHAR(20) ถ้ายาวกว่านั้น MySQL จะโยน
    //    "SQLSTATE[22001] Data too long for column 'isbn'" ขึ้นหน้าจอผู้ใช้ตรง ๆ
    //    ซึ่งทั้งดูไม่เป็นมืออาชีพและเปิดเผยชื่อคอลัมน์/ชั้นฐานข้อมูลออกไป
    //    (เกิดจริงแม้ตั้ง APP_DEBUG=false)
    // ⚠️ ตรวจแค่ "ความยาว" ห้ามบังคับรูปแบบ 10/13 หลัก
    //    เพราะห้องสมุดจำนวนมากเก็บ ISBN แบบมีขีดคั่น เช่น 978-616-123-456-7 (17 ตัว)
    //    ถ้าบังคับรูปแบบ ข้อมูลที่ใช้งานอยู่จริงจะกรอกไม่ได้
    if (!empty($data['isbn']) && mb_strlen(trim($data['isbn'])) > 20) {
        $errors[] = 'ISBN ต้องไม่เกิน 20 ตัวอักษร';
    }

    return $errors;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: Hash password (Single Source of Truth)
 * ==========================================================================
 * ⚠️ ทุกที่ที่ต้อง hash ต้องเรียกผ่านฟังก์ชันนี้
 */
function hashPassword(string $plainPassword): string
{
    // 📝 PASSWORD_DEFAULT = bcrypt (ปัจจุบัน)
    //    PHP จะอัปเกรด algorithm เองเมื่อมีตัวใหม่ที่ดีกว่า
    //    ⚠️ ทุกที่ที่ต้อง hash ต้องเรียกผ่านฟังก์ชันนี้ (Single Source of Truth)
    return password_hash($plainPassword, PASSWORD_DEFAULT);
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: สร้าง CSRF token (per-session, cryptographically secure)
 * ==========================================================================
 *
 * 📤 Output: @return string 64 hex chars
 * 🛡️ Security: random_bytes(32), per-session token
 * ✅ Use case: <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
 */
function generateCSRFToken(): string
{
    // 📝 Per-session token — สร้างครั้งเดียวต่อ session
    //    ใช้ร่วมกันทุก form ในหน้าเดียวกัน (ไม่ต้อง per-form)
    //    🛡️ SameSite=Lax cookie ป้องกัน cross-origin POST เพิ่มอีกชั้น
    if (!isset($_SESSION['csrf_token'])) {
        // 📝 random_bytes(32) = 256-bit cryptographically secure random
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ตรวจ CSRF token (hash_equals ป้องกัน timing attack)
 * ==========================================================================
 * ⚠️ ห้ามใช้ == หรือ === เปรียบเทียบ token
 */
function validateCSRFToken(string $token): bool
{
    // 📝 hash_equals = timing-safe comparison
    //    ⚠️ ห้ามใช้ == หรือ === (เสี่ยง timing attack)
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: Start secure session + inactivity timeout
 * ==========================================================================
 * 🛡️ Security: HttpOnly, SameSite=Lax, Secure (HTTPS), inactivity timeout
 * ⚠️ ถูกเรียกอัตโนมัติท้าย functions.php (ไม่ต้องเรียกเอง)
 */
/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ชื่อ session ที่ไม่ซ้ำกับระบบอื่นบนโดเมนเดียวกัน
 * ==========================================================================
 * 🧠 ทำไมต้องแยกเป็นฟังก์ชัน: ชุดทดสอบ HTTP ต้องรู้ชื่อนี้เพื่ออ่าน cookie ที่ server ส่งมา
 *    ถ้าปล่อยให้สูตรอยู่ใน startSession() แล้วให้เทสต์คัดลอกสูตรไปเขียนเอง
 *    วันหนึ่งแก้ที่เดียวไม่ครบ เทสต์จะพังแบบงง ๆ (เคยเกิดมาแล้ว)
 *
 * 📤 Output: เช่น "BBSESS9f97785c" — ขึ้นต้นด้วยตัวอักษรเพราะชื่อ session ห้ามเป็นตัวเลขล้วน
 * 🧠 ผูกกับ path ของโฟลเดอร์ includes/ → แต่ละที่ติดตั้งได้ชื่อของตัวเองอัตโนมัติ
 *    ลูกค้าไม่ต้องตั้งค่าอะไรเพิ่ม
 */
function appSessionName(): string
{
    return 'BBSESS' . substr(md5(__DIR__), 0, 8);
}

function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // 🛡️ [SECURITY] ตั้งชื่อ session ให้ไม่ซ้ำกับระบบอื่นบนโดเมนเดียวกัน
        // 🧠 ทำไมต้องทำ: PHP ใช้ชื่อ `PHPSESSID` เป็นค่าเริ่มต้น + cookie path = '/'
        //    ถ้ามีระบบนี้ 2 ชุดบนโดเมนเดียวกัน (เช่น /library กับ /library-test)
        //    การ login ที่ชุดหนึ่งจะทำให้เข้าอีกชุดได้ทันที เพราะ $_SESSION['user_id']
        //    ถูกส่งต่อไป แล้วอีกชุดเอา id นั้นไปหาใน **ฐานข้อมูลของตัวเอง**
        //    (ทดสอบยืนยันแล้ว: login ที่ /book_borrowing → เปิด /bb_release_test/admin/ ได้ 200)
        //    ⚠️ ต้องเรียกก่อน session_start() เสมอ
        // 📌 ผูกกับ path ของไฟล์ → แต่ละที่ติดตั้งได้ชื่อของตัวเอง โดยไม่ต้องให้ลูกค้าตั้งค่าอะไร
        //    ขึ้นต้นด้วยตัวอักษรเพราะชื่อ session ห้ามเป็นตัวเลขล้วน
        session_name(appSessionName());

        // 🛡️ [SECURITY] ตั้งค่า session cookie ให้ปลอดภัย
        session_set_cookie_params([
            'lifetime' => 0, // 📝 Session cookie — ปิด browser = หมดอายุ
            'path' => '/',
            'domain' => '',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', // 📝 HTTPS เท่านั้น
            'httponly' => true,   // 📝 JavaScript อ่าน cookie ไม่ได้ (ป้องกัน XSS ขโมย session)
            'samesite' => 'Lax'  // 📝 ป้องกัน CSRF บางส่วน (cross-site POST ไม่ส่ง cookie)
        ]);
        session_start();
    }
    
    // 🛡️ [SECURITY] Inactivity timeout
    //    ป้องกัน session ค้างบน shared computer (เช่น คอมห้องสมุด)
    $timeout = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 3600;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
        // 📝 หมดอายุ → ล้าง session ทั้งหมด + เริ่มใหม่
        session_unset();
        session_destroy();
        session_start();
        return;
    }
    // 📝 อัปเดต timestamp ทุก request
    $_SESSION['last_activity'] = time();
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ตรวจ rate limit (DB-based)
 * ==========================================================================
 *
 * 📥 Input: @param string $key, @param int|null $maxAttempts, @param int|null $windowMinutes
 *           @param bool $appendIp  true = ต่อ IP ท้าย key (default, เหมาะกับ login)
 *                                  false = ใช้ key ตรงๆ (เหมาะกับ reserve ที่ key มี user_id แล้ว)
 * 📤 Output: @return bool true = ยังไม่เกิน limit
 * 🧠 เหตุผล: DB fail → allow (best-effort — ไม่ lock out ทุกคน)
 */
function checkRateLimit(string $key, ?int $maxAttempts = null, ?int $windowMinutes = null, bool $appendIp = true): bool
{
    // 📝 ใช้ default จาก config.php ถ้าไม่ระบุ
    $maxAttempts = $maxAttempts ?? RATE_LIMIT_MAX_ATTEMPTS;
    $windowMinutes = $windowMinutes ?? RATE_LIMIT_WINDOW_MINUTES;
    
    // 📝 สร้าง key สำหรับ rate limit
    //    $appendIp = true  → key + IP (default, เหมาะกับ login/register — จำกัดต่อ IP)
    //    $appendIp = false → key เท่านั้น (เหมาะกับ reserve — จำกัดต่อ user ไม่ว่า IP ไหน)
    //    🛡️ [SECURITY FIX] เพิ่ม $appendIp เพราะ reserve ใช้ user_id เป็น key
    //    แต่ระบบต่อ _IP ให้อัตโนมัติ → user เปลี่ยน IP ก็ bypass rate limit ได้
    $fullKey = $appendIp ? $key . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') : $key;
    
    try {
        $pdo = getDB();
        
        // 📝 ลบรายการหมดอายุ (ทำความสะอาด table)
        $stmt = $pdo->prepare("DELETE FROM rate_limits WHERE key_name = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)");
        $stmt->execute([$fullKey, $windowMinutes]);
        
        // 📝 นับรายการใน window
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE key_name = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)");
        $stmt->execute([$fullKey, $windowMinutes]);
        
        // 📤 true = ยังไม่เกิน limit, false = เกินแล้ว (บล็อก)
        return (int) $stmt->fetchColumn() < $maxAttempts;
    } catch (\Exception $e) {
        // 📝 Fallback: DB พัง → อนุญาตผ่าน (best-effort, ไม่ lock out ทุกคน)
        return true;
    }
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: เพิ่ม attempt counter (DB-based)
 * ==========================================================================
 */
function incrementRateLimit(string $key, bool $appendIp = true): void
{
    // 📝 เพิ่ม 1 record ใน rate_limits (บันทึก attempt)
    //    $appendIp ต้องตรงกับ checkRateLimit() ที่จับคู่กัน
    $fullKey = $appendIp ? $key . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') : $key;
    
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("INSERT INTO rate_limits (key_name) VALUES (?)");
        $stmt->execute([$fullKey]);
    } catch (\Exception $e) {
        // 📝 Silently fail — best-effort (ไม่พังแอป)
    }
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: Reset rate limit counter (เรียกหลัง success)
 * ==========================================================================
 */
function resetRateLimit(string $key, bool $appendIp = true): void
{
    // 📝 ลบ attempt ทั้งหมดของ key (เรียกหลัง login สำเร็จ)
    //    $appendIp ต้องตรงกับ checkRateLimit() ที่จับคู่กัน
    $fullKey = $appendIp ? $key . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') : $key;
    
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM rate_limits WHERE key_name = ?");
        $stmt->execute([$fullKey]);
    } catch (\Exception $e) {
        // 📝 Silently fail — best-effort
    }
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ล้าง idempotency keys หมดอายุ (session-based)
 * ==========================================================================
 * ✅ เรียกอัตโนมัติจาก bootstrap.php
 */
function cleanupIdempotencyKeys(int $maxAgeSeconds = 300): void
{
    // 📝 สร้าง array ถ้ายังไม่มี
    if (!isset($_SESSION['processed_actions'])) {
        $_SESSION['processed_actions'] = [];
        return;
    }
    
    // 📝 ลบ key ที่เกิน maxAge (default 5 นาที)
    //    ป้องกัน session บวมจาก key สะสม
    $now = time();
    foreach ($_SESSION['processed_actions'] as $key => $timestamp) {
        if ($now - $timestamp > $maxAgeSeconds) {
            unset($_SESSION['processed_actions'][$key]);
        }
    }
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ตรวจว่า exception นี้เกิดจาก deadlock/lock timeout ของ DB หรือไม่
 * ==========================================================================
 *
 * 📥 Input: @param \Throwable $e
 * 📤 Output: @return bool true = เป็น deadlock (ลองใหม่ได้)
 *
 * 🧠 MySQL/MariaDB คืน 2 กรณีที่ "ลองใหม่แล้วมีโอกาสสำเร็จ":
 *    - 1213 / SQLSTATE 40001 → Deadlock found
 *    - 1205 → Lock wait timeout exceeded
 *    ทั้งคู่ transaction ถูก rollback ไปแล้ว จึงเริ่มใหม่ได้อย่างปลอดภัย
 *
 * ⚠️ error อื่นของ DB (เช่น UNIQUE ซ้ำ, FK) ห้าม retry — ลองกี่ครั้งก็ผลเดิม
 */
function isDeadlockException(\Throwable $e): bool
{
    if (!($e instanceof \PDOException)) {
        return false;
    }

    // 📝 errorInfo[1] = driver error code (1213 / 1205), errorInfo[0] = SQLSTATE
    $driverCode = $e->errorInfo[1] ?? null;
    $sqlState   = $e->errorInfo[0] ?? ($e->getCode() ?: '');

    return in_array($driverCode, [1213, 1205], true) || $sqlState === '40001';
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: รัน operation ที่มี transaction แล้วลองใหม่อัตโนมัติเมื่อเจอ deadlock
 * ==========================================================================
 *
 * 📥 Input:
 * @param PDO      $pdo          connection เดียวกับที่ operation ใช้
 * @param callable $operation    ฟังก์ชันที่ครอบ beginTransaction...commit ไว้ครบในตัว
 * @param string   $context      ชื่อไว้เขียน log เช่น 'BorrowService::createBorrow'
 * @param int      $maxAttempts  ลองทั้งหมดกี่ครั้ง (รวมครั้งแรก)
 *
 * 📤 Output: @return mixed ค่าที่ operation คืนมา
 * @throws Exception ข้อความภาษาไทย เมื่อลองครบแล้วยังไม่สำเร็จ หรือเจอ DB error อื่น
 *
 * 🧠 ทำไมต้องมี:
 *    เมื่อเจ้าหน้าที่ 2 คนแย่งหนังสือเล่มสุดท้ายพร้อมกัน InnoDB จะเลือก transaction
 *    หนึ่งเป็น "เหยื่อ" แล้ว rollback ทิ้ง — เป็นพฤติกรรมปกติที่ MySQL คาดหวังให้ลองใหม่
 *    ไม่ใช่ข้อผิดพลาดของข้อมูล (วัดแล้วเกิดราว 25% ของการแย่งเล่มสุดท้าย — ดู FINDINGS F-20)
 *
 * 🛡️ เงื่อนไขความปลอดภัยที่ต้องรักษาไว้:
 *    - $operation ต้อง "ครบวงจรในตัวเอง" — เปิด/ปิด transaction เองทั้งหมด
 *      และห้ามมี side effect นอก transaction (เขียนไฟล์/ส่งเมล) ก่อน commit
 *      ไม่งั้นการลองใหม่จะทำซ้ำ side effect นั้น
 *    - retry เฉพาะ deadlock เท่านั้น — error ทางธุรกิจ (หนังสือหมด/เกินโควตา)
 *      ต้องเด้งออกทันที ไม่ลองใหม่
 *
 * 🛡️ [SECURITY] ไม่ปล่อยข้อความดิบของ DB ออกหน้าจอ
 *    (เดิม SQLSTATE[40001]: Serialization failure... โผล่ให้ผู้ใช้เห็นทั้งที่ APP_DEBUG=false)
 */
function runWithDeadlockRetry(PDO $pdo, callable $operation, string $context = '', int $maxAttempts = 3): mixed
{
    for ($attempt = 1; ; $attempt++) {
        try {
            return $operation();
        } catch (\PDOException $e) {
            // 🧹 กัน transaction ค้าง — บาง driver ยังถือว่า transaction เปิดอยู่หลัง deadlock
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (\Throwable $ignore) {
                    // rollback ซ้ำไม่สำเร็จก็ไม่เป็นไร — transaction ถูก DB ยกเลิกไปแล้ว
                }
            }

            if (!isDeadlockException($e)) {
                // 📝 error อื่นของ DB — log ของจริงไว้ แต่ส่งข้อความกลาง ๆ ให้ผู้ใช้
                error_log("[$context] PDOException: " . $e->getMessage());
                throw new Exception('เกิดข้อผิดพลาดกับฐานข้อมูล กรุณาลองใหม่อีกครั้ง');
            }

            if ($attempt >= $maxAttempts) {
                error_log("[$context] deadlock ยังไม่หายหลังลอง {$maxAttempts} ครั้ง: " . $e->getMessage());
                throw new Exception('ระบบกำลังมีผู้ใช้งานพร้อมกันจำนวนมาก กรุณาลองใหม่อีกครั้ง');
            }

            // 📝 หน่วงแบบเพิ่มขึ้นเรื่อย ๆ + สุ่ม เพื่อไม่ให้ทั้งสองฝั่งชนกันซ้ำที่จังหวะเดิม
            usleep($attempt * 20000 + random_int(0, 20000));
            error_log("[$context] deadlock — ลองใหม่ครั้งที่ " . ($attempt + 1));
        }
    }
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: จัดรูปแบบค่าปรับ (บาท)
 * ==========================================================================
 * ✅ Use case: formatFine(150) → "150 บาท"
 */
function formatFine(float $amount): string
{
    // 📝 ไม่มีค่าปรับ → '-'
    if ($amount <= 0) {
        return '-';
    }
    // 📝 format พร้อมหน่วยบาท
    return number_format($amount) . ' บาท';
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: คำนวณข้อมูลการแบ่งหน้า (ไม่เกี่ยวกับ HTML)
 * ==========================================================================
 * 🧠 ทำไมต้องมี: 4 หน้าที่แบ่งหน้าต้องคำนวณเหมือนกันหมด
 *    (หน้าปัจจุบันเกินช่วงไหม / มีกี่หน้า / แสดงเลขหน้าไหนบ้าง)
 *    ถ้าปล่อยให้แต่ละ View คิดเอง จะเพี้ยนกันคนละแบบ
 *
 * 📥 Input:  $total   จำนวนรายการทั้งหมด (จาก countXxx() ของ Repository)
 *            $page    หน้าที่ผู้ใช้ขอมา (รับมาจาก $_GET ดิบ ๆ ได้เลย)
 *            $perPage จำนวนต่อหน้า
 * 📤 Output: ['page','per_page','total','total_pages','offset','from','to','pages']
 *            - offset  → ส่งต่อให้ Repository ใช้ใน SQL
 *            - from/to → "แสดง 21–40 จาก 137" และใช้เป็นเลขลำดับแถวในตาราง
 *            - pages   → เลขหน้าที่จะแสดงเป็นปุ่ม (null = จุดไข่ปลา)
 *
 * 🛡️ [SECURITY] cast เป็น int + clamp ทุกค่า → ค่าที่ออกไปประกอบ SQL ปลอดภัยเสมอ
 *    ("?page=abc" → 1 · "?page=-5" → 1 · "?page=9999" ทั้งที่มี 3 หน้า → 3)
 */
function paginate($total, $page, int $perPage): array
{
    $total   = max(0, (int) $total);
    $perPage = max(1, $perPage);

    // 📝 หน้าทั้งหมด — ไม่มีข้อมูลเลยก็ยังนับเป็น 1 หน้า (จะได้แสดง empty state)
    $totalPages = max(1, (int) ceil($total / $perPage));

    // 🛡️ clamp หน้าให้อยู่ในช่วงจริงเสมอ
    $page   = max(1, min($totalPages, (int) $page));
    $offset = ($page - 1) * $perPage;

    // 📝 ช่วงลำดับที่แสดงอยู่ (ใช้ทั้งข้อความสรุปและเลขแถวในตาราง)
    $from = $total === 0 ? 0 : $offset + 1;
    $to   = min($total, $offset + $perPage);

    return [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $totalPages,
        'offset'      => $offset,
        'from'        => $from,
        'to'          => $to,
        'pages'       => paginationPageNumbers($page, $totalPages),
    ];
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: เลือกเลขหน้าที่จะแสดงเป็นปุ่ม (ย่อด้วย ... เมื่อหน้าเยอะ)
 * ==========================================================================
 * ✅ ตัวอย่าง 10 หน้า อยู่หน้า 6 → [1, null, 5, 6, 7, null, 10]
 *    (null = จุดไข่ปลา)
 * 🧠 แยกออกมาเป็นฟังก์ชันของตัวเองเพราะเป็นตรรกะล้วน ๆ ทดสอบง่าย
 */
function paginationPageNumbers(int $current, int $totalPages, int $around = 1): array
{
    // 📝 หน้าน้อย (≤ 7) → แสดงครบทุกหน้า ไม่ต้องย่อ
    if ($totalPages <= 7) {
        return range(1, $totalPages);
    }

    // 📝 เก็บหน้าแรก หน้าสุดท้าย และหน้ารอบ ๆ หน้าปัจจุบัน
    $keep = [1, $totalPages];
    for ($p = $current - $around; $p <= $current + $around; $p++) {
        if ($p >= 1 && $p <= $totalPages) {
            $keep[] = $p;
        }
    }
    $keep = array_values(array_unique($keep));
    sort($keep);

    // 📝 แทรก null ตรงช่วงที่ข้ามไป
    $out  = [];
    $prev = 0;
    foreach ($keep as $p) {
        if ($prev && $p - $prev > 1) {
            $out[] = null;
        }
        $out[] = $p;
        $prev  = $p;
    }
    return $out;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: เก็บ "สถานะรายการ" (หน้า + ตัวกรอง) ไว้พากลับหลังบันทึก — F-37
 * ==========================================================================
 * เดิมทุกการบันทึกจะเด้งกลับหน้าแรกของรายการ ตัวกรองหายหมด
 * เคลียร์รายการเกินกำหนด 26 รายการ = ต้องกดกรองใหม่ 26 รอบ
 *
 * 🛡️ **[SECURITY] ไม่รับ URL จากผู้ใช้เด็ดขาด**
 *    การพา "ที่อยู่สำหรับกลับ" มาจากฝั่งผู้ใช้คือช่องทางคลาสสิกของ open redirect
 *    ฟังก์ชันนี้รับเฉพาะ **ค่าของพารามิเตอร์ที่อยู่ใน whitelist** แล้วประกอบ URL
 *    ขึ้นใหม่ที่ฝั่งเซิร์ฟเวอร์ ปลายทางเป็นชื่อไฟล์ที่ hardcode ไว้ในโค้ดเสมอ
 *    → ต่อให้ยัด `//evil.com` หรือ `https://...` เข้ามา ก็ออกนอกระบบไม่ได้
 *
 * 📥 Input:
 * @param array      $allowed ชื่อพารามิเตอร์ที่ยอมให้พากลับ เช่น ['page','search','filter']
 * @param array|null $source  แหล่งข้อมูล (default: $_GET) — ส่ง $_POST มาได้ตอนอ่านจาก hidden field
 * @param string     $prefix  คำนำหน้าใน $source เช่น 'ret_' (ใช้ตอนส่งผ่านหน้าฟอร์ม)
 *
 * 📤 Output: @return array คู่ key/value ที่ผ่านการกรองแล้ว
 */
function listState(array $allowed, ?array $source = null, string $prefix = ''): array
{
    $source = $source ?? $_GET;
    $out = [];

    foreach ($allowed as $key) {
        $srcKey = $prefix . $key;
        if (!isset($source[$srcKey])) {
            continue;
        }
        $val = $source[$srcKey];

        // 🛡️ รับเฉพาะค่าเดี่ยว — array/object ทิ้งทันที (กันการยัด structure แปลก ๆ)
        if (!is_scalar($val)) {
            continue;
        }
        $val = trim((string) $val);
        if ($val === '') {
            continue;
        }

        // 🛡️ พารามิเตอร์ที่เป็น "เลขหน้า" ต้องเป็นตัวเลขบวกเท่านั้น
        //    ไม่งั้นค่าขยะอย่าง `//evil.com` จะหลุดเข้าไปอยู่ใน URL ของเราเอง
        //    (ยังออกนอกระบบไม่ได้เพราะ path เป็นค่าคงที่ในโค้ด แต่ URL ไม่ควรมีของแบบนี้)
        if (in_array($key, ['page', 'upage'], true)) {
            if (!ctype_digit($val) || (int) $val < 1) {
                continue;
            }
        }

        // 🛡️ ตัดความยาวกันคนยัดข้อความยาวมากเพื่อทำให้ URL บวม
        if (mb_strlen($val) > 200) {
            $val = mb_substr($val, 0, 200);
        }
        $out[$key] = $val;
    }

    return $out;
}

/**
 * 🎯 แปลงสถานะรายการเป็น query string พร้อมต่อท้าย URL ('' ถ้าไม่มีอะไร)
 *
 * ✅ Use case: <a href="book_form.php?id=5<?= listStateSuffix(BOOKS_LIST_STATE, null, 'ret_') ?>">
 */
function listStateQuery(array $state): string
{
    return $state ? '?' . http_build_query($state) : '';
}


/**
 * 🎯 สร้างลิงก์ไปหน้าฟอร์ม พร้อมพาสถานะรายการไปด้วยในชื่อ ret_*
 *
 * 🧠 ทำไมต้องมี prefix — หน้าฟอร์มมีพารามิเตอร์ของตัวเอง (`id`) และอาจมี `page` ของมันเอง
 *    ถ้าไม่แยกชื่อ สถานะของ "หน้ารายการ" กับของ "หน้าฟอร์ม" จะทับกัน
 *
 * ✅ Use case: <a href="<?= listStateLink('book_form.php?id=' . $id, LIST_STATE_BOOKS) ?>">แก้ไข</a>
 *              <a href="<?= listStateLink('borrow_form.php', LIST_STATE_BORROWS) ?>">บันทึกการยืม</a>
 */
function listStateLink(string $target, array $allowed, ?array $source = null, string $prefix = 'ret_'): string
{
    $state = listState($allowed, $source);
    if (!$state) {
        return $target;
    }

    $prefixed = [];
    foreach ($state as $k => $v) {
        $prefixed[$prefix . $k] = $v;
    }

    // 🧠 ต่อด้วย ? หรือ & แล้วแต่ว่าปลายทางมี query อยู่แล้วหรือยัง
    $sep = str_contains($target, '?') ? '&' : '?';
    return $target . $sep . http_build_query($prefixed);
}

/**
 * 🎯 redirect กลับไปหน้ารายการพร้อมสถานะเดิม — ใช้แทน redirect('books.php') ทุกที่
 *
 * 🛡️ $page ต้องเป็นค่าคงที่ในโค้ดเท่านั้น ห้ามรับจากผู้ใช้
 *
 * ✅ Use case: redirectToList('books.php', BOOKS_LIST_STATE);
 */
function redirectToList(string $page, array $allowed, ?array $source = null, string $prefix = ''): void
{
    redirect($page . listStateQuery(listState($allowed, $source, $prefix)));
}

/**
 * 🎯 ช่อง hidden สำหรับพาสถานะรายการผ่านหน้าฟอร์ม (book_form / member_form)
 *
 * 🧠 ลิงก์ "แก้ไข" ในหน้ารายการพา ret_* มาให้ → หน้าฟอร์มพ่นกลับเป็น hidden
 *    → ตอน POST ถึงจะรู้ว่าต้องกลับไปหน้าไหน
 *    ถ้าไม่ทำครบ 3 ทอดนี้ ต่อให้ redirect ถูกก็กู้สถานะไม่ได้ เพราะมันหายตั้งแต่ตอนกดลิงก์
 */
function listStateHiddenInputs(array $allowed, ?array $source = null, string $prefix = 'ret_'): string
{
    $html = '';
    foreach (listState($allowed, $source, $prefix) as $k => $v) {
        $html .= '<input type="hidden" name="' . e($prefix . $k) . '" value="' . e($v) . '">' . "\n";
    }
    return $html;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: สร้าง URL ของหน้าที่ต้องการ โดยคง filter เดิมไว้ครบ
 * ==========================================================================
 * 🧠 ทำไมต้องมี: ถ้าลิงก์ "หน้า 2" ทิ้ง ?search= ไป ผู้ใช้จะเด้งกลับไปเห็นข้อมูลทั้งหมด
 * 🛡️ [SECURITY] ค่าถูก urlencode ผ่าน http_build_query — แต่ต้อง e() อีกชั้นตอนใส่ href
 */
function paginationUrl(array $params, int $page, string $pageKey = 'page'): string
{
    // 🧠 $pageKey เปิดให้หน้าที่มี **2 ตารางแบ่งหน้าแยกกัน** ใช้ชื่อพารามิเตอร์คนละตัวได้
    //    (admin/payments.php มีทั้ง "รายการค้างชำระ" และ "ประวัติการรับชำระ")
    //    ถ้าใช้ชื่อเดียวกัน กดหน้า 2 ของตารางบน ตารางล่างจะเลื่อนตามไปด้วย
    $params[$pageKey] = $page;
    // 📝 ตัดค่าว่างทิ้ง ให้ URL สั้นและอ่านง่าย
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($params);
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: แปลงข้อความเป็น "trigram" สำหรับให้ FULLTEXT ค้นหาภาษาไทยได้
 * ==========================================================================
 * 🧠 ทำไมต้องทำแบบนี้ (สำคัญมาก — อย่าเปลี่ยนเป็น FULLTEXT ธรรมดา):
 *    FULLTEXT ของ MySQL/MariaDB ตัดคำด้วย "ช่องว่าง" แต่ภาษาไทยไม่มีช่องว่างระหว่างคำ
 *    "การเขียนโปรแกรม" จึงกลายเป็น token เดียว → ค้น "โปรแกรม" **ไม่เจอ**
 *    (ทดสอบยืนยันแล้ว ทั้ง natural language mode และ boolean mode + wildcard)
 *    และ MariaDB ไม่มี ngram parser แบบ MySQL 8
 *
 *    วิธีแก้: เก็บ "คอลัมน์เงา" ที่ตัดข้อความเป็นชิ้นละ 3 ตัวอักษรแบบเลื่อนทีละตัว
 *    "การเขียน" → "การ ารเ รเข เขี ขีย ียน"  ← ทีนี้ FULLTEXT ก็มี token ให้ค้นแล้ว
 *    ตอนค้นก็แปลงคำค้นด้วยวิธีเดียวกัน แล้วสั่งให้เจอ "ทุกชิ้น"
 *
 * 📌 ทำไม 3 ตัว ไม่ใช่ 2: `innodb_ft_min_token_size` default = 3
 *    ถ้าใช้ 2 ตัว ต้องแก้ config ของ MySQL แล้ว restart ซึ่งบังคับลูกค้าไม่ได้
 *
 * 📝 normalize ก่อน: ตัดช่องว่าง/เครื่องหมายวรรคตอนทิ้ง เหลือแต่ตัวอักษร ตัวเลข
 *    และ \p{M} = สระบน-ล่าง + วรรณยุกต์ไทย
 *    ⚠️ ต้องเก็บ \p{M} ไว้ ไม่งั้น "กัน" กับ "กน" จะกลายเป็นคำเดียวกัน
 *
 * 📥 Input:  ข้อความดิบ (ชื่อเรื่อง + ผู้แต่ง + ISBN ต่อกัน)
 * 📤 Output: trigram คั่นด้วยช่องว่าง — เก็บลง `books.search_tokens`
 *
 * ⚠️ ถ้าแก้ฟังก์ชันนี้ **ต้องรัน `php database/rebuild_search_index.php` ใหม่ทั้งตาราง**
 *    ไม่งั้น token เก่ากับคำค้นใหม่จะคนละสูตรกัน แล้วค้นไม่เจอทั้งระบบ
 */
function buildSearchTokens(string $text): string
{
    // 📝 ตัวเล็กทั้งหมด — ค้นหาไม่สนตัวพิมพ์
    $normalized = mb_strtolower($text, 'UTF-8');

    // 📝 เหลือแต่ตัวอักษร (\p{L}) ตัวเลข (\p{N}) และสระ/วรรณยุกต์ (\p{M})
    $normalized = preg_replace('/[^\p{L}\p{N}\p{M}]+/u', '', $normalized) ?? '';

    $length = mb_strlen($normalized, 'UTF-8');
    if ($length === 0) {
        return '';
    }
    // 📝 สั้นกว่า 3 → เก็บทั้งก้อน (จะค้นเจอผ่าน LIKE อยู่ดี)
    if ($length < SEARCH_TOKEN_SIZE) {
        return $normalized;
    }

    $tokens = [];
    for ($i = 0; $i <= $length - SEARCH_TOKEN_SIZE; $i++) {
        $tokens[] = mb_substr($normalized, $i, SEARCH_TOKEN_SIZE, 'UTF-8');
    }
    return implode(' ', $tokens);
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: แปลงคำค้นของผู้ใช้เป็นเงื่อนไข BOOLEAN MODE ของ FULLTEXT
 * ==========================================================================
 * ✅ "โปรแกรม" → "+โปร +ปรแ +รแก +แกร +กรม"  (ต้องเจอครบทุกชิ้น)
 *
 * 📤 Output: string ที่ส่งเข้า `AGAINST(? IN BOOLEAN MODE)`
 *            หรือ **null** = ใช้ FULLTEXT กับคำค้นนี้ไม่ได้ ให้ผู้เรียกไปใช้ LIKE ล้วนแทน
 *
 * ⚠️ [กับดัก] คำค้นที่สั้นกว่า 3 ตัวอักษรหลัง normalize จะกลายเป็น token ที่สั้นกว่า
 *    `innodb_ft_min_token_size` → FULLTEXT จะ **คืน 0 ผลลัพธ์เงียบ ๆ**
 *    (ทดสอบแล้ว: ค้น "กา" ที่ควรเจอ 18,333 แถว กลับได้ 0)
 *    จึงต้องคืน null เพื่อบังคับให้ fallback ไม่ใช่ปล่อยผ่าน
 */
function buildSearchBooleanQuery(string $term): ?string
{
    $tokens = buildSearchTokens($term);
    if ($tokens === '') {
        return null;
    }

    $parts = explode(' ', $tokens);

    // 🛡️ ชิ้นแรกสั้นกว่าขนาดขั้นต่ำ = คำค้นทั้งคำสั้นเกินไป → FULLTEXT ใช้ไม่ได้
    if (mb_strlen($parts[0], 'UTF-8') < SEARCH_TOKEN_SIZE) {
        return null;
    }

    // ⚡ [PERF] คำค้นที่เป็นตัวเลขเป็นหลัก (ISBN/บาร์โค้ด) → ไม่ใช้ FULLTEXT
    // 🧠 เหตุผล: trigram ของตัวเลขมีได้แค่ 1,000 แบบ ("000" ถึง "999")
    //    ในห้องสมุดหลักหมื่นเล่ม ตัวเลข 3 หลักชุดหนึ่งจะไปโผล่ในหนังสือเป็นพัน ๆ เล่ม
    //    (ยิ่ง ISBN เติมศูนย์ข้างหน้ายิ่งหนัก — "000" ตรงกับแทบทุกเล่ม)
    //    FULLTEXT จึงต้องไล่รายการยาวมากแล้วตัดทิ้งเกือบหมด — วัดจริงแล้ว
    //    **ช้ากว่า** LIKE ราว 3 เท่า (70 ms เทียบกับ 21 ms ที่ 21,000 เล่ม)
    // 📌 ไม่กระทบการยิงบาร์โค้ด — ตรงนั้นใช้ findByIdOrIsbn() ที่ค้นแบบตรงตัวผ่าน uq_isbn
    $normalized = str_replace(' ', '', $tokens);
    $digitCount = preg_match_all('/\d/', $normalized);
    if ($digitCount > 0 && $digitCount / mb_strlen($normalized, 'UTF-8') >= 0.7) {
        return null;
    }

    // 📝 "+" หน้าทุกชิ้น = ต้องมีครบทุกชิ้นถึงจะนับว่าตรง
    return implode(' ', array_map(fn($token) => '+' . $token, $parts));
}

// ⚖️ กฎการยืม-คืน (ค่าปรับ / วันยืม / โควตา / วันหมดอายุการจอง)
//    ต้องโหลดที่นี่ ไม่ใช่ใน config.php เพราะจุดนี้เป็นจุดแรกที่ getDB() พร้อมใช้
//    → อ่านค่าที่ลูกค้าตั้งไว้จากตาราง settings ได้ (ดูเหตุผลเต็มใน includes/rules.php)
require_once __DIR__ . '/rules.php';

// 📝 Auto-start session — เรียกอัตโนมัติเมื่อ require functions.php
//    ไม่ต้องเรียก startSession() เอง

startSession();
