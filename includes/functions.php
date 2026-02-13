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

        echo '<div class="' . $colorClass . ' border-l-4 p-3 sm:p-4 mb-4 sm:mb-6 rounded-r-lg shadow-sm flex items-start gap-2 sm:gap-3 relative z-30 overflow-hidden" role="alert">';
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
 * 🎯 จุดประสงค์: บังคับ login (ถ้ายังไม่ login → redirect login.php)
 * ==========================================================================
 * ✅ Use case: ใส่บรรทัดแรกของหน้าที่ต้อง login
 */
function requireLogin(): void
{
    // 📝 ถ้ายังไม่ login → flash error + redirect ไป login.php
    if (!isLoggedIn()) {
        setFlash('error', 'กรุณาเข้าสู่ระบบก่อน');
        redirect(APP_URL . '/login.php');
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
        'pending' => '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800"><i class="bi bi-hourglass-split mr-1"></i>รอรับของ</span>',
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
function validateMemberData(array $data, bool $isEdit = false): array
{
    $errors = [];

    // Name
    if (empty(trim($data['name'] ?? ''))) {
        $errors[] = 'กรุณากรอกชื่อ-นามสกุล';
    } elseif ($err = validateMaxLength($data['name'], 100, 'ชื่อ')) {
        $errors[] = $err;
    }

    // Email
    if (empty(trim($data['email'] ?? ''))) {
        $errors[] = 'กรุณากรอกอีเมล';
    } elseif (!isValidEmail($data['email'])) {
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
function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
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

// 📝 Auto-start session — เรียกอัตโนมัติเมื่อ require functions.php
//    ไม่ต้องเรียก startSession() เอง

startSession();
