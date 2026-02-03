<?php
require_once __DIR__ . '/functions.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$user = isLoggedIn() ? getCurrentUser() : null;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' - ' : '' ?><?= APP_NAME ?></title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap Icons (Keep for icons compatibility) -->
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
    <nav class="bg-white shadow-sm sticky top-0 z-50 transition-all duration-300 backdrop-blur-md bg-opacity-90 border-b border-gray-100">
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
                        
                        <!-- Profile Dropdown -->
                        <div class="relative group ml-3">
                            <button class="flex items-center space-x-2 text-sm font-medium text-gray-700 hover:text-primary-600 focus:outline-none transition-colors">
                                <span class="w-8 h-8 rounded-full bg-primary-100 text-primary-600 flex items-center justify-center border border-primary-200">
                                    <i class="bi bi-person-fill"></i>
                                </span>
                                <span><?= e($user['name'] ?? 'ผู้ใช้') ?></span>
                                <i class="bi bi-chevron-down text-xs text-gray-400 group-hover:text-primary-500 transition-colors"></i>
                            </button>
                            
                            <!-- Dropdown Menu -->
                            <div class="absolute right-0 mt-2 w-48 bg-white rounded-xl shadow-lg py-1 border border-gray-100 opacity-0 invisible group-hover:opacity-100 group-hover:visible transform group-hover:translate-y-0 translate-y-2 transition-all duration-200 z-50">
                                <div class="px-4 py-3 border-b border-gray-50">
                                    <p class="text-sm text-gray-500">สวัสดี,</p>
                                    <p class="text-sm font-medium text-gray-900 truncate"><?= e($user['name'] ?? '') ?></p>
                                </div>
                                <a href="<?= APP_URL ?>/profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-primary-600 transition-colors">
                                    <i class="bi bi-person mr-2 text-gray-400"></i>โปรไฟล์
                                </a>
                                <a href="<?= APP_URL ?>/my_reservations.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-primary-600 transition-colors">
                                    <i class="bi bi-bookmark-check mr-2 text-gray-400"></i>รายการจองของฉัน
                                </a>
                                <div class="border-t border-gray-50 my-1"></div>
                                <a href="<?= APP_URL ?>/logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
                                    <i class="bi bi-box-arrow-right mr-2"></i>ออกจากระบบ
                                </a>
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
                    <a href="<?= APP_URL ?>/my_reservations.php" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-primary-600 hover:bg-gray-50">
                        <i class="bi bi-bookmark-check mr-2"></i>รายการจองของฉัน
                    </a>
                    <a href="<?= APP_URL ?>/logout.php" class="block px-3 py-2 rounded-md text-base font-medium text-red-600 hover:bg-red-50">
                        <i class="bi bi-box-arrow-right mr-2"></i>ออกจากระบบ
                    </a>
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
    
    <!-- Main Content -->
    <main class="flex-grow">

