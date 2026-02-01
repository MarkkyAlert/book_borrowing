<?php
/**
 * Helper Functions
 */

require_once __DIR__ . '/config.php';

/**
 * ป้องกัน XSS โดยแปลง HTML entities
 * 
 * ใช้ครอบทุกครั้งที่แสดงผลข้อมูลจาก user/database บนหน้าเว็บ
 * 
 * @param string|null $string ข้อความที่ต้องการ escape (รับ null ได้)
 * @return string ข้อความที่ผ่านการ escape แล้ว (ปลอดภัยสำหรับ HTML)
 * 
 * @example echo e($user['name']); // แสดงชื่อผู้ใช้อย่างปลอดภัย
 */
function e(?string $string): string
{
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * เปลี่ยนเส้นทางไปยัง URL ที่กำหนด แล้วหยุดการทำงานทันที
 * 
 * @param string $url URL ปลายทาง (ควรใช้ APP_URL prefix)
 * @return never ฟังก์ชันนี้จะ exit() เสมอ ไม่มี return
 * 
 * @example redirect(APP_URL . '/login.php');
 */
function redirect(string $url): void
{
    header("Location: $url");
    exit;
}

/**
 * ตั้งค่าข้อความแจ้งเตือนชั่วคราว (แสดงครั้งเดียวแล้วหายไป)
 * 
 * ใช้คู่กับ redirect() เพื่อแจ้งผลการทำงานหลังจาก POST action
 * 
 * @param string $type    ประเภท: 'success', 'error', 'warning', 'info'
 * @param string $message ข้อความที่ต้องการแสดง
 * @param bool   $isHtml  true = แสดง HTML ตรงๆ (ระวัง XSS!), false = escape อัตโนมัติ
 * 
 * @sideeffect เขียนลง $_SESSION['flash']
 * 
 * @example setFlash('success', 'บันทึกสำเร็จ');
 *          redirect('list.php');
 */
function setFlash(string $type, string $message, bool $isHtml = false): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
        'isHtml' => $isHtml
    ];
}

/**
 * ดึงข้อความ flash แล้วลบออกจาก session (แสดงได้ครั้งเดียว)
 * 
 * @return array|null ['type' => string, 'message' => string, 'isHtml' => bool] หรือ null ถ้าไม่มี
 * 
 * @sideeffect ลบ $_SESSION['flash'] หลังจากดึงค่า
 */
function getFlash(): ?array
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Display flash message HTML (Tailwind)
 */
function displayFlash(): void
{
    $flash = getFlash();
    if ($flash) {
        $type = $flash['type'];
        // Map types to colors
        $colorClass = match($type) {
            'error', 'danger' => 'bg-red-50 text-red-700 border-red-200',
            'success' => 'bg-green-50 text-green-700 border-green-200',
            'warning' => 'bg-amber-50 text-amber-700 border-amber-200',
            default => 'bg-blue-50 text-blue-700 border-blue-200'
        };
        
        $icon = match($type) {
            'error', 'danger' => 'bi-exclamation-circle-fill',
            'success' => 'bi-check-circle-fill',
            'warning' => 'bi-exclamation-triangle-fill',
            default => 'bi-info-circle-fill'
        };

        echo '<div class="' . $colorClass . ' border-l-4 p-4 mb-6 rounded-r-lg shadow-sm flex items-start animate-fade-in-down" role="alert">';
        echo '<div class="flex-shrink-0 mr-3"><i class="bi ' . $icon . '"></i></div>';
        
        $content = $flash['isHtml'] ? $flash['message'] : e($flash['message']);
        
        echo '<div class="flex-grow">' . $content . '</div>';
        echo '<button type="button" class="ml-auto -mx-1.5 -my-1.5 rounded-lg p-1.5 inline-flex h-8 w-8 hover:bg-white/25 focus:ring-2 focus:ring-offset-2 focus:ring-offset-' . str_replace(['bg-', '-50'], ['text-', '-500'], $colorClass) . '" onclick="this.parentElement.remove()">';
        echo '<span class="sr-only">Close</span>';
        echo '<i class="bi bi-x text-lg"></i>';
        echo '</button>';
        echo '</div>';
    }
}

/**
 * ตรวจสอบว่าผู้ใช้ login อยู่หรือไม่
 * 
 * @return bool true = login อยู่, false = ยังไม่ login
 * 
 * @security ตรวจจาก session เท่านั้น ไม่เชื่อ cookie/header อื่น
 *           session_id ถูก regenerate หลัง login แล้ว (ป้องกัน session fixation)
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

/**
 * ตรวจสอบว่าผู้ใช้เป็น admin หรือไม่
 * 
 * @return bool true = เป็น admin
 * 
 * @security role มาจาก DB ที่ set ตอน login ไม่ใช่จาก user input
 *           ปลอดภัยจากการปลอมแปลง
 */
function isAdmin(): bool
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * ตรวจสอบว่าผู้ใช้เป็นเจ้าหน้าที่หรือไม่ (admin หรือ staff)
 * 
 * @return bool true = เป็น admin หรือ staff
 * 
 * @note ใช้สำหรับหน้าที่ไม่ต้องการสิทธิ์ admin เต็ม เช่น จัดการหนังสือ, ยืม-คืน
 */
function isStaff(): bool
{
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'staff']);
}

/**
 * บังคับให้ login ก่อนเข้าหน้านี้ - ถ้ายังไม่ login จะ redirect ไป login.php
 * 
 * @return void ถ้า login อยู่แล้ว / never ถ้ายังไม่ login (redirect แล้ว exit)
 * 
 * @sideeffect redirect ไป /login.php พร้อม flash message ถ้ายังไม่ login
 * 
 * @example // ใส่ไว้บรรทัดแรกของหน้าที่ต้อง login
 *          requireLogin();
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        setFlash('error', 'กรุณาเข้าสู่ระบบก่อน');
        redirect(APP_URL . '/login.php');
    }
}

/**
 * บังคับให้เป็น admin ก่อนเข้าหน้านี้
 * 
 * ถ้ายังไม่ login → redirect ไป login.php
 * ถ้า login แล้วแต่ไม่ใช่ admin → redirect ไป index.php
 * 
 * @return void ถ้าเป็น admin / never ถ้าไม่ผ่าน (redirect แล้ว exit)
 * 
 * @sideeffect redirect พร้อม flash message ถ้าไม่มีสิทธิ์
 * 
 * @example // ใส่ไว้บรรทัดแรกของหน้า admin-only เช่น settings, reports
 *          requireAdmin();
 */
function requireAdmin(): void
{
    requireLogin();
    if (!isAdmin()) {
        setFlash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้ (สำหรับผู้ดูแลระบบเท่านั้น)');
        redirect(APP_URL . '/index.php');
    }
}

/**
 * บังคับให้เป็นเจ้าหน้าที่ (admin หรือ staff) ก่อนเข้าหน้านี้
 * 
 * ถ้ายังไม่ login → redirect ไป login.php
 * ถ้า login แล้วแต่ไม่ใช่ staff → redirect ไป index.php
 * 
 * @return void ถ้าเป็น staff / never ถ้าไม่ผ่าน (redirect แล้ว exit)
 * 
 * @sideeffect redirect พร้อม flash message ถ้าไม่มีสิทธิ์
 * 
 * @example // ใส่ไว้บรรทัดแรกของหน้า staff เช่น books, borrows
 *          requireStaff();
 */
function requireStaff(): void
{
    requireLogin();
    if (!isStaff()) {
        setFlash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้ (สำหรับเจ้าหน้าที่เท่านั้น)');
        redirect(APP_URL . '/index.php');
    }
}

/**
 * ดึงข้อมูลผู้ใช้ปัจจุบันจากฐานข้อมูล
 * 
 * @return array|null ['id', 'name', 'email', 'phone', 'role'] หรือ null ถ้าไม่ได้ login
 * 
 * @note Query DB ทุกครั้งที่เรียก (ไม่ cache) - เหมาะสำหรับข้อมูลที่อาจเปลี่ยน
 *       ถ้าต้องการแค่ user_id/role ใช้ $_SESSION แทนจะเร็วกว่า
 */
function getCurrentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }
    
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/../app/Repositories/UserRepository.php';
    
    $userRepo = new \App\Repositories\UserRepository(getDB());
    return $userRepo->findById($_SESSION['user_id']);
}

/**
 * ดึงค่า setting จากฐานข้อมูล
 * 
 * @param string $key     ชื่อ setting ที่ต้องการ
 * @param mixed  $default ค่าเริ่มต้นถ้าไม่พบ setting
 * @return mixed ค่า setting หรือค่าเริ่มต้น
 * 
 * @example $orgName = getSetting('org_name', 'ห้องสมุด');
 */
function getSetting($key, $default = '') {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/../app/Repositories/SettingsRepository.php';
    
    $settingsRepo = new \App\Repositories\SettingsRepository(getDB());
    return $settingsRepo->get($key, $default);
}

/**
 * บันทึกค่า setting ลงฐานข้อมูล (insert หรือ update ถ้ามีอยู่แล้ว)
 * 
 * @param string $key   ชื่อ setting
 * @param mixed  $value ค่าที่ต้องการบันทึก
 * @return bool true = สำเร็จ
 * 
 * @sideeffect INSERT หรือ UPDATE ตาราง settings
 */
function updateSetting($key, $value) {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/../app/Repositories/SettingsRepository.php';
    
    $settingsRepo = new \App\Repositories\SettingsRepository(getDB());
    return $settingsRepo->set($key, $value);
}

/**
 * จัดรูปแบบวันที่สำหรับแสดงผล
 * 
 * @param string|null $date   วันที่ในรูปแบบที่ strtotime() เข้าใจได้ (เช่น Y-m-d)
 * @param string      $format รูปแบบผลลัพธ์ (default: d/m/Y สำหรับไทย)
 * @return string วันที่ที่จัดรูปแบบแล้ว หรือ '-' ถ้าไม่มีค่า
 * 
 * @example formatDate('2024-01-15');         // "15/01/2024"
 *          formatDate('2024-01-15', 'Y-m-d'); // "2024-01-15"
 */
function formatDate(?string $date, string $format = 'd/m/Y'): string
{
    if (!$date) {
        return '-';
    }
    return date($format, strtotime($date));
}

/**
 * คำนวณจำนวนวันระหว่าง 2 วันที่
 * 
 * @param string $date1 วันที่เริ่มต้น (Y-m-d หรือรูปแบบที่ DateTime เข้าใจ)
 * @param string $date2 วันที่สิ้นสุด
 * @return int จำนวนวัน (บวก = date2 > date1, ลบ = date2 < date1)
 * 
 * @example daysDiff('2024-01-01', '2024-01-15'); // 14
 *          daysDiff('2024-01-15', '2024-01-01'); // -14
 */
function daysDiff(string $date1, string $date2): int
{
    $d1 = new DateTime($date1);
    $d2 = new DateTime($date2);
    return (int) $d1->diff($d2)->format('%r%a');
}

/**
 * Get book status label (Tailwind)
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
 * Get borrow status label (Tailwind)
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
 * Get reservation status label (Tailwind)
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
 * ตรวจสอบรูปแบบ email
 * 
 * @param string $email อีเมลที่ต้องการตรวจสอบ
 * @return bool true = รูปแบบถูกต้อง
 * 
 * @note ใช้ PHP FILTER_VALIDATE_EMAIL (RFC 5321)
 *       ไม่ได้ตรวจว่า email มีจริงหรือไม่ - ต้องส่ง verification email เอง
 */
function isValidEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * ตรวจสอบรูปแบบเบอร์โทรศัพท์ (ไทย)
 * 
 * @param string $phone เบอร์โทรที่ต้องการตรวจสอบ
 * @return bool true = รูปแบบถูกต้อง (ตัวเลข 9-10 หลัก)
 * 
 * @note รองรับเฉพาะเบอร์ไทย เช่น 0812345678
 *       ถ้าต้องการ international format (+66...) ต้องแก้ regex
 * 
 * @example isValidPhone('0812345678'); // true
 *          isValidPhone('081-234-5678'); // false (มีขีด)
 */
function isValidPhone(string $phone): bool
{
    return preg_match('/^[0-9]{9,10}$/', $phone);
}

/**
 * สร้าง CSRF token สำหรับป้องกันการโจมตี Cross-Site Request Forgery
 * 
 * @return string token 64 ตัวอักษร (hex)
 * 
 * @security - ใช้ random_bytes() ที่ cryptographically secure
 *           - Token เดียวต่อ session (สร้างครั้งเดียว ใช้ซ้ำได้จนกว่า session หมดอายุ)
 *           - ถ้าต้องการ per-request token ต้องแก้ logic
 * 
 * @sideeffect เขียน $_SESSION['csrf_token'] ถ้ายังไม่มี
 * 
 * @example <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
 */
function generateCSRFToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * ตรวจสอบ CSRF token จาก form submission
 * 
 * @param string $token token ที่ได้รับจาก $_POST['csrf_token']
 * @return bool true = token ถูกต้อง
 * 
 * @security ใช้ hash_equals() ป้องกัน timing attack
 *           ห้ามใช้ == หรือ === เปรียบเทียบ token
 * 
 * @example if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
 *              die('Invalid CSRF token');
 *          }
 */
function validateCSRFToken(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Start secure session
 */
function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * ตรวจสอบ rate limit สำหรับป้องกัน brute force
 * 
 * @param string $key ชื่อ key สำหรับแยก limit แต่ละ action (เช่น 'login', 'register')
 * @param int $maxAttempts จำนวนครั้งสูงสุดที่อนุญาต (default: RATE_LIMIT_MAX_ATTEMPTS)
 * @param int $windowMinutes ช่วงเวลาที่นับ (นาที) (default: RATE_LIMIT_WINDOW_MINUTES)
 * @return bool true = ยังไม่เกิน limit, false = เกิน limit แล้ว
 * 
 * @example if (!checkRateLimit('login')) {
 *              $errors[] = 'ลองหลายครั้งเกินไป กรุณารอ ' . RATE_LIMIT_WINDOW_MINUTES . ' นาที';
 *          }
 */
function checkRateLimit(string $key, ?int $maxAttempts = null, ?int $windowMinutes = null): bool
{
    $maxAttempts = $maxAttempts ?? RATE_LIMIT_MAX_ATTEMPTS;
    $windowMinutes = $windowMinutes ?? RATE_LIMIT_WINDOW_MINUTES;
    
    $attemptKey = $key . '_attempts';
    $timeKey = $key . '_time';
    
    if (!isset($_SESSION[$attemptKey])) {
        $_SESSION[$attemptKey] = 0;
        $_SESSION[$timeKey] = time();
    }
    
    // Reset counter หลังหมดเวลา window
    if (time() - $_SESSION[$timeKey] > $windowMinutes * 60) {
        $_SESSION[$attemptKey] = 0;
        $_SESSION[$timeKey] = time();
    }
    
    return $_SESSION[$attemptKey] < $maxAttempts;
}

/**
 * เพิ่มจำนวน attempt สำหรับ rate limit
 * 
 * @param string $key ชื่อ key เดียวกับที่ใช้กับ checkRateLimit()
 */
function incrementRateLimit(string $key): void
{
    $attemptKey = $key . '_attempts';
    if (!isset($_SESSION[$attemptKey])) {
        $_SESSION[$attemptKey] = 0;
    }
    $_SESSION[$attemptKey]++;
}

/**
 * Reset rate limit counter (เรียกหลัง success)
 * 
 * @param string $key ชื่อ key เดียวกับที่ใช้กับ checkRateLimit()
 */
function resetRateLimit(string $key): void
{
    $attemptKey = $key . '_attempts';
    $_SESSION[$attemptKey] = 0;
}

/**
 * ล้าง idempotency keys ที่หมดอายุ (เรียกตอนเริ่ม session)
 * 
 * @param int $maxAgeSeconds อายุสูงสุดของ key (default: 300 = 5 นาที)
 */
function cleanupIdempotencyKeys(int $maxAgeSeconds = 300): void
{
    if (!isset($_SESSION['processed_actions'])) {
        $_SESSION['processed_actions'] = [];
        return;
    }
    
    $now = time();
    foreach ($_SESSION['processed_actions'] as $key => $timestamp) {
        if ($now - $timestamp > $maxAgeSeconds) {
            unset($_SESSION['processed_actions'][$key]);
        }
    }
}

/**
 * จัดรูปแบบจำนวนเงินค่าปรับ (บาท)
 * 
 * @param float $amount จำนวนเงิน
 * @return string เช่น "150 บาท" หรือ "-" ถ้าไม่มีค่าปรับ
 */
function formatFine(float $amount): string
{
    if ($amount <= 0) {
        return '-';
    }
    return number_format($amount) . ' บาท';
}

// Auto-start session
startSession();
