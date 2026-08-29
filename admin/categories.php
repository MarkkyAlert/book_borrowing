<?php
/**
 * Categories Management - จัดการหมวดหมู่
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้ทำ CRUD หมวดหมู่ทั้งหมดในไฟล์เดียว (ไม่แยก form)
 * - สิทธิ์: staff ขึ้นไป
 * 
 * 📂 Flow:
 * 1. POST action=add    → สร้างหมวดหมู่ใหม่ (ตรวจชื่อซ้ำ)
 * 2. POST action=edit   → อัปเดตชื่อหมวดหมู่ (ตรวจชื่อซ้ำ ยกเว้นตัวเอง)
 * 3. POST action=delete → ลบหมวดหมู่ (ต้องไม่มีหนังสืออยู่)
 * 4. GET ?edit=ID        → โหลดข้อมูลเดิมเข้า form สำหรับแก้ไข
 * 5. GET → แสดงรายการหมวดหมู่ + จำนวนหนังสือ
 * 
 * ⚠️ ระวัง:
 * - ลบหมวดหมู่ที่มีหนังสือไม่ได้ (hasBooks check)
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/../bootstrap.php';
// 🔒 [AUTH] staff/admin เท่านั้น
requireStaff();

use App\Repositories\CategoryRepository;

// 📦 สร้าง repository instance
$pdo = getDB();
$categoryRepo = new CategoryRepository($pdo);

$errors = [];
$editCategory = null; // ถ้า != null → form จะเปลี่ยนเป็น edit mode

// ── POST: CRUD actions (add / update / delete) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ [SECURITY] CSRF — ป้องกัน attacker หลอกให้ staff แก้ไขหมวดหมู่
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง กรุณาลองใหม่');
        redirect('categories.php');
    }
    
    $action = $_POST['action'] ?? '';
    
    // ── Action: เพิ่มหมวดหมู่ใหม่ ──
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        
        if (empty($name)) {
            $errors[] = 'กรุณากรอกชื่อหมวดหมู่';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = 'ชื่อหมวดหมู่ต้องไม่เกิน 100 ตัวอักษร';
        }
        
        // 🔍 [DATA INTEGRITY] ตรวจชื่อซ้ำ — ป้องกัน duplicate ใน DB
        if (empty($errors)) {
            if ($categoryRepo->nameExists($name)) {
                $errors[] = 'ชื่อหมวดหมู่นี้มีอยู่แล้ว';
            }
        }
        
        if (empty($errors)) {
            $categoryRepo->create($name);
            setFlash('success', 'เพิ่มหมวดหมู่สำเร็จ');
            redirect('categories.php');
        }
    }
    
    // ── Action: อัปเดตหมวดหมู่ ──
    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        
        if (empty($name)) {
            $errors[] = 'กรุณากรอกชื่อหมวดหมู่';
        }
        
        // 🔍 [DATA INTEGRITY] ตรวจชื่อซ้ำ (exclude ตัวเอง) — nameExists($name, $excludeId)
        if (empty($errors)) {
            if ($categoryRepo->nameExists($name, $id)) {
                $errors[] = 'ชื่อหมวดหมู่นี้มีอยู่แล้ว';
            }
        }
        
        if (empty($errors)) {
            $categoryRepo->update($id, $name);
            setFlash('success', 'อัปเดตหมวดหมู่สำเร็จ');
            redirect('categories.php');
        } else {
            $editCategory = ['id' => $id, 'name' => $name];
        }
    }
    
    // ── Action: ลบหมวดหมู่ ──
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        
        try {
            // [DATA INTEGRITY] ป้องกัน orphan books — ต้องย้าย/ลบหนังสือออกก่อนลบหมวดหมู่
            if ($categoryRepo->hasBooks($id)) {
                throw new Exception('ไม่สามารถลบได้ หมวดหมู่นี้มีหนังสือ');
            }
            
            $categoryRepo->delete($id);
            setFlash('success', 'ลบหมวดหมู่สำเร็จ');
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }
        redirect('categories.php');
    }
}

// ── GET ?edit=ID: โหลดข้อมูลเข้า form สำหรับแก้ไข ──
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $editCategory = $categoryRepo->findById($editId); // ถ้า null → form จะเป็น add mode
}

// 📊 ดึงหมวดหมู่ทั้งหมดพร้อมจำนวนหนังสือ (LEFT JOIN + COUNT)
$categories = $categoryRepo->findAllWithBookCount();

$pageTitle = 'จัดการหมวดหมู่';
require_once __DIR__ . '/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <!-- Form -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden sticky top-6">
            <div class="px-5 py-4 border-b border-gray-100 bg-gray-50/50">
                <h5 class="font-bold text-gray-800 flex items-center">
                    <i class="bi bi-<?= $editCategory ? 'pencil-fill text-amber-500' : 'plus-circle-fill text-primary-600' ?> mr-2"></i>
                    <?= $editCategory ? 'แก้ไขหมวดหมู่' : 'เพิ่มหมวดหมู่' ?>
                </h5>
            </div>
            <div class="p-5">
                <?php if (!empty($errors)): ?>
                    <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-4 rounded-r-lg">
                        <ul class="list-disc list-inside text-sm text-red-700">
                            <?php foreach ($errors as $error): ?>
                                <li><?= e($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="action" value="<?= $editCategory ? 'update' : 'add' ?>">
                    <?php if ($editCategory): ?>
                        <input type="hidden" name="id" value="<?= $editCategory['id'] ?>">
                    <?php endif; ?>
                    
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">ชื่อหมวดหมู่ <span class="text-red-500">*</span></label>
                        <input type="text" id="name" name="name" value="<?= e($editCategory['name'] ?? '') ?>" 
                               class="w-full rounded-xl border-gray-300 focus:border-primary-500 focus:ring-primary-500 shadow-sm"
                               placeholder="เช่น นิยาย, วิชาการ..." required autofocus>
                    </div>
                    
                    <div class="pt-2 flex flex-col gap-2">
                        <button type="submit" class="w-full justify-center inline-flex items-center px-4 py-2.5 border border-transparent shadow-sm text-sm font-medium rounded-xl text-white <?= $editCategory ? 'bg-amber-500 hover:bg-amber-600 focus:ring-amber-500' : 'bg-primary-600 hover:bg-primary-700 focus:ring-primary-500' ?> focus:outline-none focus:ring-2 focus:ring-offset-2 transition-colors">
                            <i class="bi bi-<?= $editCategory ? 'check-lg' : 'plus-lg' ?> mr-1.5"></i>
                            <?= $editCategory ? 'บันทึกการแก้ไข' : 'เพิ่มหมวดหมู่' ?>
                        </button>
                        
                        <?php if ($editCategory): ?>
                            <a href="categories.php" class="w-full justify-center inline-flex items-center px-4 py-2.5 border border-gray-300 shadow-sm text-sm font-medium rounded-xl text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors">
                                <i class="bi bi-x-lg mr-1.5"></i>ยกเลิก
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- List -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50 flex items-center justify-between">
                <h5 class="font-bold text-gray-800 flex items-center">
                    <i class="bi bi-list mr-2"></i>รายการหมวดหมู่
                </h5>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary-50 text-primary-700">
                    ทั้งหมด <?= count($categories) ?>
                </span>
            </div>
            
            <?php if (empty($categories)): ?>
                <div class="text-center py-12 text-gray-400">
                    <i class="bi bi-inbox text-5xl mb-3 inline-block text-gray-300"></i>
                    <p>ยังไม่มีหมวดหมู่</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-500 uppercase bg-gray-50 border-b border-gray-100">
                            <tr>
                                <th class="px-6 py-4 font-medium" width="60">#</th>
                                <th class="px-6 py-4 font-medium">ชื่อหมวดหมู่</th>
                                <th class="px-6 py-4 font-medium text-center" width="140">จำนวนหนังสือ</th>
                                <th class="px-6 py-4 font-medium text-end" width="120">การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($categories as $index => $cat): ?>
                                <tr class="hover:bg-gray-50/50 transition-colors group">
                                    <td class="px-6 py-4 text-gray-500"><?= $index + 1 ?></td>
                                    <td class="px-6 py-4 font-medium text-gray-900">
                                        <i class="bi bi-bookmark text-primary-400 mr-2 group-hover:text-primary-600 transition-colors"></i>
                                        <?= e($cat['name']) ?>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                            <?= number_format($cat['book_count']) ?> เล่ม
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-right space-x-2">
                                        <a href="?edit=<?= $cat['id'] ?>" class="text-amber-500 hover:text-amber-600 transition-colors p-1" title="แก้ไข">
                                            <i class="bi bi-pencil-square"></i>
                                        </a>
                                        <?php if ($cat['book_count'] == 0): ?>
                                            <?php // 🔴 [F-47] บอกชื่อหมวดที่กำลังจะลบ — ตารางทุกแถวหน้าตาเหมือนกัน
                                                  //    ปุ่มลบขึ้นเฉพาะหมวดที่ไม่มีหนังสือ (book_count == 0) จึงไม่ต้องเตือนเรื่องหนังสือ ?>
                                            <form method="POST" class="inline-block" onsubmit="return confirmSubmit(this, <?= jsString("ลบหมวดหมู่ \"{$cat['name']}\"") ?>, {title: 'ลบหมวดหมู่', confirmText: 'ลบ', confirmClass: 'danger'})">
                                                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                                <button type="submit" class="text-red-400 hover:text-red-600 transition-colors p-1" title="ลบ">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-gray-300 p-1 cursor-not-allowed" title="มีหนังสือในหมวดหมู่นี้ ไม่สามารถลบได้">
                                                <i class="bi bi-trash"></i>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
