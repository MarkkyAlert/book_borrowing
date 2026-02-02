<?php
/**
 * Admin Header Template
 */

require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$currentPage = basename($_SERVER['PHP_SELF']);
$user = getCurrentUser();

// Handle case where user was deleted but session exists
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    

    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
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
                    <span>Dashboard</span>
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
                    <span>ประวัติการเงิน</span>
                </a>
                
                <?php if (isAdmin()): ?>
                <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-primary-100 hover:text-white <?= $currentPage === 'members.php' ? 'active' : 'border-l-4 border-transparent' ?>" href="<?= APP_URL ?>/admin/members.php">
                    <i class="bi bi-people text-lg"></i>
                    <span>สมาชิก</span>
                </a>
                
                <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-primary-100 hover:text-white <?= $currentPage === 'reports.php' ? 'active' : 'border-l-4 border-transparent' ?>" href="<?= APP_URL ?>/admin/reports.php">
                    <i class="bi bi-bar-chart-line text-lg"></i>
                    <span>รายงาน</span>
                </a>
                <?php endif; ?>
                
                <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-primary-100 hover:text-white <?= $currentPage === 'settings.php' ? 'active' : 'border-l-4 border-transparent' ?>" href="<?= APP_URL ?>/admin/settings.php">
                    <i class="bi bi-gear-fill text-lg"></i>
                    <span>ตั้งค่าระบบ</span>
                </a>
                
                <hr class="border-primary-800 my-4 mx-2">
                
                <p class="px-3 text-xs font-semibold text-primary-300 uppercase tracking-wider mb-2">ทั่วไป</p>
                
                <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-primary-100 hover:text-white border-l-4 border-transparent" href="<?= APP_URL ?>">
                    <i class="bi bi-house text-lg"></i>
                    <span>หน้าเว็บไซต์</span>
                </a>
                
                <a class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-red-300 hover:text-red-100 hover:bg-red-900/30 border-l-4 border-transparent transition-colors" href="<?= APP_URL ?>/logout.php">
                    <i class="bi bi-box-arrow-right text-lg"></i>
                    <span>ออกจากระบบ</span>
                </a>
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
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/">Dashboard</a>
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/categories.php">หมวดหมู่</a>
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/books.php">หนังสือ</a>
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/borrows.php">ยืม-คืน</a>
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/book_labels.php">พิมพ์บาร์โค้ด</a>
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/reservations.php">รายการจอง</a>
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/payments.php">ประวัติการเงิน</a>
                    <?php if (isAdmin()): ?>
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/members.php">สมาชิก</a>
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/reports.php">รายงาน</a>
                    <?php endif; ?>
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>/admin/settings.php">ตั้งค่าระบบ</a>
                    <hr class="border-primary-700 my-2">
                    <a class="block px-4 py-2 text-primary-100 hover:bg-primary-800 rounded" href="<?= APP_URL ?>">หน้าเว็บไซต์</a>
                    <a class="block px-4 py-2 text-red-300 hover:bg-red-900/50 rounded" href="<?= APP_URL ?>/logout.php">ออกจากระบบ</a>
                 </nav>
             </div>
        </div>

        <!-- Desktop Header & Main Content -->
        <header class="bg-white shadow-sm h-16 flex items-center justify-between px-8 z-10 hidden md:flex">
            <h2 class="text-xl font-bold text-gray-800"><?= $pageTitle ?? 'Admin Panel' ?></h2>
            <div class="flex items-center gap-4">
                <button class="text-gray-400 hover:text-primary-600 transition-colors relative">
                    <i class="bi bi-bell text-xl"></i>
                    <span class="absolute top-0 right-0 block h-2 w-2 rounded-full ring-2 ring-white bg-red-500"></span>
                </button>
            </div>
        </header>

        <!-- Main Content Scroll Area -->
        <main class="flex-1 overflow-y-auto bg-gray-50 p-6 md:p-8">
            <div class="max-w-7xl mx-auto">
                <div class="mb-6 md:hidden">
                    <h2 class="text-2xl font-bold text-gray-800"><?= $pageTitle ?? 'Admin Panel' ?></h2>
                </div>
                <?php displayFlash(); ?>

