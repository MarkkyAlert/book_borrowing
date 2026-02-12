<?php
/**
 * Import Books from CSV - นำเข้าหนังสือจากไฟล์ CSV
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้ upload CSV แล้ว import หนังสือเข้าระบบ (ทั้ง create + merge)
 * - สิทธิ์: staff ขึ้นไป
 * 
 * 📂 Flow:
 * 1. POST → upload CSV → parse ทีละแถว (ภายใน transaction เดียว)
 * 2. ถ้า ISBN ตรงกับที่มีอยู่ → เพิ่ม quantity (merge)
 * 3. ถ้า title+author ตรงกัน → เพิ่ม quantity (merge)
 * 4. ถ้าไม่พบ → สร้างหนังสือใหม่
 * 5. ล้มเหลวแถวใดแถวหนึ่ง → rollback ทั้งหมด
 * 
 * ⚠️ ระวัง:
 * - ทั้ง batch อยู่ใน transaction เดียว — ผิด 1 แถว = rollback ทั้งไฟล์
 * - CSV format: title, author, isbn, category, quantity, description
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/../bootstrap.php';
// 🔒 [AUTH] staff/admin เท่านั้น
requireStaff();

use App\Repositories\BookRepository;    // CRUD หนังสือ + merge (addQuantity)
use App\Repositories\CategoryRepository; // ค้นหา/สร้างหมวดหมู่อัตโนมัติ

// 📦 สร้าง repository instances
$pdo = getDB();
$bookRepo = new BookRepository($pdo);
$categoryRepo = new CategoryRepository($pdo);

$messages = [];
$errors = [];
$successCount = 0;
$failCount = 0;

// ── POST: อัปโหลด CSV + นำเข้าหนังสือ ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    
    // 🛡️ [SECURITY] CSRF
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid Request (CSRF)';
    } else {
        $file = $_FILES['csv_file'];
        
        // 🔍 [VALIDATION] ตรวจนามสกุลไฟล์ — รับเฉพาะ .csv
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $errors[] = 'กรุณาอัปโหลดไฟล์ .csv เท่านั้น';
        } else {
            // 📄 เปิดไฟล์ CSV จาก tmp directory
            $handle = fopen($file['tmp_name'], 'r');
            
            if (!$handle) {
                $errors[] = 'ไม่สามารถอ่านไฟล์ได้';
            } else {
                fgetcsv($handle); // ข้าม header row (Title, Author, ISBN, Category, Quantity)
                
                // [DATA INTEGRITY] Transaction ครอบทุกแถว — ถ้า error กลางทาง rollback ทั้งหมด (all-or-nothing)
                $pdo->beginTransaction();
                
                try {
                    $createdCount = 0;
                    $updatedCount = 0;
                    $rowNumber = 1;
                    $skippedDetails = [];
                    
                    // 🔄 วนอ่านทีละแถว — แถวที่มีปัญหาจะ skip (เก็บรายละเอียด) ไม่ throw
                    while (($row = fgetcsv($handle)) !== false) {
                        $rowNumber++;
                        
                        // 🔍 ตรวจจำนวนคอลัมน์ขั้นต่ำ (ต้องมีอย่างน้อย title + author)
                        if (count($row) < 2) {
                            $skippedDetails[] = "แถวที่ $rowNumber: ข้อมูลไม่ครบ (ต้องมีอย่างน้อย ชื่อหนังสือ และ ผู้แต่ง)";
                            continue;
                        }
                        
                        $title = trim($row[0]);
                        $author = trim($row[1]);
                        $isbn = trim($row[2] ?? '');
                        $categoryName = trim($row[3] ?? 'General');
                        $qty = max(1, (int)($row[4] ?? 1));
                        
                        if (empty($title)) {
                            $skippedDetails[] = "แถวที่ $rowNumber: ชื่อหนังสือว่างเปล่า";
                            continue;
                        }
                        
                        // 🔍 [DATA INTEGRITY] ISBN unique — ถ้าซ้ำให้ skip
                        //    ไม่ merge เพราะ ISBN เดียวกันอาจเป็นคนละเล่ม (edition ต่างกัน)
                        if (!empty($isbn) && $bookRepo->isbnExists($isbn)) {
                            $skippedDetails[] = "แถวที่ $rowNumber: ISBN $isbn ซ้ำกับหนังสือในระบบ";
                            continue;
                        }
                        
                        // 🔄 [DATA INTEGRITY] Merge strategy:
                        //    ชื่อ+ผู้แต่งตรงกัน → เพิ่ม quantity (ไม่สร้างซ้ำ)
                        //    ไม่พบ → สร้างใหม่
                        $existingBook = $bookRepo->findByTitleAndAuthor($title, $author);
                        
                        if ($existingBook) {
                            // [WRITE] เพิ่ม quantity+available ของหนังสือที่มีอยู่ (stock เพิ่มทันที)
                            $bookRepo->addQuantity($existingBook['id'], $qty);
                            $updatedCount++;
                        } else {
                            // ── INSERT: สร้างหนังสือใหม่ ──
                            // 📂 จัดการหมวดหมู่ก่อน: ค้นหาตามชื่อ — ถ้าไม่พบ สร้างอัตโนมัติ
                            $categoryId = null;
                            if (!empty($categoryName)) {
                                $cat = $categoryRepo->findByName($categoryName);
                                if ($cat) {
                                    $categoryId = $cat['id'];
                                } else {
                                    $categoryId = $categoryRepo->create($categoryName); // auto-create
                                }
                            }
                            
                            // [WRITE] สร้างหนังสือใน DB
                            $bookRepo->create([
                                'title' => $title,
                                'author' => $author,
                                'isbn' => $isbn ?: null,
                                'category_id' => $categoryId,
                                'quantity' => $qty
                            ]);
                            $createdCount++;
                        }
                    }
                    
                    $pdo->commit();
                    
                    $msg = "นำเข้าเสร็จสิ้น: เพิ่มใหม่ $createdCount รายการ, อัปเดต $updatedCount รายการ";
                    if (!empty($skippedDetails)) {
                        $msg .= "<br><br><strong>รายการที่ไม่สำเร็จ (" . count($skippedDetails) . "):</strong><br>" . implode("<br>", $skippedDetails);
                        setFlash('warning', $msg, true);
                    } else {
                        setFlash('success', $msg);
                    }
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = "เกิดข้อผิดพลาด: " . $e->getMessage();
                } finally {
                    // [CLEANUP] ปิด file handle เสมอไม่ว่าจะสำเร็จหรือไม่
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                }
            }
        }
    }
}

$pageTitle = 'นำเข้าหนังสือ (Import Books)';
require_once __DIR__ . '/header.php';
?>

<div class="max-w-3xl mx-auto">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
            <h5 class="font-bold text-gray-800 flex items-center">
                <i class="bi bi-file-earmark-spreadsheet mr-2 text-green-600"></i>นำเข้าหนังสือจาก CSV
            </h5>
            <a href="books.php" class="text-sm text-gray-500 hover:text-gray-700">
                <i class="bi bi-arrow-left mr-1"></i>กลับ
            </a>
        </div>
        
        <div class="p-6">
            <?php if (!empty($errors)): ?>
                <div class="bg-red-50 text-red-700 p-4 rounded-xl mb-6">
                    <ul class="list-disc list-inside">
                        <?php foreach ($errors as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="mb-8">
                <h4 class="text-sm font-bold text-gray-700 mb-2">1. เตรียมไฟล์ CSV</h4>
                <p class="text-sm text-gray-600 mb-2">สร้างไฟล์ CSV โดยมีคอลัมน์เรียงตามลำดับดังนี้:</p>
                <div class="bg-gray-800 text-gray-200 p-3 rounded-lg font-mono text-xs overflow-x-auto mb-3">
                    Title, Author, ISBN, Category, Quantity
                </div>
                <div class="bg-gray-50 p-3 rounded-lg border border-gray-200 text-sm">
                    <strong>ตัวอย่างข้อมูล:</strong><br>
                    Harry Potter, J.K. Rowling, 978-1234567890, Fantasy, 5<br>
                    Clean Code, Robert C. Martin, , Computer, 3
                </div>
                <div class="mt-2">
                    <a href="data:text/csv;charset=utf-8,Title,Author,ISBN,Category,Quantity%0AExample Book,John Doe,123456789,Fiction,5" download="template_books.csv" class="text-primary-600 hover:text-primary-700 text-sm font-medium inline-flex items-center">
                        <i class="bi bi-download mr-1"></i>ดาวน์โหลดเทมเพลต (Template)
                    </a>
                </div>
            </div>

            <hr class="border-gray-100 my-6">

            <div class="mb-4">
                <h4 class="text-sm font-bold text-gray-700 mb-4">2. อัปโหลดไฟล์</h4>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <div class="mb-6">
                        <label class="block w-full cursor-pointer">
                            <div class="flex flex-col items-center justify-center h-32 bg-gray-50 rounded-xl border-2 border-dashed border-gray-300 hover:bg-gray-100 hover:border-primary-500 transition-colors">
                                <i class="bi bi-cloud-upload text-3xl text-gray-400 mb-2"></i>
                                <span class="text-sm text-gray-500">คลิกเพื่อเลือกไฟล์ .csv</span>
                                <input type="file" name="csv_file" class="hidden" accept=".csv" required onchange="document.getElementById('fileName').textContent = this.files[0].name">
                            </div>
                        </label>
                        <p id="fileName" class="text-center text-sm text-gray-600 mt-2 min-h-[20px]"></p>
                    </div>
                    
                    <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2.5 px-4 rounded-xl transition-colors shadow-sm flex items-center justify-center">
                        <i class="bi bi-check-lg mr-2"></i>เริ่มนำเข้าข้อมูล
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
