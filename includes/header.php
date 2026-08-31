<?php

/**
 * Public Header Template - layout wrapper สำหรับหน้า public (root/*.php)
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - ทุกหน้า root (index, book, login, register, profile, my_*) require ไฟล์นี้
 * - ประกอบด้วย: <head>, top navbar (เปลี่ยนตาม login status), เปิด <main>
 * - ปิด </main> อยู่ที่ includes/footer.php
 * - ต้องตั้ง $pageTitle ก่อน require เพื่อแสดงชื่อหน้าใน title bar
 */
require_once __DIR__ . '/functions.php';

// 📝 ดึงชื่อไฟล์ปัจจุบัน (สำหรับ highlight active menu)
$currentPage = basename($_SERVER['PHP_SELF']);
// 📝 ดึง user จาก DB (ถ้า login) สำหรับแสดงชื่อบน navbar
$user = isLoggedIn() ? getCurrentUser() : null;

/**
 * 🔔 ตัวเลขแจ้งเตือนของ **สมาชิกคนที่ล็อกอินอยู่**
 *
 * 🔴 [SECURITY] ใช้ `$_SESSION['user_id']` เท่านั้น — **ห้ามรับ id จาก URL**
 *    ไฟล์นี้ถูก include ทุกหน้าฝั่งผู้ใช้ ถ้าเผลอรับพารามิเตอร์ภายนอก
 *    สมาชิกจะสอดส่องตัวเลขของคนอื่นได้ทันที
 *
 * 🔴 [PERFORMANCE] **ยิง query เฉพาะตอนล็อกอิน** — หน้าแรก/หน้า login/สมัครสมาชิก
 *    เป็นหน้าที่คนทั่วไปเปิดบ่อยที่สุด ยิง query ตรงนั้นคือจ่ายฟรีโดยไม่ได้อะไร
 *
 * 🛡️ ถ้าดึงไม่ได้ให้กระดิ่งเงียบ ไม่ใช่ทำให้ทั้งหน้าพัง
 */
$memberAlerts = ['overdue' => 0, 'due_soon' => 0, 'ready_pickup' => 0, 'unpaid' => 0, 'total' => 0];
$memberAlertItems = [];
if (isLoggedIn()) {
    try {
        $memberAlerts = (new \App\Repositories\BorrowRepository(getDB()))
            ->getMemberAlertCounts((int) $_SESSION['user_id'], (int) DUE_SOON_DAYS);
    } catch (\Throwable $e) {
        // เงียบไว้ — กระดิ่งไม่ใช่ของที่ควรทำให้หน้าเว็บล่ม
    }

    // 🧠 เรียงตามความเร่งด่วน: เกินกำหนดมาก่อนเสมอ
    if ($memberAlerts['overdue'] > 0) {
        $memberAlertItems[] = ['label' => 'เลยกำหนดคืนแล้ว', 'count' => $memberAlerts['overdue'],
            'unit' => 'เล่ม', 'icon' => 'bi-exclamation-triangle', 'tone' => 'red',
            'url' => APP_URL . '/my_borrows.php'];
    }
    // 🔴 "ต้องคืนวันนี้" เป็นส่วนย่อยของ "ใกล้ครบกำหนด" — total ไม่นับซ้ำ
    //    เดิมสมาชิกเห็นแค่ "ใกล้ครบกำหนดคืน 3" ซึ่งวันนี้กับอีก 3 วันหน้าตาเหมือนกันหมด
    if ($memberAlerts['due_today'] > 0) {
        $memberAlertItems[] = ['label' => 'ต้องคืนวันนี้', 'count' => $memberAlerts['due_today'],
            'unit' => 'เล่ม', 'icon' => 'bi-calendar-check', 'tone' => 'orange',
            'url' => APP_URL . '/my_borrows.php'];
    }
    if ($memberAlerts['due_soon'] > 0) {
        $memberAlertItems[] = ['label' => 'ใกล้ครบกำหนดคืน', 'count' => $memberAlerts['due_soon'],
            'unit' => 'เล่ม', 'icon' => 'bi-clock-history', 'tone' => 'amber',
            'url' => APP_URL . '/my_borrows.php'];
    }
    /**
     * 🔴 ช่องโหว่เดิมฝั่งสมาชิก: "จองไว้ รอมารับ 1" ไม่บอกว่าเหลือเวลาเท่าไหร่
     *    ค่าเริ่มต้นให้เวลามารับแค่ 2 วัน (RESERVATION_EXPIRE_DAYS)
     *    พรุ่งนี้หมดอายุ กับ อีก 2 วันหมด หน้าตาเหมือนกันเป๊ะ → เสียคิวโดยไม่รู้ตัว
     * 🧠 ใช้เกณฑ์เดียวกับป้ายแดง "ใกล้หมดอายุ!" ใน my_reservations.php
     *    (ReservationRepository::EXPIRING_SOON_CONDITION) กระดิ่งกับหน้าจึงพูดตรงกัน
     */
    if ($memberAlerts['expiring_reservations'] > 0) {
        $memberAlertItems[] = ['label' => 'จองใกล้หมดอายุ', 'count' => $memberAlerts['expiring_reservations'],
            'unit' => 'รายการ', 'icon' => 'bi-hourglass-split', 'tone' => 'rose',
            'url' => APP_URL . '/my_reservations.php'];
    }
    if ($memberAlerts['ready_pickup'] > 0) {
        $memberAlertItems[] = ['label' => 'จองไว้ รอมารับ', 'count' => $memberAlerts['ready_pickup'],
            'unit' => 'รายการ', 'icon' => 'bi-bookmark-check', 'tone' => 'indigo',
            'url' => APP_URL . '/my_reservations.php'];
    }
    if ($memberAlerts['unpaid'] > 0) {
        $memberAlertItems[] = ['label' => 'ค่าปรับค้างชำระ', 'count' => $memberAlerts['unpaid'],
            'unit' => 'รายการ', 'icon' => 'bi-cash-coin', 'tone' => 'orange',
            'url' => APP_URL . '/profile.php'];
    }
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- 📝 $pageTitle ต้องตั้งก่อน require header.php -->
    <title><?= isset($pageTitle) ? e($pageTitle) . ' - ' : '' ?><?= APP_NAME ?></title>

    <!-- Google Fonts -->
    <?php // 🔌 [OFFLINE] asset ทุกตัวอยู่ในโปรเจกต์ ไม่พึ่งอินเทอร์เน็ต — ดู assets/vendor/README.md
    //    ห้ามเปลี่ยนกลับไปใช้ CDN: ลูกค้าหลายรายเป็นห้องสมุด intranet ที่ไม่ต่อเน็ต
    ?>
    <link href="<?= APP_URL ?>/assets/vendor/fonts/sarabun.css" rel="stylesheet">

    <!-- Bootstrap Icons (Keep for icons compatibility) -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.css">

    <!-- Tailwind CSS -->
    <?php // 🧠 Tailwind ตัวนี้คอมไพล์ในเบราว์เซอร์ (Play CDN) — เก็บไฟล์เดิมมาไว้เครื่อง
    //    ไม่เปลี่ยนไปใช้ build step เพราะโปรเจกต์นี้ตั้งใจไม่มี Node/npm
    //    ⚠️ ต้องโหลดก่อน tailwind.config ด้านล่างเสมอ
    ?>
    <script src="<?= APP_URL ?>/assets/vendor/tailwind/tailwind.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Sarabun', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            200: '#bfdbfe',
                            300: '#93c5fd',
                            400: '#60a5fa',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                            950: '#172554',
                        }
                    }
                }
            }
        }
    </script>

    <style>
        /* Custom scrollbar for webkit */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
    <!-- Modal Component -->
    <script src="<?= APP_URL ?>/includes/modal.js"></script>
</head>

<body class="bg-gray-50 text-gray-800 font-sans antialiased flex flex-col min-h-screen">

    <!-- Navbar -->
    <nav class="bg-white shadow-sm sticky top-0 z-50 border-b border-gray-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <!-- Logo -->
                <div class="flex items-center">
                    <a href="<?= APP_URL ?>" class="flex-shrink-0 flex items-center group">
                        <div class="w-10 h-10 bg-primary-600 rounded-lg flex items-center justify-center text-white shadow-lg shadow-primary-500/30 transform group-hover:scale-105 transition-transform duration-200">
                            <i class="bi bi-book-half text-xl"></i>
                        </div>
                        <span class="ml-3 text-xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-primary-700 to-primary-500">
                            <?= APP_NAME ?>
                        </span>
                    </a>

                    <!-- Desktop Menu -->
                    <div class="hidden md:ml-10 md:flex md:space-x-1">
                        <a href="<?= APP_URL ?>"
                            class="<?= $currentPage === 'index.php'
                                        ? 'text-primary-600 bg-primary-50'
                                        : 'text-gray-600 hover:text-primary-600 hover:bg-gray-50' ?> 
                                px-4 py-2 rounded-lg text-sm font-medium transition-colors duration-200 flex items-center">
                            <i class="bi bi-house mr-2"></i>หน้าแรก
                        </a>
                    </div>
                </div>

                <!-- Right Menu -->
                <div class="hidden md:flex items-center space-x-4">
                    <?php if (isLoggedIn()): ?>
                        <?php if (isAdmin() || isStaff()): ?>
                            <a href="<?= APP_URL ?>/admin/" class="text-gray-600 hover:text-primary-600 font-medium text-sm flex items-center transition-colors">
                                <i class="bi bi-gear mr-1.5"></i>จัดการระบบ
                            </a>
                        <?php endif; ?>

                        <?php // 🔔 กระดิ่งของสมาชิก — คนละชุดตัวเลขกับกระดิ่งฝั่งแอดมิน
                              //    อันนี้ตอบว่า "ฉันต้องทำอะไร" · ฝั่งแอดมินตอบว่า "ห้องสมุดต้องทำอะไร"
                              //    เจ้าหน้าที่ที่มาดูหน้าเว็บฝั่งผู้ใช้จะเห็นอันนี้ ซึ่งถูกต้องแล้ว
                              //    เพราะเขาก็ยืมหนังสือได้เหมือนกัน ?>
                        <div class="relative">
                            <button type="button"
                                    onclick="event.stopPropagation(); document.getElementById('member-alerts').classList.toggle('hidden')"
                                    class="text-gray-500 hover:text-primary-600 transition-colors relative p-1"
                                    aria-label="การแจ้งเตือนของฉัน">
                                <i class="bi bi-bell text-xl"></i>
                                <?php if ($memberAlerts['total'] > 0): ?>
                                    <span class="absolute -top-0.5 -right-0.5 min-w-[1.05rem] h-[1.05rem] px-1 flex items-center justify-center
                                                 rounded-full ring-2 ring-white bg-red-500 text-white text-[10px] font-bold">
                                        <?= $memberAlerts['total'] > 99 ? '99+' : $memberAlerts['total'] ?>
                                    </span>
                                <?php endif; ?>
                            </button>

                            <div id="member-alerts"
                                 class="hidden absolute right-0 mt-2 w-72 bg-white rounded-xl shadow-lg border border-gray-200 z-50 overflow-hidden">
                                <div class="px-4 py-3 border-b border-gray-100 bg-gray-50/70">
                                    <p class="text-sm font-bold text-gray-800">การแจ้งเตือนของฉัน</p>
                                </div>
                                <?php if (!$memberAlertItems): ?>
                                    <div class="px-4 py-6 text-center text-gray-400">
                                        <i class="bi bi-check-circle text-2xl text-green-500 block mb-1"></i>
                                        <p class="text-sm">ไม่มีอะไรต้องจัดการ</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($memberAlertItems as $item): ?>
                                        <a href="<?= e($item['url']) ?>"
                                           class="flex items-center justify-between px-4 py-3 hover:bg-gray-50 border-b border-gray-50 last:border-0">
                                            <span class="flex items-center gap-2 text-sm text-gray-700">
                                                <i class="bi <?= e($item['icon']) ?> text-<?= e($item['tone']) ?>-500"></i>
                                                <?= e($item['label']) ?>
                                            </span>
                                            <span class="text-sm font-bold text-<?= e($item['tone']) ?>-600">
                                                <?= number_format($item['count']) ?> <span class="font-normal text-gray-400 text-xs"><?= e($item['unit']) ?></span>
                                            </span>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <script>
                                document.addEventListener('click', function () {
                                    var d = document.getElementById('member-alerts');
                                    if (d) d.classList.add('hidden');
                                });
                            </script>
                        </div>

                        <!-- Profile Dropdown -->
                        <div class="relative group ml-3">
                            <button type="button" class="flex items-center space-x-2 text-sm font-medium text-gray-700 hover:text-primary-600 focus:outline-none transition-colors">
                                <span class="w-8 h-8 rounded-full bg-primary-100 text-primary-600 flex items-center justify-center border border-primary-200">
                                    <i class="bi bi-person-fill"></i>
                                </span>
                                <span><?= e($user['name'] ?? 'ผู้ใช้') ?></span>
                                <i class="bi bi-chevron-down text-xs text-gray-400 group-hover:text-primary-500 transition-colors"></i>
                            </button>

                            <!-- Dropdown Menu — outer div has pt-2 as hover bridge (replaces mt-2 gap) -->
                            <div class="absolute right-0 pt-2 w-48 z-50 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200">
                                <div class="bg-white rounded-xl shadow-lg py-1 border border-gray-100 transform group-hover:translate-y-0 translate-y-2 transition-transform duration-200">
                                    <div class="px-4 py-3 border-b border-gray-50">
                                        <p class="text-sm text-gray-500">สวัสดี,</p>
                                        <p class="text-sm font-medium text-gray-900 truncate"><?= e($user['name'] ?? '') ?></p>
                                    </div>
                                    <a href="<?= APP_URL ?>/profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-primary-600 transition-colors">
                                        <i class="bi bi-person mr-2 text-gray-400"></i>โปรไฟล์
                                    </a>
                                    <a href="<?= APP_URL ?>/my_borrows.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-primary-600 transition-colors">
                                        <i class="bi bi-book mr-2 text-gray-400"></i>รายการยืมของฉัน
                                    </a>
                                    <a href="<?= APP_URL ?>/my_reservations.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-primary-600 transition-colors">
                                        <i class="bi bi-bookmark-check mr-2 text-gray-400"></i>รายการจองของฉัน
                                    </a>
                                    <div class="border-t border-gray-50 my-1"></div>
                                    <button type="button" onclick="document.getElementById('logout-form').submit()" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
                                        <i class="bi bi-box-arrow-right mr-2"></i>ออกจากระบบ
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="flex items-center space-x-3">
                            <a href="<?= APP_URL ?>/login.php" class="text-gray-600 hover:text-primary-600 font-medium text-sm px-4 py-2 rounded-lg hover:bg-gray-50 transition-all">
                                เข้าสู่ระบบ
                            </a>
                            <a href="<?= APP_URL ?>/register.php" class="bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg shadow-md shadow-primary-500/20 transition-all transform hover:-translate-y-0.5">
                                สมัครสมาชิก
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Mobile menu button -->
                <div class="flex items-center md:hidden">
                    <button type="button" onclick="document.getElementById('mobile-menu').classList.toggle('hidden')" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-primary-500">
                        <span class="sr-only">Open main menu</span>
                        <i class="bi bi-list text-2xl"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Menu -->
        <div class="hidden md:hidden bg-white border-t border-gray-100" id="mobile-menu">
            <div class="px-2 pt-2 pb-3 space-y-1">
                <a href="<?= APP_URL ?>" class="block px-3 py-2 rounded-md text-base font-medium text-primary-700 bg-primary-50">
                    <i class="bi bi-house mr-2"></i>หน้าแรก
                </a>

                <?php if (isLoggedIn()): ?>
                    <?php if (isAdmin() || isStaff()): ?>
                        <a href="<?= APP_URL ?>/admin/" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-primary-600 hover:bg-gray-50">
                            <i class="bi bi-gear mr-2"></i>จัดการระบบ
                        </a>
                    <?php endif; ?>
                    <a href="<?= APP_URL ?>/profile.php" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-primary-600 hover:bg-gray-50">
                        <i class="bi bi-person mr-2"></i>โปรไฟล์
                    </a>
                    <a href="<?= APP_URL ?>/my_borrows.php" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-primary-600 hover:bg-gray-50">
                        <i class="bi bi-book mr-2"></i>รายการยืมของฉัน
                    </a>
                    <a href="<?= APP_URL ?>/my_reservations.php" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-primary-600 hover:bg-gray-50">
                        <i class="bi bi-bookmark-check mr-2"></i>รายการจองของฉัน
                    </a>
                    <button type="button" onclick="document.getElementById('logout-form').submit()" class="w-full text-left block px-3 py-2 rounded-md text-base font-medium text-red-600 hover:bg-red-50">
                        <i class="bi bi-box-arrow-right mr-2"></i>ออกจากระบบ
                    </button>
                <?php else: ?>
                    <a href="<?= APP_URL ?>/login.php" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-primary-600 hover:bg-gray-50">
                        เข้าสู่ระบบ
                    </a>
                    <a href="<?= APP_URL ?>/register.php" class="block px-3 py-2 rounded-md text-base font-medium text-primary-600 font-bold">
                        สมัครสมาชิก
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <?php if (isLoggedIn()): ?>
        <!-- 🛡️ Logout form: POST + CSRF token (ป้องกัน logout ผ่าน GET link) -->
        <form id="logout-form" method="POST" action="<?= APP_URL ?>/logout.php" class="hidden">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
        </form>
    <?php endif; ?>

    <!-- 📝 Main Content: เปิดที่นี่, ปิดที่ footer.php (</main>) -->
    <main class="flex-grow">