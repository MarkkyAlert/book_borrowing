<?php

/**
 * Books Management - จัดการหนังสือ (รายการ + ลบ)
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้แสดงรายการหนังสือ + filter + ปุ่มลบ
 * - เพิ่ม/แก้ไขอยู่ที่ book_form.php
 * - สิทธิ์: staff ขึ้นไป
 * 
 * 📂 Flow:
 * 1. GET → BookService::getBooks(filters) → แสดงรายการ
 * 2. ปุ่มลบ (ถ้ามี) → ส่ง POST ไปที่ book_form.php หรือ AJAX
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/../bootstrap.php';
// 🔒 [AUTH] staff/admin เท่านั้น
requireStaff();

use App\Services\BookService;         // Business logic: ค้นหา, ลบ (รวม cleanup cover image)
use App\Repositories\CategoryRepository; // ดึงรายการหมวดหมู่สำหรับ filter dropdown

// สร้าง service/repository instances
$pdo = getDB();
$bookService = new BookService($pdo);
$categoryRepo = new CategoryRepository($pdo);

// ── POST: ลบหนังสือ (ทำก่อน fetch data — PRG pattern) ──
// 🧠 ทำ POST ก่อน GET เพื่อให้ข้อมูลที่แสดงเป็น version ล่าสุดหลังลบ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    // [SECURITY] CSRF — ป้องกัน attacker หลอกให้ staff ลบหนังสือโดยไม่รู้ตัว
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง กรุณาลองใหม่');
        redirectToList('books.php', LIST_STATE_BOOKS);
    }

    $id = (int) ($_POST['id'] ?? 0);

    // 🛡️ [IDEMPOTENCY] ป้องกัน double-submit (กดลบซ้ำ)
    $idempotencyKey = 'delete_book_' . $id;
    if (isset($_SESSION['processed_actions'][$idempotencyKey])) {
        setFlash('info', 'รายการนี้ถูกลบไปแล้ว');
        redirectToList('books.php', LIST_STATE_BOOKS);
    }

    try {
        $bookService->deleteBook($id);
        // 🛡️ [IDEMPOTENCY] บันทึกว่า process แล้ว
        $_SESSION['processed_actions'][$idempotencyKey] = time();
        setFlash('success', 'ลบหนังสือสำเร็จ');
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
    }
    redirectToList('books.php', LIST_STATE_BOOKS);
}

// ── GET: ดึงข้อมูลหนังสือตาม filter ──
// รับ filter จาก query string (XSS-safe เพราะ e() ใน HTML)
$search = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? '';
$status = $_GET['status'] ?? '';
$isReference = $_GET['is_reference'] ?? '';   // 📚 '' = ทั้งหมด, '1' = เฉพาะอ้างอิง, '0' = เฉพาะที่ยืมออกได้
$sort = $_GET['sort'] ?? 'newest';
$page = (int) ($_GET['page'] ?? 1);

// 🔧 filter ชุดเดียว ใช้ทั้งนับยอดรวมและดึงรายการ — ต้องเป็นชุดเดียวกันเป๊ะ
//    ไม่งั้นจะบอกจำนวนหน้าผิด (เช่น บอกมี 5 หน้า แต่หน้า 5 ว่างเปล่า)
$bookFilters = [
    'search' => $search,
    'category_id' => $category,
    'status' => in_array($status, ['available', 'out_of_stock', 'low_stock']) ? $status : '',
    // 🛡️ whitelist — รับเฉพาะ '0' กับ '1' ค่าอื่นถือว่าไม่กรอง
    'is_reference' => in_array($isReference, ['0', '1'], true) ? $isReference : '',
    'sort' => $sort
];

// 📄 นับยอดรวมก่อน แล้วคำนวณว่าอยู่หน้าไหน ต้องข้ามกี่แถว
$pagination = paginate($bookService->countBooks($bookFilters), $page, ITEMS_PER_PAGE);
$bookFilters['limit']  = $pagination['per_page'];
$bookFilters['offset'] = $pagination['offset'];

// ดึงหนังสือ "เฉพาะหน้านี้" ผ่าน Service
$books = $bookService->getBooks($bookFilters);

// 📄 filter ที่ต้องติดไปกับลิงก์เปลี่ยนหน้า — ไม่งั้นกดหน้า 2 แล้วตัวกรองหาย
// 📄 ทุกตัวกรองต้องอยู่ในลิงก์เลขหน้า ไม่งั้นกดหน้า 2 แล้วตัวกรองหลุด
$paginationParams = ['search' => $search, 'category' => $category, 'status' => $status, 'is_reference' => $isReference, 'sort' => $sort];
$paginationUnit = 'เล่ม';

// ดึงหมวดหมู่ทั้งหมดสำหรับ filter dropdown
$categories = $categoryRepo->findAll();

$pageTitle = 'จัดการหนังสือ';
require_once __DIR__ . '/header.php';
?>

<!-- Actions Bar -->
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
    <div>
        <h3 class="text-lg font-bold text-gray-800">รายการหนังสือทั้งหมด</h3>
        <p class="text-sm text-gray-500">ทั้งหมด <?= number_format($pagination['total']) ?> เล่ม</p>
    </div>
    <div class="flex gap-2">
        <a href="import_books.php" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium rounded-xl transition-colors shadow-sm">
            <i class="bi bi-file-earmark-spreadsheet mr-2 text-green-600"></i>Import CSV
        </a>
        <a href="book_form.php" class="inline-flex items-center px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-xl transition-colors shadow-lg shadow-primary-500/30">
            <i class="bi bi-plus-circle mr-2"></i>เพิ่มหนังสือใหม่
        </a>
    </div>
</div>

<!-- Filters -->
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
    <div class="text-sm font-bold text-gray-700 mb-4 flex items-center">
        <i class="bi bi-funnel mr-2"></i>ตัวกรอง
    </div>
    <form method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
        <div class="md:col-span-3">
            <label class="block text-xs font-medium text-gray-700 mb-1">ค้นหา</label>
            <input type="text" class="w-full border-gray-300 rounded-lg text-sm focus:ring-primary-500 focus:border-primary-500" name="search" value="<?= e($search) ?>" placeholder="ชื่อ, ผู้แต่ง, ISBN...">
        </div>
        <div class="md:col-span-3">
            <label class="block text-xs font-medium text-gray-700 mb-1">หมวดหมู่</label>
            <select class="w-full border-gray-300 rounded-lg text-sm focus:ring-primary-500 focus:border-primary-500" name="category">
                <option value="">ทั้งหมด</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $categoryId == $cat['id'] ? 'selected' : '' ?>>
                        <?= e($cat['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-2">
            <label class="block text-xs font-medium text-gray-700 mb-1">สถานะ</label>
            <select class="w-full border-gray-300 rounded-lg text-sm focus:ring-primary-500 focus:border-primary-500" name="status">
                <option value="">ทั้งหมด</option>
                <option value="available" <?= $status === 'available' ? 'selected' : '' ?>>มีของ</option>
                <option value="out_of_stock" <?= $status === 'out_of_stock' ? 'selected' : '' ?>>หนังสือหมด</option>
                <option value="low_stock" <?= $status === 'low_stock' ? 'selected' : '' ?>>ใกล้หมด (≤2)</option>
            </select>
        </div>
        <div class="md:col-span-2">
            <label class="block text-xs font-medium text-gray-700 mb-1">ประเภทการยืม</label>
            <select class="w-full border-gray-300 rounded-lg text-sm focus:ring-primary-500 focus:border-primary-500" name="is_reference">
                <option value="">ทั้งหมด</option>
                <option value="0" <?= $isReference === '0' ? 'selected' : '' ?>>ยืมออกได้</option>
                <option value="1" <?= $isReference === '1' ? 'selected' : '' ?>>อ้างอิง (อ่านในห้องสมุด)</option>
            </select>
        </div>
        <div class="md:col-span-2">
            <label class="block text-xs font-medium text-gray-700 mb-1">เรียงลำดับ</label>
            <select class="w-full border-gray-300 rounded-lg text-sm focus:ring-primary-500 focus:border-primary-500" name="sort">
                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>ใหม่ล่าสุด</option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>เก่าที่สุด</option>
                <option value="az" <?= $sort === 'az' ? 'selected' : '' ?>>ชื่อ (A-Z, ก-ฮ)</option>
            </select>
        </div>
        <div class="md:col-span-2 flex gap-2">
            <button type="submit" class="flex-1 bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                <i class="bi bi-search mr-1"></i>ค้นหา
            </button>
            <a href="books.php" class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm font-medium transition-colors">ล้าง</a>
        </div>
    </form>
</div>

<!-- Books Table -->
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
        <?php if (empty($books)): ?>
            <div class="text-center py-12 text-gray-400">
                <i class="bi bi-book text-6xl mb-4 inline-block text-gray-300"></i>
                <h4 class="text-lg font-medium text-gray-600">ไม่พบหนังสือ</h4>
                <p class="text-sm mb-6">ลองปรับเปลี่ยนตัวกรองหรือเพิ่มหนังสือใหม่</p>
                <a href="book_form.php" class="inline-flex items-center px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-xl transition-colors">
                    <i class="bi bi-plus-circle mr-2"></i>เพิ่มหนังสือเล่มแรก
                </a>
            </div>
        <?php else: ?>
            <table class="w-full text-sm text-left">
                <thead class="text-xs text-gray-500 uppercase bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="px-6 py-4 font-medium" width="50">#</th>
                        <th class="px-6 py-4 font-medium">ชื่อหนังสือ</th>
                        <th class="px-6 py-4 font-medium">ผู้แต่ง</th>
                        <th class="px-6 py-4 font-medium">หมวดหมู่</th>
                        <th class="px-6 py-4 font-medium">จำนวน</th>
                        <th class="px-6 py-4 font-medium">สถานะ</th>
                        <th class="px-6 py-4 font-medium text-right">การจัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($books as $index => $book): ?>
                        <tr class="hover:bg-gray-50/50 transition-colors">
                            <td class="px-6 py-4 text-gray-500"><?= $pagination['offset'] + $index + 1 ?></td>
                            <td class="px-6 py-4">
                                <a href="<?= APP_URL ?>/book.php?id=<?= $book['id'] ?>" class="font-bold text-gray-900 hover:text-primary-600 transition-colors line-clamp-2 max-w-xs block" target="_blank">
                                    <?= e($book['title']) ?>
                                </a>
                                <?php if ($book['isbn']): ?>
                                    <span class="text-xs text-gray-400 font-mono mt-1 block">ISBN: <?= e($book['isbn']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 font-medium text-gray-700"><?= e($book['author']) ?></td>
                            <td class="px-6 py-4">
                                <span class="px-2.5 py-0.5 bg-gray-100 text-gray-600 rounded-md text-xs font-medium border border-gray-200">
                                    <?= e($book['category_name'] ?? 'ไม่ระบุ') ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center text-gray-600 font-mono">
                                    <span class="font-bold text-gray-900"><?= $book['available'] ?></span>
                                    <span class="mx-1 text-gray-400">/</span>
                                    <span><?= $book['quantity'] ?></span>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($book['available'] > 0): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        <span class="w-1.5 h-1.5 bg-green-500 rounded-full mr-1.5"></span>
                                        ว่าง
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                        <span class="w-1.5 h-1.5 bg-red-500 rounded-full mr-1.5"></span>
                                        หมด
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($book['is_reference'])): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 ml-1" title="หนังสืออ้างอิง — ยืมออกและจองไม่ได้">
                                        <i class="bi bi-building mr-1"></i>อ้างอิง
                                    </span>
                                <?php endif; ?>
                                <?php if (empty($book['is_visible'])): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-200 text-gray-600 ml-1" title="ซ่อนจากหน้าเว็บสาธารณะ">
                                        <i class="bi bi-eye-slash mr-1"></i>ซ่อน
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <a href="<?= e(listStateLink('book_form.php?id=' . $book['id'], LIST_STATE_BOOKS)) ?>" class="text-amber-500 hover:text-amber-600 border border-amber-200 hover:bg-amber-50 p-1.5 rounded-lg transition-colors inline-block" title="แก้ไข">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php
                                // 🚫 ถามกฎจาก Service — ตรงกับ guard ใน BookService::deleteBook()
                                //    (เดิมเช็คแค่ available == quantity → ปุ่มเปิดใช้งานทั้งที่ลบไม่ได้)
                                $deleteBlockedReason = $bookService->getDeleteBlockReason($book);
                                ?>
                                <?php if ($deleteBlockedReason === null): ?>
                                    <form method="POST" class="d-inline inline-block" onsubmit="return confirmSubmit(this, 'ยืนยันการลบหนังสือเล่มนี้?', {title: 'ลบหนังสือ', confirmText: 'ลบ', confirmClass: 'danger'})">
                                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $book['id'] ?>">
                                        <button type="submit" class="text-red-500 hover:text-red-600 border border-red-200 hover:bg-red-50 p-1.5 rounded-lg transition-colors" title="ลบ">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button class="text-gray-300 border border-gray-200 p-1.5 rounded-lg cursor-not-allowed" disabled title="<?= e($deleteBlockedReason) ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php // 📄 แถบเลือกหน้า (ไม่แสดงถ้ามีหน้าเดียว) ?>
<?php require __DIR__ . '/../includes/pagination.php'; ?>

<?php require_once __DIR__ . '/footer.php'; ?>