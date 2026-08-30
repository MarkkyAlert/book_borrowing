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
    'call_number' => '',
    'category_id' => '',
    'description' => '',
    'copy_notes' => '',
    'cover_image' => '',
    'quantity' => 1,
    'price' => null,      // 💰 null = ยังไม่ระบุราคาปก (ไม่ใช่ 0 = ฟรี)
    'available' => 1
];
$isEdit = false;
// 🔍 [F-36] เล่มที่อาจซ้ำ — ตั้งไว้ก่อนเพื่อให้ชั้นแสดงผลอ้างถึงได้เสมอ แม้ตอน GET
$duplicateBook = null;

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
        redirectToList('books.php', LIST_STATE_BOOKS, $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET, 'ret_');
    }
}

// ── POST: บันทึกข้อมูลหนังสือ (create หรือ update) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ [SECURITY] CSRF — ป้องกัน attacker หลอกให้ staff สร้าง/แก้หนังสือ
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง กรุณาลองใหม่');
        redirectToList('books.php', LIST_STATE_BOOKS, $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET, 'ret_');
    }

    // 📥 รับ input จาก form — trim ป้องกัน whitespace หัว/ท้าย
    $book['title'] = trim($_POST['title'] ?? '');
    $book['author'] = trim($_POST['author'] ?? '');
    $book['isbn'] = trim($_POST['isbn'] ?? '');
    // 📍 เลขเรียกหนังสือ — รูปแบบอิสระ ไม่บังคับ
    //    🔴 ห้ามตรวจรูปแบบดิวอี้ — ห้องสมุดเล็กจำนวนมากใช้รหัสของตัวเอง
    //       เช่น "ก-01-03" (ตู้ ก ชั้น 1 ช่อง 3) หรือ "นิยาย-045"
    //       บังคับรูปแบบ = ลูกค้าครึ่งหนึ่งใช้ไม่ได้
    $book['call_number'] = trim($_POST['call_number'] ?? '');
    $book['category_id'] = (int) ($_POST['category_id'] ?? 0) ?: null;
    $book['description'] = trim($_POST['description'] ?? '');
    $book['copy_notes'] = trim($_POST['copy_notes'] ?? '');
    $book['quantity'] = max(0, (int) ($_POST['quantity'] ?? 1)); // ขั้นต่ำ 0 เล่ม (สำหรับซ่อนหนังสือที่หาย/ชำรุด)
    // 💰 ราคาปก — เว้นว่างได้ แปลว่า "ยังไม่ระบุ" ไม่ใช่ "ฟรี"
    //    🔴 ห้ามแปลงค่าว่างเป็น 0 เพราะตอนแจ้งหนังสือหายระบบใช้ค่านี้คิดค่าชดใช้
    //       ถ้าเป็น 0 จะกลายเป็นทำหายแล้วไม่ต้องจ่าย ต้องคง null ไว้ให้ระบบบังคับกรอก
    $priceRaw = trim((string) ($_POST['price'] ?? ''));
    $book['price'] = ($priceRaw === '') ? null : round(max(0, (float) $priceRaw), 2);
    $book['is_visible'] = isset($_POST['is_visible']) ? 1 : 0; // 👁️ การมองเห็น
    $book['is_reference'] = isset($_POST['is_reference']) ? 1 : 0; // 📚 หนังสืออ้างอิง (ยืม/จองไม่ได้)
    $isEdit = !empty($_POST['id']);
    $book['id'] = (int) ($_POST['id'] ?? 0);

    // ── Input Validation ──
    // 🔍 Validation ผ่าน shared helper (Single Source of Truth — ใช้ร่วมกับ import_books.php)
    $errors = array_merge($errors, validateBookData([
        'title' => $book['title'],
        'author' => $book['author'],
        'isbn' => $book['isbn']   // 🧠 ต้องส่งด้วย ไม่งั้น ISBN ยาวเกินจะหลุดไปให้ MySQL โยน error ดิบ
    ]));

    // 🔍 [DATA INTEGRITY] ตรวจ ISBN ซ้ำ — exclude ตัวเองในกรณี edit
    //    isbnExists($isbn, $excludeId) จะไม่นับ ID ของหนังสือที่กำลังแก้
    if (!empty($book['isbn'])) {
        if ($bookRepo->isbnExists($book['isbn'], $book['id'] ?: null)) {
            $errors[] = 'ISBN นี้มีในระบบแล้ว';
        }
    }

    // ══════════════════════════════════════════════════════════════
    // 🔍 [F-36] กันการเพิ่มหนังสือซ้ำ — มี 2 สาเหตุ แก้คนละแบบ
    // ══════════════════════════════════════════════════════════════
    // เดิมส่งฟอร์มเดิม 3 ครั้งได้หนังสือ 3 เล่ม ไม่มีคำเตือนเลย
    // uq_isbn คุ้มครองเฉพาะเล่มที่มี ISBN — NULL ซ้ำได้หลายแถวตามมาตรฐาน SQL
    // เล่มที่ไม่มี ISBN (เอกสารเย็บเล่มเอง วารสารเก่า หนังสือบริจาค) จึงไม่มีอะไรกัน

    // ── ด่านที่ 1: มีเล่มนั้นอยู่แล้วหรือเปล่า ──
    // 🔴 **ต้องมาก่อนด่าน idempotency** — เคยสลับลำดับแล้วพบว่า idempotency
    //    เด้งกลับไปหน้ารายการก่อน ผู้ใช้เลยไม่เห็นคำเตือน ไม่รู้เหตุผล
    //    และกด "เป็นคนละเล่มจริง ๆ" ไม่ได้เลยเพราะไม่เคยเห็นตัวเลือกนั้น
    //
    // ⚠️ **เตือนแล้วให้ยืนยัน ไม่ใช่ห้ามเด็ดขาด** — ชื่อเรื่องซ้ำกันได้จริง
    //    (คนละสำนักพิมพ์ · คนละปี · เล่ม 1/เล่ม 2 ที่ตั้งชื่อเหมือนกัน)
    //    ถ้าห้ามเด็ดขาด บรรณารักษ์จะเพิ่มเล่มที่ถูกต้องไม่ได้เลย
    if (empty($errors) && empty($_POST['confirm_duplicate'])) {
        $duplicateBook = $bookRepo->findDuplicateCandidate(
            $book['title'],
            $book['author'],
            $isEdit ? (int) $book['id'] : null   // 🔴 ตอนแก้ไขต้องกันตัวเองออก ไม่งั้นเตือนว่าซ้ำกับตัวเอง
        );

        if ($duplicateBook) {
            $errors[] = sprintf(
                'มีหนังสือชื่อนี้ของผู้แต่งคนนี้อยู่แล้ว — "%s" (คงเหลือ %d จาก %d เล่ม)',
                $duplicateBook['title'],
                (int) $duplicateBook['available'],
                (int) $duplicateBook['quantity']
            );
        }
    }

    // ── ด่านที่ 2: กดซ้ำ / refresh เร็ว ๆ ──
    // 🧠 กันเฉพาะช่วงที่ด่านที่ 1 ยังมองไม่เห็น — คือระหว่างที่คำขอแรกยังบันทึกไม่เสร็จ
    //    พอบันทึกเสร็จแล้ว ด่านที่ 1 จะเป็นตัวรับหน้าที่ต่อ (และอธิบายให้ผู้ใช้เข้าใจด้วย)
    // 🧠 key ผูกกับ **เนื้อหาฟอร์ม + สถานะการยืนยัน** ไม่ใช่แค่ผู้ใช้
    //    - ผูกกับเนื้อหา: เพิ่มหนังสือคนละเล่ม 2 เล่มติดกันต้องไม่ถูกบล็อก
    //    - ผูกกับการยืนยัน: กด "เป็นคนละเล่มจริง ๆ" ต้องผ่านได้ ไม่ติด key ของรอบก่อนหน้า
    $idempotencyKey = null;
    if (empty($errors) && !$isEdit) {
        cleanupIdempotencyKeys();
        $idempotencyKey = 'book_add_' . md5(
            $book['title'] . '|' . $book['author'] . '|' . $book['isbn']
            . '|' . (empty($_POST['confirm_duplicate']) ? '0' : '1')
        );

        if (isset($_SESSION['processed_actions'][$idempotencyKey])
            && (time() - $_SESSION['processed_actions'][$idempotencyKey]) < 60) {
            setFlash('info', 'หนังสือเล่มนี้เพิ่งถูกเพิ่มไปแล้ว');
            redirectToList('books.php', LIST_STATE_BOOKS, $_POST, 'ret_');
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

            // 📁 [F-54] เช็คสิทธิ์เขียน **ก่อน** ลองย้ายไฟล์ เพื่อบอกสาเหตุจริงได้
            //    🧠 เดิมล้มเหลวแล้วแจ้งแค่ "ไม่สามารถอัปโหลดรูปภาพได้"
            //       บรรณารักษ์จะเข้าใจว่า "รูปนี้มีปัญหา" แล้วลองรูปอื่นอีก 4-5 รูป
            //       ทุกรูปล้มเหลวเหมือนกัน เพราะสาเหตุจริงคือโฟลเดอร์เขียนไม่ได้
            //       ทั้งที่ระบบรู้อยู่แล้ว — เช็คครั้งเดียวก็บอกได้
            //    ใช้ isDirActuallyWritable() ตัวเดียวกับตัวติดตั้ง จะได้ตัดสินตรงกันเสมอ
            if (!isDirActuallyWritable($uploadDir)) {
                $errors[] = 'โฟลเดอร์เก็บรูปปก (uploads/covers/) เขียนไม่ได้ '
                    . 'ไม่ใช่ปัญหาที่ไฟล์รูป — กรุณาแจ้งผู้ดูแลระบบให้ตั้งสิทธิ์โฟลเดอร์';
            } elseif (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $oldCoverImage = $book['cover_image'] ?? null;
                $coverImage = $newFilename;
            } else {
                $errors[] = 'ไม่สามารถอัปโหลดรูปภาพได้ (ย้ายไฟล์ไม่สำเร็จ)';
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
            // 📍 '' → null เพื่อให้ "ยังไม่ได้ลงเลขเรียก" เป็นค่าเดียวกันทั้งระบบ
            //    ไม่งั้นจะมีทั้งแถวที่เป็น '' และ null ปนกัน ค้นหา/กรองแล้วได้ผลไม่ครบ
            //    (บทเรียนจาก F-48: ISBN มีทั้ง '' และ NULL จนต้องเช็คสองแบบ)
            'call_number' => $book['call_number'] ?: null,
            'category_id' => $book['category_id'],
            'description' => $book['description'] ?: null,
            'copy_notes' => $book['copy_notes'] ?: null,
            'cover_image' => $coverImage,
            'quantity' => $book['quantity'],
            'price' => $book['price'],          // 💰 null = ยังไม่ระบุ
            'is_visible' => $book['is_visible'] ?? 1,
            'is_reference' => $book['is_reference'] ?? 0
        ];

        try {
            if ($isEdit) {
                // [WRITE] อัปเดตหนังสือ — Service จัดการ available adjustment ให้
                $bookService->updateBook($book['id'], $bookData);
                setFlash('success', 'อัปเดตหนังสือสำเร็จ');
            } else {
                // [WRITE] สร้างหนังสือใหม่ — available = quantity
                $bookService->createBook($bookData);
                // 🛡️ [F-36] จดไว้ว่าเพิ่งเพิ่มไป — refresh ภายใน 60 วินาทีจะไม่ได้เล่มซ้ำ
                if ($idempotencyKey !== null) {
                    $_SESSION['processed_actions'][$idempotencyKey] = time();
                }
                setFlash('success', 'เพิ่มหนังสือสำเร็จ');
            }
            // [CLEANUP] ลบรูปปกเก่าหลัง DB save สำเร็จ — ป้องกัน orphan ถ้า DB ล้มเหลว
            if (!empty($oldCoverImage) && $oldCoverImage !== $coverImage) {
                $oldPath = (__DIR__ . '/../uploads/covers/') . $oldCoverImage;
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
            redirectToList('books.php', LIST_STATE_BOOKS, $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET, 'ret_');
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

            <?php // 🔍 [F-36] เจอเล่มที่อาจซ้ำ — ให้ทางเลือกที่ชัดเจน 2 ทาง
                  //    ไม่ใช่แค่ปฏิเสธแล้วปล่อยให้บรรณารักษ์งง เพราะชื่อเรื่องซ้ำกันได้จริง ?>
            <?php if (!empty($duplicateBook)): ?>
                <div class="bg-amber-50 border border-amber-200 rounded-xl p-5 mb-6">
                    <div class="flex items-start gap-3">
                        <i class="bi bi-exclamation-triangle-fill text-amber-500 text-xl mt-0.5"></i>
                        <div class="flex-1">
                            <h3 class="font-bold text-amber-900 mb-1">มีหนังสือชื่อนี้อยู่แล้ว</h3>
                            <p class="text-sm text-amber-800">
                                <span class="font-medium"><?= e($duplicateBook['title']) ?></span>
                                — <?= e($duplicateBook['author']) ?>
                                · คงเหลือ <?= (int) $duplicateBook['available'] ?> จาก <?= (int) $duplicateBook['quantity'] ?> เล่ม
                            </p>

                            <div class="mt-4 space-y-2 text-sm">
                                <p class="text-amber-900 font-medium">ต้องการทำอะไร</p>
                                <div class="flex flex-col sm:flex-row gap-2">
                                    <?php // ทางที่ 1 — ได้เล่มเพิ่มของเดิม ให้ไปเพิ่มจำนวนที่เล่มนั้น ?>
                                    <a href="<?= e(listStateLink('book_form.php?id=' . (int) $duplicateBook['id'], LIST_STATE_BOOKS, $_POST, 'ret_')) ?>"
                                       class="inline-flex items-center justify-center px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition-colors">
                                        <i class="bi bi-plus-square mr-2"></i>ไปเพิ่มจำนวนที่เล่มเดิม
                                    </a>
                                </div>
                                <p class="text-xs text-amber-700 pt-1">
                                    ถ้าได้หนังสือเล่มเดิมมาเพิ่ม ให้ไปแก้ "จำนวน" ที่เล่มเดิม จะได้ไม่มี 2 รายการในระบบ
                                </p>
                            </div>

                            <?php // ทางที่ 2 — เป็นคนละเล่มจริง ๆ (คนละสำนักพิมพ์/คนละปี/เล่ม 1-2 ชื่อเหมือนกัน) ?>
                            <label class="mt-4 flex items-start gap-2 p-3 bg-white border border-amber-200 rounded-lg cursor-pointer hover:bg-amber-50 transition-colors">
                                <input type="checkbox" name="confirm_duplicate" value="1" form="bookForm"
                                       class="mt-0.5 rounded text-amber-600 focus:ring-amber-500">
                                <span class="text-sm text-gray-700">
                                    <span class="font-medium">เป็นคนละเล่มจริง ๆ</span> — เพิ่มเป็นรายการใหม่
                                    <span class="block text-xs text-gray-500 mt-0.5">
                                        เช่น คนละสำนักพิมพ์ · คนละปีที่พิมพ์ · เล่ม 1 กับเล่ม 2 ที่ตั้งชื่อเหมือนกัน
                                    </span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" class="space-y-6" id="bookForm">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <?php // 📄 พาหน้า/ตัวกรองของรายการกลับไปด้วยหลังบันทึก (F-37)
                      //    ถ้าไม่ส่งต่อตรงนี้ ต่อให้ redirect ถูกก็กู้สถานะไม่ได้
                      //    เพราะมันหายไปตั้งแต่ตอนกดลิงก์ "แก้ไข" แล้ว ?>
                <?= listStateHiddenInputs(LIST_STATE_BOOKS) ?>
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
                            <?php // 📝 maxlength = ด่านแรกฝั่งหน้าจอ (ของจริงตรวจซ้ำที่ validateBookData) ?>
                            <input type="text" id="isbn" name="isbn" maxlength="20" value="<?= e($book['isbn']) ?>"
                                class="focus:ring-primary-500 focus:border-primary-500 block w-full pl-10 border-gray-300 rounded-xl"
                                placeholder="978-xxx-xxx">
                        </div>
                    </div>

                    <?php // 📍 เลขเรียกหนังสือ — "ที่อยู่" ของหนังสือบนชั้น
                          //    คนละเรื่องกับ ISBN: ISBN บอกว่าเป็นหนังสือเรื่องอะไร (ทั้งโลกใช้เลขเดียวกัน)
                          //    เลขเรียกบอกว่าอยู่ชั้นไหนใน **ห้องสมุดนี้** (แต่ละแห่งกำหนดเอง) ?>
                    <div class="md:col-span-1">
                        <label for="call_number" class="block text-sm font-medium text-gray-700 mb-1">เลขเรียกหนังสือ</label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="bi bi-signpost-split text-gray-400"></i>
                            </div>
                            <input type="text" id="call_number" name="call_number" maxlength="50" value="<?= e($book['call_number']) ?>"
                                class="focus:ring-primary-500 focus:border-primary-500 block w-full pl-10 border-gray-300 rounded-xl"
                                placeholder="เช่น 371.3 ส236ค หรือ ก-01-03">
                        </div>
                        <p class="mt-1 text-xs text-gray-500">
                            ตำแหน่งบนชั้น — ใช้รูปแบบไหนก็ได้ตามที่ห้องสมุดใช้อยู่ (ไม่บังคับ)
                        </p>
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
                            min="0" required>
                        <?php if ($isEdit && isset($book['available'])): ?>
                            <p class="mt-1 text-xs text-green-600 font-medium">ว่าง <?= $book['available'] ?> เล่ม</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 💰 ราคาปก — ใช้ตั้งต้นค่าชดใช้ตอนแจ้งหนังสือหาย -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="md:col-span-1">
                        <label for="price" class="block text-sm font-medium text-gray-700 mb-1">ราคาปก</label>
                        <div class="relative">
                            <input type="number" id="price" name="price" step="0.01" min="0"
                                value="<?= $book['price'] !== null && $book['price'] !== '' ? e($book['price']) : '' ?>"
                                placeholder="เว้นว่างได้"
                                class="w-full rounded-xl border-gray-300 focus:border-primary-500 focus:ring-primary-500 shadow-sm pr-12">
                            <span class="absolute inset-y-0 right-0 flex items-center pr-4 text-sm text-gray-400 pointer-events-none">บาท</span>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">
                            ใช้ตั้งต้นค่าชดใช้เวลาแจ้งหนังสือหาย — เว้นว่างไว้ได้ แล้วค่อยกรอกตอนแจ้ง
                        </p>
                    </div>
                </div>

                <!-- 👁️ Toggle: แสดง/ซ่อนหนังสือ -->
                <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <label for="is_visible" class="block text-sm font-medium text-gray-700">แสดงให้ผู้ใช้ทั่วไปเห็น</label>
                            <p class="text-xs text-gray-500 mt-0.5">ปิดเพื่อซ่อนหนังสือจากหน้าเว็บสาธารณะ (ยังเห็นในหน้า Admin)</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" id="is_visible" name="is_visible" value="1"
                                class="sr-only peer"
                                <?= ($book['is_visible'] ?? 1) ? 'checked' : '' ?>>
                            <div class="w-11 h-6 bg-gray-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-600"></div>
                        </label>
                    </div>
                </div>

                <!-- 📚 Toggle: หนังสืออ้างอิง (อ่านในห้องสมุดเท่านั้น) -->
                <div class="bg-amber-50 rounded-xl p-4 border border-amber-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <label for="is_reference" class="block text-sm font-medium text-gray-700">หนังสืออ้างอิง — อ่านในห้องสมุดเท่านั้น</label>
                            <p class="text-xs text-gray-500 mt-0.5">เปิดแล้วจะยืมออกและจองไม่ได้ แต่ยังค้นเจอและแสดงบนหน้าเว็บตามปกติ</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" id="is_reference" name="is_reference" value="1"
                                class="sr-only peer"
                                <?= !empty($book['is_reference']) ? 'checked' : '' ?>>
                            <div class="w-11 h-6 bg-gray-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-amber-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-amber-500"></div>
                        </label>
                    </div>
                </div>

                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-1">รายละเอียด/คำอธิบาย</label>
                    <textarea id="description" name="description" rows="4"
                        class="w-full rounded-xl border-gray-300 focus:border-primary-500 focus:ring-primary-500 shadow-sm"
                        placeholder="รายละเอียดหนังสือ (ถ้ามี)"><?= e($book['description']) ?></textarea>
                </div>

                <?php // 📓 หมายเหตุรายเล่ม — สมุดจดของเจ้าหน้าที่
                      //    🔴 ต้องบอกบนหน้าจอให้ชัดว่า **ระบบไม่ได้ตามรายเล่มให้**
                      //       ไม่งั้นบรรณารักษ์จะจดแล้วคิดว่าระบบรู้ แล้วเลิกนับเล่มเอง
                      //    🛡️ เป็นบันทึกภายใน — ช่องนี้มีเฉพาะฝั่งผู้ดูแล ไม่โผล่หน้าสาธารณะ ?>
                <div class="bg-amber-50 rounded-xl p-4 border border-amber-200">
                    <label for="copy_notes" class="flex items-center text-sm font-medium text-amber-900 mb-1">
                        <i class="bi bi-journal-text mr-2"></i>หมายเหตุรายเล่ม
                        <span class="ml-2 text-xs font-normal px-2 py-0.5 rounded-full bg-amber-200 text-amber-900">เจ้าหน้าที่เห็นเท่านั้น</span>
                    </label>
                    <textarea id="copy_notes" name="copy_notes" rows="3"
                        class="w-full rounded-xl border-amber-300 focus:border-amber-500 focus:ring-amber-500 shadow-sm bg-white"
                        placeholder="เช่น&#10;เล่ม 2 ปกขาด&#10;เล่ม 3 หาย 12 ส.ค. 2569"><?= e($book['copy_notes']) ?></textarea>
                    <p class="text-xs text-amber-800 mt-2 leading-relaxed">
                        <i class="bi bi-exclamation-triangle mr-1"></i>
                        <strong>นี่คือสมุดจด ไม่ใช่ระบบตามรายเล่ม</strong> —
                        ระบบนับหนังสือเป็น "จำนวนเล่ม" ไม่ได้แยกทีละเล่ม
                        ข้อความตรงนี้ระบบไม่ได้เอาไปคิดอะไรต่อ และ<strong>ไม่ได้หักออกจากจำนวนที่ว่าง</strong>
                        ถ้าเล่มไหนหายจริงต้องแก้ "จำนวนทั้งหมด" ด้วย
                    </p>
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
                    <a href="books.php<?= listStateQuery(listState(LIST_STATE_BOOKS, null, 'ret_')) ?>" class="px-5 py-2.5 border border-gray-300 shadow-sm text-sm font-medium rounded-xl text-gray-700 bg-white hover:bg-gray-50 transition-colors">
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