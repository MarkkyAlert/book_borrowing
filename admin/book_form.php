<?php
/**
 * Book Form - เพิ่ม/แก้ไขหนังสือ
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้ทำ 3 อย่าง: สร้างหนังสือ, แก้ไขหนังสือ, ลบหนังสือ
 * - สิทธิ์: staff ขึ้นไป
 * 
 * 📂 Flow:
 * 1. GET ?id=X      → โหลดข้อมูลหนังสือเข้า form (edit mode)
 * 2. GET (ไม่มี id) → form ว่าง (create mode)
 * 3. POST action=save   → BookService::createBook() หรือ updateBook()
 *    - รองรับ upload รูปปก (uploads/covers/)
 * 4. POST action=delete → BookService::deleteBook() (ตรวจเงื่อนไข 3 ข้อก่อนลบ)
 * 
 * ⚠️ ระวัง:
 * - deleteBook() ต้องไม่มี active borrow, borrow history, pending reservation
 * - upload ตรวจ mime type + ขนาด — แก้ไข limit ที่ config.php
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/../bootstrap.php';
// 🔒 [AUTH] staff/admin เท่านั้น
requireStaff();

use App\Repositories\BookRepository;    // ดึง/ตรวจข้อมูลหนังสือ (ISBN ซ้ำ, findById)
use App\Repositories\CategoryRepository; // ดึงรายการหมวดหมู่สำหรับ dropdown
use App\Services\BookService;            // Business logic: create, update, delete (รวม cover cleanup)

// 📦 สร้าง service/repository instances
$pdo = getDB();
$bookRepo = new BookRepository($pdo);
$categoryRepo = new CategoryRepository($pdo);
$bookService = new BookService($pdo);

$errors = [];
// 📝 ค่า default สำหรับ create mode — จะถูก overwrite ถ้าเป็น edit mode
$book = [
    'id' => 0,
    'title' => '',
    'author' => '',
    'isbn' => '',
    'category_id' => '',
    'description' => '',
    'cover_image' => '',
    'quantity' => 1,
    'available' => 1
];
$isEdit = false;

// ── Edit Mode: โหลดข้อมูลหนังสือเข้า form ──
// 🔍 ถ้ามี ?id=X → ดึงข้อมูลจาก DB เพื่อ pre-fill form
if (isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    $existingBook = $bookRepo->findById($id);
    
    if ($existingBook) {
        $book = $existingBook;
        $isEdit = true;
    } else {
        setFlash('error', 'ไม่พบหนังสือที่ต้องการแก้ไข');
        redirect('books.php');
    }
}

// ── POST: บันทึกข้อมูลหนังสือ (create หรือ update) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ [SECURITY] CSRF — ป้องกัน attacker หลอกให้ staff สร้าง/แก้หนังสือ
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง กรุณาลองใหม่');
        redirect('books.php');
    }
    
    // 📥 รับ input จาก form — trim ป้องกัน whitespace หัว/ท้าย
    $book['title'] = trim($_POST['title'] ?? '');
    $book['author'] = trim($_POST['author'] ?? '');
    $book['isbn'] = trim($_POST['isbn'] ?? '');
    $book['category_id'] = (int) ($_POST['category_id'] ?? 0) ?: null;
    $book['description'] = trim($_POST['description'] ?? '');
    $book['quantity'] = max(1, (int) ($_POST['quantity'] ?? 1)); // ขั้นต่ำ 1 เล่ม
    $isEdit = !empty($_POST['id']);
    $book['id'] = (int) ($_POST['id'] ?? 0);
    
    // ── Input Validation ──
    // 🔍 ตรวจสอบข้อมูลก่อนส่งเข้า Service
    if (empty($book['title'])) {
        $errors[] = 'กรุณากรอกชื่อหนังสือ';
    } elseif (mb_strlen($book['title']) > 200) {
        $errors[] = 'ชื่อหนังสือต้องไม่เกิน 200 ตัวอักษร';
    }
    
    if (empty($book['author'])) {
        $errors[] = 'กรุณากรอกชื่อผู้แต่ง';
    } elseif (mb_strlen($book['author']) > 100) {
        $errors[] = 'ชื่อผู้แต่งต้องไม่เกิน 100 ตัวอักษร';
    }
    
    // 🔍 [DATA INTEGRITY] ตรวจ ISBN ซ้ำ — exclude ตัวเองในกรณี edit
    //    isbnExists($isbn, $excludeId) จะไม่นับ ID ของหนังสือที่กำลังแก้
    if (!empty($book['isbn'])) {
        if ($bookRepo->isbnExists($book['isbn'], $book['id'] ?: null)) {
            $errors[] = 'ISBN นี้มีในระบบแล้ว';
        }
    }
    
    // ── [FILE UPLOAD] จัดการรูปปก ──
    // ⚠️ มีความเสี่ยงสูงถ้าไม่ validate — อาจถูก upload shell/malware
    $coverImage = $book['cover_image'] ?? null;
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['cover_image'];
        // [SECURITY] whitelist MIME types - ป้องกัน upload shell/malware
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 2 * 1024 * 1024; // 2MB
        
        // [SECURITY] ใช้ finfo ตรวจ MIME จาก file content จริง - ไม่เชื่อ $_FILES['type'] (client ส่งมา)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            $errors[] = 'รองรับเฉพาะไฟล์รูปภาพ (JPEG, PNG, GIF, WEBP)';
        } elseif ($file['size'] > $maxSize) {
            $errors[] = 'ขนาดไฟล์ต้องไม่เกิน 2MB';
        } else {
            $uploadDir = __DIR__ . '/../uploads/covers/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // [SECURITY] กำหนด extension จาก MIME ที่ตรวจแล้ว - ไม่ใช้ชื่อไฟล์จาก user
            $mimeToExt = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp'
            ];
            $ext = $mimeToExt[$mimeType] ?? 'jpg';
            // [SECURITY] สร้างชื่อไฟล์ใหม่ด้วย uniqid - ป้องกัน path traversal และ overwrite
            $newFilename = 'cover_' . time() . '_' . uniqid() . '.' . $ext;
            $targetPath = $uploadDir . $newFilename;
            
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $oldCoverImage = $book['cover_image'] ?? null;
                $coverImage = $newFilename;
            } else {
                $errors[] = 'ไม่สามารถอัปโหลดรูปภาพได้';
            }
        }
    }
    
    // ── บันทึกข้อมูล (ถ้าไม่มี error) ──
    if (empty($errors)) {
        // 📦 เตรียม data array ส่งเข้า Service
        $bookData = [
            'title' => $book['title'],
            'author' => $book['author'],
            'isbn' => $book['isbn'] ?: null,
            'category_id' => $book['category_id'],
            'description' => $book['description'] ?: null,
            'cover_image' => $coverImage,
            'quantity' => $book['quantity']
        ];
        
        try {
            if ($isEdit) {
                // [WRITE] อัปเดตหนังสือ — Service จัดการ available adjustment ให้
                $bookService->updateBook($book['id'], $bookData);
                setFlash('success', 'อัปเดตหนังสือสำเร็จ');
            } else {
                // [WRITE] สร้างหนังสือใหม่ — available = quantity
                $bookService->createBook($bookData);
                setFlash('success', 'เพิ่มหนังสือสำเร็จ');
            }
            // [CLEANUP] ลบรูปปกเก่าหลัง DB save สำเร็จ — ป้องกัน orphan ถ้า DB ล้มเหลว
            if (!empty($oldCoverImage) && $oldCoverImage !== $coverImage) {
                $oldPath = (__DIR__ . '/../uploads/covers/') . $oldCoverImage;
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
            redirect('books.php');
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// 📂 ดึงหมวดหมู่ทั้งหมดสำหรับ dropdown ใน form
$categories = $categoryRepo->findAll();

$pageTitle = $isEdit ? 'แก้ไขหนังสือ' : 'เพิ่มหนังสือ';
require_once __DIR__ . '/header.php';
?>

<div class="max-w-3xl mx-auto">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50 flex items-center">
            <h5 class="font-bold text-gray-800 flex items-center text-lg">
                <i class="bi bi-<?= $isEdit ? 'pencil' : 'plus-circle' ?> mr-2 text-primary-600"></i>
                <?= $isEdit ? 'แก้ไขหนังสือ' : 'เพิ่มหนังสือใหม่' ?>
            </h5>
        </div>
        
        <div class="p-6 md:p-8">
            <?php if (!empty($errors)): ?>
                <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-r-lg">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="bi bi-exclamation-circle-fill text-red-500"></i>
                        </div>
                        <div class="ml-3">
                            <ul class="list-disc list-inside text-sm text-red-700">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= e($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <?php if ($isEdit): ?>
                    <input type="hidden" name="id" value="<?= $book['id'] ?>">
                <?php endif; ?>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2">
                        <label for="title" class="block text-sm font-medium text-gray-700 mb-1">
                            ชื่อหนังสือ <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="title" name="title" value="<?= e($book['title']) ?>" 
                               class="w-full rounded-xl border-gray-300 focus:border-primary-500 focus:ring-primary-500 shadow-sm"
                               placeholder="กรอกชื่อหนังสือ" required autofocus>
                    </div>
                    
                    <div class="md:col-span-1">
                        <label for="isbn" class="block text-sm font-medium text-gray-700 mb-1">ISBN</label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="bi bi-upc-scan text-gray-400"></i>
                            </div>
                            <input type="text" id="isbn" name="isbn" value="<?= e($book['isbn']) ?>" 
                                   class="focus:ring-primary-500 focus:border-primary-500 block w-full pl-10 border-gray-300 rounded-xl"
                                   placeholder="978-xxx-xxx">
                        </div>
                    </div>

                    <div class="md:col-span-1">
                        <label for="author" class="block text-sm font-medium text-gray-700 mb-1">
                            ผู้แต่ง <span class="text-red-500">*</span>
                        </label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="bi bi-person text-gray-400"></i>
                            </div>
                            <input type="text" id="author" name="author" value="<?= e($book['author']) ?>" 
                                   class="focus:ring-primary-500 focus:border-primary-500 block w-full pl-10 border-gray-300 rounded-xl"
                                   placeholder="กรอกชื่อผู้แต่ง" required>
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="md:col-span-2">
                        <label for="category_id" class="block text-sm font-medium text-gray-700 mb-1">หมวดหมู่</label>
                        <select id="category_id" name="category_id" class="w-full rounded-xl border-gray-300 focus:border-primary-500 focus:ring-primary-500 shadow-sm">
                            <option value="">-- เลือกหมวดหมู่ --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $book['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                                    <?= e($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="md:col-span-1">
                        <label for="quantity" class="block text-sm font-medium text-gray-700 mb-1">
                            จำนวน <span class="text-red-500">*</span>
                        </label>
                        <input type="number" id="quantity" name="quantity" value="<?= e($book['quantity'] ?? 1) ?>" 
                               class="w-full rounded-xl border-gray-300 focus:border-primary-500 focus:ring-primary-500 shadow-sm"
                               min="1" required>
                        <?php if ($isEdit && isset($book['available'])): ?>
                            <p class="mt-1 text-xs text-green-600 font-medium">ว่าง <?= $book['available'] ?> เล่ม</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-1">รายละเอียด/คำอธิบาย</label>
                    <textarea id="description" name="description" rows="4" 
                              class="w-full rounded-xl border-gray-300 focus:border-primary-500 focus:ring-primary-500 shadow-sm"
                              placeholder="รายละเอียดหนังสือ (ถ้ามี)"><?= e($book['description']) ?></textarea>
                </div>
                
                <div class="bg-gray-50 rounded-xl p-4 border border-gray-200 border-dashed">
                    <label for="cover_image" class="block text-sm font-medium text-gray-700 mb-2">รูปหน้าปก</label>
                    
                    <div class="flex items-start space-x-4">
                        <?php if (!empty($book['cover_image'])): ?>
                            <div class="flex-shrink-0">
                                <img src="<?= APP_URL ?>/uploads/covers/<?= e($book['cover_image']) ?>" 
                                     alt="Current Cover" 
                                     class="h-32 w-24 object-cover rounded-lg shadow-sm border border-gray-200">
                                <p class="text-xs text-gray-500 mt-1 text-center">รูปปัจจุบัน</p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="flex-1">
                            <input type="file" id="cover_image" name="cover_image" 
                                   class="block w-full text-sm text-gray-500
                                          file:mr-4 file:py-2 file:px-4
                                          file:rounded-full file:border-0
                                          file:text-sm file:font-semibold
                                          file:bg-primary-50 file:text-primary-700
                                          hover:file:bg-primary-100
                                          transition-colors cursor-pointer"
                                   accept="image/jpeg,image/png,image/gif,image/webp">
                            <p class="mt-2 text-xs text-gray-500">รองรับ JPEG, PNG, GIF, WEBP ขนาดไม่เกิน 2MB</p>
                        </div>
                    </div>
                </div>
                
                <div class="flex items-center justify-between pt-6 border-t border-gray-100">
                    <a href="books.php" class="px-5 py-2.5 border border-gray-300 shadow-sm text-sm font-medium rounded-xl text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                        <i class="bi bi-arrow-left mr-1"></i>ยกเลิก
                    </a>
                    <button type="submit" class="px-5 py-2.5 border border-transparent shadow-sm text-sm font-medium rounded-xl text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors shadow-lg shadow-primary-500/30">
                        <i class="bi bi-check-lg mr-1"></i>
                        <?= $isEdit ? 'บันทึกการแก้ไข' : 'เพิ่มหนังสือ' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
