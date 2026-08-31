<?php
/**
 * Admin Header Template - layout wrapper สำหรับทุกหน้า admin/
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - ทุกหน้าใน admin/ จะ require ไฟล์นี้หลังจาก logic เสร็จ
 * - ประกอบด้วย: <head>, top navbar, sidebar menu, เปิด <main>
 * - ปิด </main> อยู่ที่ admin/footer.php
 * - ต้องตั้ง $pageTitle ก่อน require เพื่อแสดงชื่อหน้าใน title bar
 * 
 * 📂 การใช้งาน (ในแต่ละหน้า admin/):
 *   $pageTitle = 'ชื่อหน้า';
 *   require_once __DIR__ . '/header.php';
 *   // ... HTML content ...
 *   require_once __DIR__ . '/footer.php';
 */

// 🔌 โหลด helper functions (e(), redirect(), requireStaff(), ฯลฯ)
require_once __DIR__ . '/../includes/functions.php';
// 🔒 [AUTH] ตรวจสิทธิ์ซ้ำในระดับ template — defense-in-depth
//    แม้แต่ละหน้าจะตรวจแล้ว แต่ถ้า dev ลืมเรียก requireStaff() ในหน้าใหม่
//    header.php จะเป็น safety net ให้
requireStaff();

// 🔔 ตัวเลขสำหรับกระดิ่งแจ้งเตือน
//    🔴 [PERFORMANCE] ไฟล์นี้ถูก include ทุกหน้าแอดมิน (16 หน้า)
//       getAlertCounts() จึงรวมทุกอย่างไว้ใน query เดียว (~10 ms) และ cache ต่อ request
//       **ห้ามเปลี่ยนไปเรียก getCardStats()** ซึ่งใช้ ~22 ms และดึงของที่กระดิ่งไม่ใช้
//    🛡️ ถ้าดึงไม่ได้ (DB มีปัญหา) ให้กระดิ่งเงียบ ไม่ใช่ทำให้ทั้งหน้าพัง
require_once __DIR__ . '/../app/Services/DashboardService.php';
try {
    $alertCounts = (new \App\Services\DashboardService(getDB()))->getAlertCounts();
} catch (\Throwable $e) {
    $alertCounts = ['overdue' => 0, 'due_soon' => 0, 'pending_reservations' => 0, 'unpaid_people' => 0, 'total' => 0];
}

/**
 * 🔴 [H1-H5] สุขภาพระบบ — สภาวะที่ตรวจได้แต่เดิมไม่มีที่ไหนบอกใครเลย
 *
 * 🧠 แยกกลุ่มจาก "สิ่งที่ต้องจัดการ" โดยเจตนา เพราะเป็นคนละชนิดของงาน:
 *    ด้านบนคืองานประจำวัน (โทรตามคนคืนหนังสือ) ที่เกิดใหม่ทุกวันเป็นเรื่องปกติ
 *    ด้านล่างคือของที่ "ไม่ควรเกิด" ปกติต้องว่างเปล่า ถ้ามีขึ้นมาคือมีอะไรผิดจริง
 *
 * 🧠 ที่ไม่เอาลงกระดิ่ง: หนังสือไม่มีเลขเรียก/ไม่มีปก — เป็นงานค้างถาวรหลักร้อยเล่ม
 *    ใส่แล้วกระดิ่งจะแดงตลอดกาลจนทุกคนเลิกมอง ของพวกนั้นอยู่ที่ตัวกรองในหน้ารายการ
 */
try {
    $systemHealth = (new \App\Services\DashboardService(getDB()))->getSystemHealth();
} catch (\Throwable $e) {
    $systemHealth = ['items' => [], 'total' => 0, 'admin_total' => 0];
}

// 🛡️ H3 (ไฟล์ติดตั้ง) และ H4 (โหมดพัฒนา) ให้เฉพาะ admin
//    ไม่ใช่เพราะเป็นความลับ — เจ้าหน้าที่เข้าหลังบ้านได้อยู่แล้ว
//    แต่เพราะ **เจ้าหน้าที่แก้ไม่ได้** ทั้งสองอย่างต้องแก้ไฟล์บนเซิร์ฟเวอร์/.env
//    คำเตือนที่ผู้เห็นลงมือไม่ได้ = noise ที่ลบไม่ออก แล้วคนจะเลิกเชื่อกระดิ่งทั้งใบ
$healthItems = array_values(array_filter(
    $systemHealth['items'],
    fn($h) => isAdmin() || !$h['admin_only']
));

/**
 * 🔗 รายการในกระดิ่ง — เฉพาะเรื่องที่ **ต้องลงมือทำ**
 *
 * 🔴 ปลายทางต้องเปิดได้ด้วยสิทธิ์ของคนที่เห็น
 *    `reports.php` เป็น **admin เท่านั้น** ส่วนหน้าอื่นเป็น staff ได้
 *    เจ้าหน้าที่จึงถูกพาไป `borrows.php?filter=due_today` แทน
 *    (กระดิ่งที่พาไปหน้าที่กดแล้วเด้งออก แย่กว่าไม่มีกระดิ่ง)
 */
$alertItems = [];
if ($alertCounts['overdue'] > 0) {
    $alertItems[] = ['label' => 'เกินกำหนดคืน', 'count' => $alertCounts['overdue'], 'unit' => 'รายการ',
                     'icon' => 'bi-exclamation-triangle', 'tone' => 'red',
                     'url' => 'borrows.php?filter=overdue'];
}
if ($alertCounts['due_soon'] > 0) {
    $alertItems[] = ['label' => 'ใกล้ครบกำหนด', 'count' => $alertCounts['due_soon'], 'unit' => 'รายการ',
                     'icon' => 'bi-telephone', 'tone' => 'sky',
                     'url' => isAdmin() ? 'reports.php?report=due_soon' : 'borrows.php?filter=due_today'];
}
if ($alertCounts['pending_reservations'] > 0) {
    $alertItems[] = ['label' => 'จองรอมารับ', 'count' => $alertCounts['pending_reservations'], 'unit' => 'รายการ',
                     'icon' => 'bi-bookmark-star', 'tone' => 'indigo',
                     'url' => 'reservations.php'];
}
if ($alertCounts['unpaid_people'] > 0) {
    $alertItems[] = ['label' => 'ค้างชำระค่าปรับ', 'count' => $alertCounts['unpaid_people'], 'unit' => 'คน',
                     'icon' => 'bi-cash-coin', 'tone' => 'amber',
                     'url' => 'payments.php'];
}

// 🔔 ตัวเลขบนกระดิ่ง = งานประจำวัน + สุขภาพระบบเท่าที่คนนี้เห็น
//    ต้องบวกด้วย ไม่งั้นเปิดหน้ามาเจอกระดิ่งเงียบ ทั้งที่ข้างในมีเรื่องด่วน
$bellTotal = (int) $alertCounts['total'] + count($healthItems);

// 📍 ระบุหน้าปัจจุบัน — ใช้ highlight active menu item ใน sidebar
$currentPage = basename($_SERVER['PHP_SELF']);
// 👤 ดึงข้อมูล user จาก session → DB (ชื่อ, role, ฯลฯ)
$user = getCurrentUser();

// 🛡️ [SECURITY] Session orphan protection:
//    ถ้า user ถูกลบจาก DB แต่ session ยังอยู่ → destroy session แล้ว redirect
//    ป้องกัน ghost session ที่อ้างถึง user ที่ไม่มีอยู่แล้ว
if (!$user) {
    session_destroy();
    redirect(APP_URL . '/login.php');
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' - ' : '' ?>Admin | <?= APP_NAME ?></title>
    
    <!-- Google Fonts -->
    <?php // 🔌 [OFFLINE] asset ทุกตัวอยู่ในโปรเจกต์ — ดู assets/vendor/README.md ?>
    <link href="<?= APP_URL ?>/assets/vendor/fonts/sarabun.css" rel="stylesheet">
    

    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.css">
    
    <!-- Tailwind CSS -->
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
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
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
    
    <style>
        .sidebar-link {
            transition: all 0.2s;
        }
        .sidebar-link:hover, .sidebar-link.active {
            background: rgba(255, 255, 255, 0.1);
            border-left-width: 4px;
            border-color: #60a5fa; /* primary-400 */
        }
        .sidebar-link.active {
            background: rgba(255, 255, 255, 0.15);
            font-weight: 600;
        }

        /* ==========================================================================
         * 🔴 [F-49] บนมือถือ ปุ่มลงมือทำอยู่นอกจอ
         * ==========================================================================
         * วัดจริงที่ 375px: ตารางกว้าง 730–1096px อยู่ในกล่อง 317px
         * ปุ่ม "คืน" / "อนุมัติ" / "ลบ" อยู่นอกจอ 356–640px
         * บรรณารักษ์ต้องลากตารางไปขวาสุดทุกครั้งก่อนกดอะไรได้สักอย่าง
         *
         * 🧠 ทำไมเลือกตรึงคอลัมน์ ไม่เปลี่ยนเป็นการ์ด:
         *    การ์ดต้องเขียน markup ของทุกแถวซ้ำสองชุด (ตาราง + การ์ด) ใน 6 ตาราง
         *    แล้วสองชุดนั้นจะเพี้ยนจากกันเมื่อมีคนแก้ทีหลัง — บั๊กที่หาไม่เจอ
         *    จนกว่าจะเปิดมือถือดู · วิธีนี้เป็น CSS ล้วน ใช้ร่วมกันทุกหน้า
         *
         * 🧠 ใช้ :last-child แทนการติดคลาสที่ทุก th/td:
         *    ติดคลาสเดียวที่ตัว table พอ — ไม่มีทางใส่ครึ่ง ๆ (ใส่ th ลืม td)
         *    ⚠️ จึงใช้ได้เฉพาะตารางที่คอลัมน์ปุ่มอยู่ **ท้ายสุด** เท่านั้น
         *       ตารางอ่านอย่างเดียว (ประวัติการชำระ / ประวัติการยืมใน modal)
         *       ไม่ต้องติดคลาสนี้ เพราะไม่มีปุ่มให้กด
         *
         * ⚠️ ข้อจำกัดที่ยังเหลือ: ตรึงปุ่มทำให้ "กดได้" แต่ยังต้องเลื่อนเพื่อ "อ่านครบ"
         *    กล่องยืนยันของ F-47 ชดเชยตรงนี้ — บอกชื่อเล่ม/ชื่อคน/ค่าปรับ ก่อนลงมือ
         */
        @media (max-width: 767px) {
            .sticky-action th:last-child,
            .sticky-action td:last-child {
                position: sticky;
                right: 0;
                /* 🎨 ต้องทึบ ไม่งั้นข้อมูลที่เลื่อนอยู่ข้างหลังทะลุมาซ้อน */
                background-color: #ffffff;
                /* เส้น + เงาซ้าย บอกว่าคอลัมน์นี้ลอยอยู่ ไม่ได้เลื่อนไปกับตาราง */
                border-left: 1px solid #e5e7eb;
                box-shadow: -6px 0 8px -6px rgba(15, 23, 42, 0.25);
            }
            /* หัวตารางต้องตรึงด้วย ไม่งั้นหัวกับข้อมูลเหลื่อมกันตอนเลื่อน
               และต้องทับข้อมูลได้ จึงให้ z สูงกว่า */
            .sticky-action thead th:last-child {
                background-color: #f9fafb;  /* = bg-gray-50 ของหัวตาราง */
                z-index: 2;
            }
            .sticky-action tbody td:last-child {
                z-index: 1;
            }
        }

        @media print {
            aside, header, #mobile-sidebar, .no-print, button, form {
                display: none !important;
            }
            html, body {
                height: auto !important;
                overflow: visible !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            body {
                display: block !important;
                margin: 0 !important;
                padding: 10px !important;
            }
            main, .max-w-7xl, .flex-1 {
                display: block !important;
                height: auto !important;
                overflow: visible !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            .overflow-hidden, .overflow-y-auto, .overflow-x-auto {
                overflow: visible !important;
                height: auto !important;
            }
            .bg-gray-100, .bg-gray-50 {
                background: white !important;
            }
            .shadow-sm, .shadow-md, .shadow-lg {
                box-shadow: none !important;
            }
            .rounded-xl, .rounded-2xl {
                border-radius: 0 !important;
            }
            table {
                width: 100% !important;
                border-collapse: collapse !important;
                page-break-inside: auto !important;
            }
            tr {
                page-break-inside: avoid !important;
                page-break-after: auto !important;
            }
            thead {
                display: table-header-group !important;
            }
            th, td {
                border: 1px solid #ddd !important;
                padding: 6px 8px !important;
                font-size: 11px !important;
            }
            .mb-6, .mb-8 {
                margin-bottom: 15px !important;
            }
            /* Page breaks */
            .page-break {
                page-break-before: always !important;
            }
        }
    </style>
</head>
<body class="bg-gray-100 font-sans antialiased h-screen flex overflow-hidden">
    
    <!-- Sidebar -->
    <aside class="w-64 bg-primary-900 text-white flex-shrink-0 hidden md:flex flex-col shadow-xl z-20">
        <div class="h-16 flex items-center px-6 border-b border-primary-800 bg-primary-950">
            <a href="<?= APP_URL ?>" class="flex items-center gap-3 text-white hover:text-primary-200 transition-colors">
                <div class="w-8 h-8 rounded bg-primary-600 flex items-center justify-center shadow-lg">
                    <i class="bi bi-book-half"></i>
                </div>
                <h5 class="font-bold text-lg tracking-wide"><?= APP_NAME ?></h5>
            </a>
        </div>
        
        <div class="flex-1 overflow-y-auto py-4">
            <nav class="space-y-1 px-3">
                <p class="px-3 text-xs font-semibold text-primary-300 uppercase tracking-wider mb-2">เมนูหลัก</p>
                
                <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-primary-100 hover:text-white <?= $currentPage === 'index.php' ? 'active' : 'border-l-4 border-transparent' ?>" href="<?= APP_URL ?>/admin/">
                    <i class="bi bi-speedometer2 text-lg"></i>
                    <span>ภาพรวม</span>
                </a>
                
                <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-primary-100 hover:text-white <?= $currentPage === 'categories.php' ? 'active' : 'border-l-4 border-transparent' ?>" href="<?= APP_URL ?>/admin/categories.php">
                    <i class="bi bi-bookmark text-lg"></i>
                    <span>หมวดหมู่</span>
                </a>
                
                <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-primary-100 hover:text-white <?= in_array($currentPage, ['books.php', 'book_form.php']) ? 'active' : 'border-l-4 border-transparent' ?>" href="<?= APP_URL ?>/admin/books.php">
                    <i class="bi bi-book text-lg"></i>
                    <span>หนังสือ</span>
                </a>
                
                <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-primary-100 hover:text-white <?= in_array($currentPage, ['borrows.php', 'borrow_form.php']) ? 'active' : 'border-l-4 border-transparent' ?>" href="<?= APP_URL ?>/admin/borrows.php">
                    <i class="bi bi-arrow-left-right text-lg"></i>
                    <span>ยืม-คืน</span>
                </a>

                <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-primary-100 hover:text-white <?= $currentPage === 'book_labels.php' ? 'active' : 'border-l-4 border-transparent' ?>" href="<?= APP_URL ?>/admin/book_labels.php">
                    <i class="bi bi-upc-scan text-lg"></i>
                    <span>พิมพ์บาร์โค้ด</span>
                </a>

                <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-primary-100 hover:text-white <?= $currentPage === 'reservations.php' ? 'active' : 'border-l-4 border-transparent' ?>" href="<?= APP_URL ?>/admin/reservations.php">
                    <i class="bi bi-bookmark-star text-lg"></i>
                    <span>รายการจอง</span>
                </a>

                <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-primary-100 hover:text-white <?= $currentPage === 'payments.php' ? 'active' : 'border-l-4 border-transparent' ?>" href="<?= APP_URL ?>/admin/payments.php">
                    <i class="bi bi-receipt text-lg"></i>
                    <span>ค่าปรับ</span>
                </a>
                
                <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-primary-100 hover:text-white <?= in_array($currentPage, ['members.php', 'member_form.php']) ? 'active' : 'border-l-4 border-transparent' ?>" href="<?= APP_URL ?>/admin/members.php">
                    <i class="bi bi-people text-lg"></i>
                    <span>สมาชิก</span>
                </a>
                
                <?php if (isAdmin()): ?>
                <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-primary-100 hover:text-white <?= $currentPage === 'reports.php' ? 'active' : 'border-l-4 border-transparent' ?>" href="<?= APP_URL ?>/admin/reports.php">
                    <i class="bi bi-bar-chart-line text-lg"></i>
                    <span>รายงาน</span>
                </a>
                
                <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-primary-100 hover:text-white <?= $currentPage === 'settings.php' ? 'active' : 'border-l-4 border-transparent' ?>" href="<?= APP_URL ?>/admin/settings.php">
                    <i class="bi bi-gear-fill text-lg"></i>
                    <span>ตั้งค่าระบบ</span>
                </a>
                <?php endif; ?>
                
                <hr class="border-primary-800 my-4 mx-2">
                
                <p class="px-3 text-xs font-semibold text-primary-300 uppercase tracking-wider mb-2">ทั่วไป</p>
                
                <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-primary-100 hover:text-white border-l-4 border-transparent" href="<?= APP_URL ?>">
                    <i class="bi bi-house text-lg"></i>
                    <span>หน้าเว็บไซต์</span>
                </a>
                
                <form method="POST" action="<?= APP_URL ?>/logout.php">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <button type="submit" class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-red-300 hover:text-red-100 hover:bg-red-900/30 border-l-4 border-transparent transition-colors w-full text-left">
                        <i class="bi bi-box-arrow-right text-lg"></i>
                        <span>ออกจากระบบ</span>
                    </button>
                </form>
            </nav>
        </div>
        
        <div class="p-4 border-t border-primary-800 bg-primary-950">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-primary-800 flex items-center justify-center text-primary-200">
                    <i class="bi bi-person-fill text-xl"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-white"><?= e($user['name']) ?></p>
                    <p class="text-xs text-primary-400"><?= ucfirst($user['role']) ?></p>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main Content Wrapper -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        
        <!-- Top Mobile Header -->
        <header class="md:hidden bg-primary-900 text-white p-4 flex justify-between items-center shadow-md z-20">
            <a href="<?= APP_URL ?>" class="flex items-center gap-2 font-bold text-lg">
                <i class="bi bi-book-half"></i> <?= APP_NAME ?>
            </a>
            <button onclick="document.getElementById('mobile-sidebar').classList.toggle('hidden')" class="text-white p-1">
                <i class="bi bi-list text-2xl"></i>
            </button>
        </header>
        
        <!-- Mobile Sidebar Overlay -->
        <div id="mobile-sidebar" class="hidden absolute inset-0 z-30 bg-gray-800 bg-opacity-75 md:hidden" onclick="this.classList.add('hidden')">
             <div class="w-64 bg-primary-900 h-full shadow-xl overflow-y-auto" onclick="event.stopPropagation()">
                 <!-- Mobile Menu Content (Clone of sidebar nav) -->
                 <div class="p-4 border-b border-primary-800">
                     <h5 class="font-bold text-white"><?= APP_NAME ?> Admin</h5>
                 </div>
                 <nav class="p-4 space-y-2">
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/">ภาพรวม</a>
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/categories.php">หมวดหมู่</a>
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/books.php">หนังสือ</a>
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/borrows.php">ยืม-คืน</a>
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/book_labels.php">พิมพ์บาร์โค้ด</a>
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/reservations.php">รายการจอง</a>
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/payments.php">ค่าปรับ</a>
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/members.php">สมาชิก</a>
                    <?php if (isAdmin()): ?>
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/reports.php">รายงาน</a>
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/settings.php">ตั้งค่าระบบ</a>
                    <?php endif; ?>
                    <hr class="border-primary-700 my-2">
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>">หน้าเว็บไซต์</a>
                    <form method="POST" action="<?= APP_URL ?>/logout.php" class="block">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <button type="submit" class="w-full text-left px-4 py-2 text-red-300 hover:bg-red-900/50 rounded">ออกจากระบบ</button>
                    </form>
                 </nav>
             </div>
        </div>

        <!-- Desktop Header & Main Content -->
        <header class="bg-white shadow-sm h-16 flex items-center justify-between px-8 z-10 hidden md:flex">
            <h2 class="text-xl font-bold text-gray-800"><?= $pageTitle ?? 'Admin Panel' ?></h2>
            <div class="flex items-center gap-4">
                <?php // 🔔 กระดิ่งแจ้งเตือน
                      //    🔴 เดิมเป็นปุ่มหลอก — ไม่มี onclick ไม่มีลิงก์ และจุดแดงเป็น HTML ตายตัว
                      //       แดงตลอดแม้ไม่มีอะไรค้าง ผู้ดูแลเห็นทุกวันจนชิน วันที่ด่วนจริงเลยไม่สังเกต
                      //    ตอนนี้จุดแดงขึ้น **เฉพาะตอนมีของจริง** และกดแล้วไปหน้าที่ต้องทำงานได้ ?>
                <?php // 🧠 ใช้ vanilla JS แบบเดียวกับเมนูมือถือด้านบน (classList.toggle)
                      //    โปรเจกต์นี้ไม่มี Alpine.js และ **ห้ามเพิ่มไลบรารีใหม่** — ทั้งระบบต้องใช้ได้ออฟไลน์
                      //    ไฟล์ทุกตัวอยู่ใน assets/vendor/ ไม่มีการดึงจาก CDN ?>
                <div class="relative">
                    <button type="button"
                            onclick="event.stopPropagation(); document.getElementById('alert-dropdown').classList.toggle('hidden')"
                            class="text-gray-400 hover:text-primary-600 transition-colors relative"
                            aria-label="การแจ้งเตือน">
                        <i class="bi bi-bell text-xl"></i>
                        <?php if ($bellTotal > 0): ?>
                            <span class="absolute -top-1 -right-1 min-w-[1.1rem] h-[1.1rem] px-1 flex items-center justify-center
                                         rounded-full ring-2 ring-white bg-red-500 text-white text-[10px] font-bold">
                                <?= $bellTotal > 99 ? '99+' : $bellTotal ?>
                            </span>
                        <?php endif; ?>
                    </button>

                    <div id="alert-dropdown"
                         class="hidden absolute right-0 mt-2 w-80 max-w-[calc(100vw-2rem)] bg-white rounded-xl shadow-lg border border-gray-200 z-50 overflow-hidden">
                        <div class="px-4 py-3 border-b border-gray-100 bg-gray-50/70">
                            <p class="text-sm font-bold text-gray-800">สิ่งที่ต้องจัดการ</p>
                        </div>
                        <?php if (!$alertItems && !$healthItems): ?>
                            <div class="px-4 py-6 text-center text-gray-400">
                                <i class="bi bi-check-circle text-2xl text-green-500 block mb-1"></i>
                                <p class="text-sm">ไม่มีอะไรค้าง</p>
                            </div>
                        <?php else: ?>
                            <?php // 📋 งานประจำวัน — ว่างได้ถ้ามีแต่เรื่องสุขภาพระบบ ?>
                            <?php foreach ($alertItems as $item): ?>
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

                        <?php if ($healthItems): ?>
                            <div class="px-4 py-2 border-t border-b border-gray-100 bg-rose-50/60">
                                <p class="text-xs font-bold text-rose-700 flex items-center gap-1.5">
                                    <i class="bi bi-shield-exclamation"></i> สุขภาพระบบ
                                </p>
                            </div>
                            <?php foreach ($healthItems as $h): ?>
                                <?php
                                    // 🧠 บางข้อแก้ที่หน้าเว็บไม่ได้ (ต้องแก้ไฟล์บนเซิร์ฟเวอร์) → ไม่มีลิงก์
                                    //    ใช้ <div> แทน <a> ไปเลย ดีกว่าลิงก์ที่กดแล้วไม่ไปไหน
                                    $tone = $h['severity'] === 'danger' ? 'rose' : 'amber';
                                    $tag  = $h['url'] ? 'a' : 'div';
                                ?>
                                <<?= $tag ?> <?= $h['url'] ? 'href="' . e($h['url']) . '"' : '' ?>
                                   class="block px-4 py-3 border-b border-gray-50 last:border-0 <?= $h['url'] ? 'hover:bg-gray-50' : '' ?>">
                                    <?php // 🧠 เรียงลงเป็นบรรทัด ไม่วางรายละเอียดไว้ขวามือ
                                          //    ป้ายภาษาไทยยาวกว่าอังกฤษมาก วางคู่กันแล้วป้ายโดนบีบจนตัดคำผิดกลางคำ
                                          //    ("ยังไม่ได้ลบไฟล์ติด" ขึ้นบรรทัดใหม่เป็น "ตั้ง") ?>
                                    <p class="text-sm font-medium text-<?= $tone ?>-700 flex items-start gap-2">
                                        <i class="bi bi-exclamation-octagon mt-0.5 shrink-0"></i>
                                        <span><?= e($h['label']) ?><?php if ($h['detail'] !== ''): ?><span class="font-normal text-gray-500"> · <?= e($h['detail']) ?></span><?php endif; ?></span>
                                    </p>
                                    <p class="text-xs text-gray-500 mt-1 pl-6"><?= e($h['how']) ?></p>
                                </<?= $tag ?>>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php // 📌 คลิกที่อื่นแล้วปิดเมนู — ผูกครั้งเดียวตอนโหลดหน้า ?>
                <script>
                    document.addEventListener('click', function () {
                        var d = document.getElementById('alert-dropdown');
                        if (d) d.classList.add('hidden');
                    });
                </script>
            </div>
        </header>

        <!-- Main Content Scroll Area -->
        <main class="flex-1 overflow-y-auto bg-gray-50 p-6 md:p-8">
            <div class="max-w-7xl mx-auto">
                <div class="mb-6 md:hidden">
                    <h2 class="text-2xl font-bold text-gray-800"><?= $pageTitle ?? 'Admin Panel' ?></h2>
                </div>
                <?php displayFlash(); ?>

