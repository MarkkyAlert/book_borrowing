<?php
/**
 * Homepage - หน้าแรก
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';

$pdo = getDB();

// Get search/filter parameters
$search = trim($_GET['search'] ?? '');
$categoryId = (int) ($_GET['category'] ?? 0);
$status = $_GET['status'] ?? '';

// Build query
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(b.title LIKE ? OR b.author LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($categoryId > 0) {
    $where[] = "b.category_id = ?";
    $params[] = $categoryId;
}

if ($status === 'available') {
    $where[] = "b.available > 0";
}

$whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get books
$sql = "
    SELECT b.*, c.name as category_name 
    FROM books b
    LEFT JOIN categories c ON b.category_id = c.id
    $whereSQL
    ORDER BY b.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$books = $stmt->fetchAll();

// Get categories for filter
$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();

// Get stats (using available column now)
$totalBooks = $pdo->query("SELECT SUM(quantity) FROM books")->fetchColumn() ?: 0;
$availableBooks = $pdo->query("SELECT SUM(available) FROM books")->fetchColumn() ?: 0;
$totalMembers = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn();

$pageTitle = 'หน้าแรก';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Hero Section -->
<section class="relative bg-primary-900 overflow-hidden mb-10">
    <!-- Decorative background elements -->
    <div class="absolute inset-0 z-0">
        <div class="absolute top-0 right-0 -mr-20 -mt-20 w-96 h-96 rounded-full bg-primary-500 opacity-20 blur-3xl"></div>
        <div class="absolute bottom-0 left-0 -ml-20 -mb-20 w-80 h-80 rounded-full bg-blue-500 opacity-20 blur-3xl"></div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 py-16 lg:py-24">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
            <div class="text-white space-y-6">
                <div class="inline-flex items-center px-3 py-1 rounded-full border border-primary-500 bg-primary-800/50 text-primary-200 text-sm font-medium backdrop-blur-sm">
                    <span class="flex h-2 w-2 rounded-full bg-green-400 mr-2 animate-pulse"></span>
                    ระบบห้องสมุดออนไลน์
                </div>
                <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold leading-tight tracking-tight">
                    ค้นหาและยืมหนังสือ<br>
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-300 to-white">ได้ง่ายๆ สะดวก รวดเร็ว</span>
                </h1>
                <p class="text-lg text-primary-100 max-w-lg leading-relaxed">
                    แหล่งรวมความรู้ที่เข้าถึงได้ทุกที่ทุกเวลา ค้นหาหนังสือที่คุณต้องการ ตรวจสอบสถานะ และยืมคืนได้ทันที
                </p>
                <div class="flex flex-wrap gap-4 pt-4">
                    <a href="#search-section" class="bg-white text-primary-900 hover:bg-gray-50 px-8 py-3.5 rounded-xl font-bold transition-all shadow-lg hover:shadow-white/20 hover:-translate-y-1">
                        <i class="bi bi-search mr-2"></i>ค้นหาหนังสือ
                    </a>
                    <?php if (!isLoggedIn()): ?>
                        <a href="<?= APP_URL ?>/register.php" class="bg-primary-700/50 hover:bg-primary-700 text-white border border-primary-600 px-8 py-3.5 rounded-xl font-semibold backdrop-blur-md transition-all hover:-translate-y-1">
                            สมัครสมาชิก
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="hidden lg:block">
                <div class="bg-white/10 backdrop-blur-md border border-white/20 p-6 rounded-2xl shadow-2xl transform rotate-2 hover:rotate-0 transition-all duration-500">
                    <div class="grid grid-cols-2 gap-6">
                        <div class="bg-gradient-to-br from-blue-500 to-blue-600 p-6 rounded-xl text-white shadow-lg shadow-blue-500/30">
                            <div class="text-4xl font-bold mb-2"><?= number_format($totalBooks) ?></div>
                            <div class="text-blue-100 flex items-center">
                                <i class="bi bi-book-half mr-2"></i>หนังสือทั้งหมด
                            </div>
                        </div>
                        <div class="bg-gradient-to-br from-green-500 to-emerald-600 p-6 rounded-xl text-white shadow-lg shadow-green-500/30">
                            <div class="text-4xl font-bold mb-2"><?= number_format($availableBooks) ?></div>
                            <div class="text-green-100 flex items-center">
                                <i class="bi bi-check-circle mr-2"></i>พร้อมให้ยืม
                            </div>
                        </div>
                        <div class="col-span-2 bg-primary-800/80 p-6 rounded-xl text-white border border-primary-600/50">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-2xl font-bold">เปิดให้บริการ</div>
                                    <div class="text-primary-300 text-sm">ค้นหาได้ตลอด 24 ชั่วโมง</div>
                                </div>
                                <div class="h-12 w-12 bg-primary-700 rounded-full flex items-center justify-center">
                                    <i class="bi bi-clock-history text-xl"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Mobile Stats -->
            <div class="lg:hidden grid grid-cols-2 gap-4">
                <div class="bg-white/10 backdrop-blur-md border border-white/10 p-4 rounded-xl text-white">
                    <div class="text-3xl font-bold"><?= number_format($totalBooks) ?></div>
                    <div class="text-sm text-primary-200">หนังสือทั้งหมด</div>
                </div>
                <div class="bg-white/10 backdrop-blur-md border border-white/10 p-4 rounded-xl text-white">
                    <div class="text-3xl font-bold"><?= number_format($availableBooks) ?></div>
                    <div class="text-sm text-primary-200">พร้อมให้ยืม</div>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-20" id="search-section">
    <?php displayFlash(); ?>
    
    <!-- Search & Filter Card -->
    <div class="bg-white rounded-2xl shadow-xl -mt-16 relative z-20 border border-gray-100 overflow-hidden mb-12">
        <div class="p-1 bg-gradient-to-r from-primary-400 via-blue-500 to-purple-500"></div>
        <div class="p-6 md:p-8">
            <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center">
                <span class="bg-primary-100 text-primary-600 p-2 rounded-lg mr-3">
                    <i class="bi bi-sliders"></i>
                </span>
                ค้นหาและกรองหนังสือ
            </h3>
            
            <form method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-6">
                <div class="md:col-span-5">
                    <label class="block text-sm font-medium text-gray-700 mb-2">คำค้นหา</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="bi bi-search text-gray-400"></i>
                        </div>
                        <input 
                            type="text" 
                            name="search" 
                            value="<?= e($search) ?>"
                            class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-xl leading-5 bg-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-shadow sm:text-sm"
                            placeholder="ชื่อหนังสือ, ผู้แต่ง..."
                        >
                    </div>
                </div>
                
                <div class="md:col-span-3">
                    <label class="block text-sm font-medium text-gray-700 mb-2">หมวดหมู่</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="bi bi-bookmark text-gray-400"></i>
                        </div>
                        <select name="category" class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-xl leading-5 bg-white focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-shadow sm:text-sm appearance-none">
                            <option value="">ทั้งหมด</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $categoryId == $cat['id'] ? 'selected' : '' ?>>
                                    <?= e($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                            <i class="bi bi-chevron-down text-xs text-gray-400"></i>
                        </div>
                    </div>
                </div>
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">สถานะ</label>
                    <select name="status" class="block w-full pl-3 pr-8 py-3 border border-gray-300 rounded-xl leading-5 bg-white focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-shadow sm:text-sm">
                        <option value="">ทั้งหมด</option>
                        <option value="available" <?= $status === 'available' ? 'selected' : '' ?>>ว่าง</option>
                    </select>
                </div>
                
                <div class="md:col-span-2 flex items-end">
                    <button type="submit" class="w-full bg-primary-600 hover:bg-primary-700 text-white font-bold py-3 px-4 rounded-xl focus:outline-none focus:shadow-outline transition duration-150 ease-in-out shadow-md shadow-primary-500/30 flex justify-center items-center">
                        <i class="bi bi-search mr-2"></i> ค้นหา
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Results Header -->
    <div class="flex flex-col sm:flex-row justify-between items-center mb-8 gap-4">
        <h2 class="text-2xl font-bold text-gray-800 flex items-center">
            <span class="text-primary-600 mr-3">
                <i class="bi bi-grid-fill"></i>
            </span>
            รายการหนังสือ
            <span id="result-count" class="ml-3 px-3 py-1 bg-gray-100 text-gray-600 text-sm rounded-full font-medium border border-gray-200">
                <?= count($books) ?> เล่ม
            </span>
        </h2>
        
        <button id="clear-filters" type="button" class="hidden text-sm text-gray-500 hover:text-red-600 transition-colors flex items-center bg-white px-4 py-2 rounded-lg border border-gray-200 hover:border-red-300 shadow-sm">
            <i class="bi bi-x-circle mr-2"></i> ล้างตัวกรองทั้งหมด
        </button>
    </div>
    
    <!-- Books Grid Container -->
    <div id="book-grid-container" class="min-h-[300px] relative">
        <?php require __DIR__ . '/includes/book_grid.php'; ?>
        
        <!-- Loading Overlay -->
        <div id="grid-loading" class="hidden absolute inset-0 bg-white/50 backdrop-blur-sm z-10 flex items-center justify-center rounded-2xl transition-opacity duration-300">
            <div class="flex flex-col items-center">
                <div class="w-12 h-12 border-4 border-primary-200 border-t-primary-600 rounded-full animate-spin mb-4"></div>
                <p class="text-primary-600 font-bold">กำลังค้นหา...</p>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchForm = document.querySelector('form');
    const searchInput = document.querySelector('input[name="search"]');
    const categorySelect = document.querySelector('select[name="category"]');
    const statusSelect = document.querySelector('select[name="status"]');
    const gridContainer = document.getElementById('book-grid-container');
    const resultCount = document.getElementById('result-count');
    const clearBtn = document.getElementById('clear-filters');
    const loadingOverlay = document.getElementById('grid-loading');
    
    let debounceTimer;

    // Prevent default form submission
    searchForm.addEventListener('submit', function(e) {
        e.preventDefault();
        fetchBooks();
    });

    // Inputs listener (Only toggle clear button, NO FETCH)
    searchInput.addEventListener('input', function() {
        toggleClearButton();
    });

    categorySelect.addEventListener('change', () => {
        toggleClearButton();
    });

    statusSelect.addEventListener('change', () => {
        toggleClearButton();
    });
    
    // Clear filters
    clearBtn.addEventListener('click', function() {
        searchInput.value = '';
        categorySelect.value = '';
        statusSelect.value = '';
        toggleClearButton();
        fetchBooks();
    });
    
    // Initial check
    toggleClearButton();

    function toggleClearButton() {
        if (searchInput.value || categorySelect.value || statusSelect.value) {
            clearBtn.classList.remove('hidden');
            clearBtn.style.display = 'flex'; // Ensure flex display
        } else {
            clearBtn.classList.add('hidden');
            clearBtn.style.display = 'none';
        }
    }

    function fetchBooks() {
        // Show loading
        loadingOverlay.classList.remove('hidden');
        
        // Build URL
        const params = new URLSearchParams({
            search: searchInput.value,
            category: categorySelect.value,
            status: statusSelect.value
        });
        
        // Update URL without reload (History API)
        const newUrl = `${window.location.pathname}?${params.toString()}`;
        window.history.pushState({path: newUrl}, '', newUrl);

        // Fetch API
        fetch(`api/search_books.php?${params.toString()}`)
            .then(response => response.text())
            .then(html => {
                // Update grid content (keep loading overlay wrapper)
                // We need to carefully replace only the grid content, not the overlay
                
                // Create temp wrapper
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = html;
                
                // Extract grid or empty message
                // The API returns exactly what includes/book_grid.php outputs
                
                // Remove existing content except loading overlay
                const children = Array.from(gridContainer.children);
                children.forEach(child => {
                    if (child.id !== 'grid-loading') {
                        child.remove();
                    }
                });
                
                // Append new content before loading overlay
                while (tempDiv.firstChild) {
                    gridContainer.insertBefore(tempDiv.firstChild, loadingOverlay);
                }
                
                // Update result count
                // We can't easily parse PHP count from JS without API returning JSON
                // So let's approximate or update API to return count header
                // For now, let's count elements with class 'group'
                const count = gridContainer.querySelectorAll('.group').length;
                resultCount.textContent = `${count} เล่ม`;
                
                // Hide loading
                loadingOverlay.classList.add('hidden');
            })
            .catch(error => {
                console.error('Error:', error);
                loadingOverlay.classList.add('hidden');
            });
    }
});
</script>
</div>

<footer class="bg-gray-900 text-gray-400 py-12 text-sm">
    <div class="max-w-7xl mx-auto px-4 text-center">
        <div class="mb-6 flex justify-center">
             <div class="w-12 h-12 bg-gray-800 rounded-lg flex items-center justify-center text-white text-2xl">
                <i class="bi bi-book-half"></i>
             </div>
        </div>
        <p>&copy; <?= date('Y') ?> <?= APP_NAME ?>. All rights reserved.</p>
        <p class="mt-2 text-gray-600">ออกแบบและพัฒนาด้วย ❤️</p>
    </div>
</footer>
</body>
</html>
