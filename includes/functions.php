<?php
/**
 * Helper Functions
 */

require_once __DIR__ . '/config.php';

/**
 * Escape output to prevent XSS
 */
function e(?string $string): string
{
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect to another page
 */
function redirect(string $url): void
{
    header("Location: $url");
    exit;
}

/**
 * Set flash message
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
 * Get and clear flash message
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
 * Check if user is logged in
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

/**
 * Check if user is admin
 */
function isAdmin(): bool
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Check if user is staff (Authorized personnel: Admin or Staff)
 */
function isStaff(): bool
{
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'staff']);
}

/**
 * Require login - redirect if not logged in
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        setFlash('error', 'กรุณาเข้าสู่ระบบก่อน');
        redirect(APP_URL . '/login.php');
    }
}

/**
 * Require admin - redirect if not admin
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
 * Require staff - redirect if not staff
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
 * Get current user data
 */
function getCurrentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }
    
    global $pdo;
    if (!isset($pdo)) {
        require_once __DIR__ . '/db.php';
        $pdo = getDB();
    }
    
    $stmt = $pdo->prepare("SELECT id, name, email, phone, role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

/**
 * Get system setting
 */
function getSetting($key, $default = '') {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value !== false ? $value : $default;
}

/**
 * Update system setting
 */
function updateSetting($key, $value) {
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    return $stmt->execute([$key, $value, $value]);
}

function formatDate(?string $date, string $format = 'd/m/Y'): string
{
    if (!$date) {
        return '-';
    }
    return date($format, strtotime($date));
}

/**
 * Calculate days difference
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
 * Validate email format
 */
function isValidEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone format (Thai)
 */
function isValidPhone(string $phone): bool
{
    return preg_match('/^[0-9]{9,10}$/', $phone);
}

/**
 * Generate CSRF token
 */
function generateCSRFToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
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
 * Format fine amount with Thai Baht
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
