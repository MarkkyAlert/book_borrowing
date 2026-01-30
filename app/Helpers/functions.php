<?php
/**
 * Helper Functions - Utility functions สำหรับใช้ทั่วทั้งระบบ
 * 
 * ไฟล์นี้รวม utility functions ที่ไม่ใช่ business logic
 * สำหรับ business logic ให้ดูที่ app/Services/
 * 
 * @package App\Helpers
 */

namespace App\Helpers;

/**
 * Escape HTML สำหรับป้องกัน XSS
 */
function e(?string $string): string
{
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect ไปยัง URL
 */
function redirect(string $url): void
{
    header("Location: $url");
    exit;
}

/**
 * ตั้งค่า Flash Message
 */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * ดึง Flash Message
 */
function getFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * แสดง Flash Message เป็น HTML
 */
function displayFlash(): void
{
    $flash = getFlash();
    if (!$flash) return;

    $bgColors = [
        'success' => 'bg-green-50 border-green-500 text-green-800',
        'error' => 'bg-red-50 border-red-500 text-red-800',
        'warning' => 'bg-yellow-50 border-yellow-500 text-yellow-800',
        'info' => 'bg-blue-50 border-blue-500 text-blue-800'
    ];

    $icons = [
        'success' => 'bi-check-circle-fill',
        'error' => 'bi-x-circle-fill',
        'warning' => 'bi-exclamation-triangle-fill',
        'info' => 'bi-info-circle-fill'
    ];

    $bgColor = $bgColors[$flash['type']] ?? $bgColors['info'];
    $icon = $icons[$flash['type']] ?? $icons['info'];

    echo "<div class=\"border-l-4 p-4 mb-4 rounded-r-lg {$bgColor}\" role=\"alert\">";
    echo "<div class=\"flex items-center\">";
    echo "<i class=\"bi {$icon} mr-2\"></i>";
    echo "<span>" . e($flash['message']) . "</span>";
    echo "</div></div>";
}

/**
 * Format วันที่เป็นภาษาไทย
 */
function formatDate(?string $date, string $format = 'd/m/Y'): string
{
    if (empty($date)) return '-';
    
    try {
        $dt = new \DateTime($date);
        return $dt->format($format);
    } catch (\Exception $e) {
        return $date;
    }
}

/**
 * Format วันที่แบบเต็ม
 */
function formatDateFull(?string $date): string
{
    if (empty($date)) return '-';
    
    $thaiMonths = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'
    ];
    
    try {
        $dt = new \DateTime($date);
        $day = $dt->format('j');
        $month = $thaiMonths[(int)$dt->format('n')];
        $year = (int)$dt->format('Y') + 543;
        return "{$day} {$month} {$year}";
    } catch (\Exception $e) {
        return $date;
    }
}

/**
 * คำนวณจำนวนวันระหว่างวันที่
 */
function daysDiff(string $date1, string $date2): int
{
    $d1 = new \DateTime($date1);
    $d2 = new \DateTime($date2);
    return (int) $d1->diff($d2)->days;
}

/**
 * สร้าง CSRF Token
 */
function generateCSRFToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * ตรวจสอบ CSRF Token
 */
function validateCSRFToken(?string $token): bool
{
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * ตรวจสอบว่าเป็น email ที่ถูกต้องหรือไม่
 */
function isValidEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * ตรวจสอบว่าเป็นเบอร์โทรที่ถูกต้องหรือไม่
 */
function isValidPhone(string $phone): bool
{
    return preg_match('/^[0-9]{9,10}$/', $phone);
}

/**
 * Format ค่าปรับ
 */
function formatFine(float $amount): string
{
    return number_format($amount, 0) . ' บาท';
}

/**
 * สร้าง label สถานะหนังสือ
 */
function getBookStatusLabel(int $available, int $quantity): string
{
    if ($available <= 0) {
        return '<span class="px-2 py-1 text-xs font-medium bg-red-100 text-red-800 rounded-full">หมด</span>';
    } elseif ($available < $quantity) {
        return '<span class="px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-800 rounded-full">ว่าง ' . $available . '</span>';
    } else {
        return '<span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded-full">ว่าง ' . $available . '</span>';
    }
}

/**
 * สร้าง label สถานะการยืม
 */
function getBorrowStatusLabel(string $status, ?string $dueDate = null): string
{
    if ($status === 'returned') {
        return '<span class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-800 rounded-full">คืนแล้ว</span>';
    }

    // Check if overdue
    if ($dueDate && strtotime($dueDate) < strtotime('today')) {
        $days = daysDiff($dueDate, date('Y-m-d'));
        return '<span class="px-2 py-1 text-xs font-medium bg-red-100 text-red-800 rounded-full">เกิน ' . $days . ' วัน</span>';
    }

    return '<span class="px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full">กำลังยืม</span>';
}

/**
 * สร้าง label สถานะการจอง
 */
function getReservationStatusLabel(string $status): string
{
    $labels = [
        'pending' => '<span class="px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-800 rounded-full">รอรับ</span>',
        'fulfilled' => '<span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded-full">รับแล้ว</span>',
        'expired' => '<span class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-800 rounded-full">หมดอายุ</span>',
        'cancelled' => '<span class="px-2 py-1 text-xs font-medium bg-red-100 text-red-800 rounded-full">ยกเลิก</span>',
    ];

    return $labels[$status] ?? $status;
}

/**
 * Truncate ข้อความ
 */
function truncate(string $text, int $length = 100): string
{
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length) . '...';
}

/**
 * สุ่มรหัสผ่าน
 */
function generateRandomPassword(int $length = 8): string
{
    return substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, $length);
}
