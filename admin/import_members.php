<?php
/**
 * Import Members from CSV - นำเข้าสมาชิกจากไฟล์ CSV
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้ upload CSV แล้ว import สมาชิกเข้าระบบ (upsert: create หรือ update)
 * - ใช้ MemberService::importMember() เป็น Single Source of Truth
 * - สิทธิ์: staff ขึ้นไป
 * 
 * 📂 Flow:
 * 1. POST → upload CSV → parse ทีละแถว (ภายใน transaction เดียว)
 * 2. ถ้า email มีอยู่แล้ว → update name/phone (ไม่แก้ password)
 * 3. ถ้า email ใหม่ → สร้างสมาชิกใหม่ด้วย default password
 * 4. แถวที่ validation ไม่ผ่าน → skip + เก็บรายละเอียด (ไม่ rollback)
 * 5. ถ้าเกิด Exception → rollback ทั้ง batch
 * 
 * ⚠️ ระวัง:
 * - สมาชิกใหม่จะได้ default password (123456) — ต้องแจ้งให้เปลี่ยน
 * - CSV format: name, email, phone
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/../bootstrap.php';
// 🔒 [AUTH] staff/admin เท่านั้น
requireStaff();

use App\Services\MemberService;

// 📦 สร้าง service instance — MemberService::importMember() เป็น Single Source of Truth
$pdo = getDB();
$memberService = new MemberService($pdo);

$messages = [];
$errors = [];

// ── POST: อัปโหลด CSV + นำเข้าสมาชิก ──
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
                fgetcsv($handle); // ข้าม header row (Name, Email, Phone)
                
                // [DATA INTEGRITY] Transaction ครอบทุกแถว — ถ้า error กลางทาง rollback ทั้งหมด (all-or-nothing)
                $pdo->beginTransaction();
                
                try {
                    $rowNumber = 1;
                    $skippedDetails = [];
                    $createdCount = 0;
                    $updatedCount = 0;
                    
                    // 🔄 วนอ่านทีละแถว — แถวที่มีปัญหาจะ skip (ไม่ throw)
                    while (($row = fgetcsv($handle)) !== false) {
                        $rowNumber++;
                        
                        // 🔍 ตรวจคอลัมน์ขั้นต่ำ (Name + Email)
                        if (count($row) < 2) {
                            $skippedDetails[] = "แถวที่ $rowNumber: ข้อมูลไม่ครบ (ต้องมีอย่างน้อย ชื่อ และ อีเมล)";
                            continue;
                        }
                        
                        $name = trim($row[0]);
                        $email = trim($row[1]);
                        $phone = trim($row[2] ?? '');
                        
                        if (empty($name) || empty($email)) {
                            $skippedDetails[] = "แถวที่ $rowNumber: ชื่อหรืออีเมลว่างเปล่า";
                            continue;
                        }
                        
                        // 🔄 [WRITE] Upsert ผ่าน MemberService::importMember():
                        //    email มีแล้ว → update name/phone (ไม่แก้ password)
                        //    email ใหม่ → create พร้อม default password (123456)
                        // ⚠️ ต้องแจ้งสมาชิกใหม่เปลี่ยนรหัสผ่านทันที
                        try {
                            $result = $memberService->importMember([
                                'name' => $name,
                                'email' => $email,
                                'phone' => $phone
                            ]);
                            
                            if ($result['action'] === 'created') {
                                $createdCount++;
                            } else {
                                $updatedCount++;
                            }
                        } catch (Exception $e) {
                            $skippedDetails[] = "แถวที่ $rowNumber: " . $e->getMessage();
                            continue;
                        }
                    }
                    
                    $pdo->commit();
                    
                    $msg = "นำเข้าเสร็จสิ้น: เพิ่มใหม่ $createdCount คน, อัปเดต $updatedCount คน";
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
                    // [CLEANUP] ปิด file handle เสมอ
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                }
            }
        }
    }
}

$pageTitle = 'นำเข้าสมาชิก (Import Members)';
require_once __DIR__ . '/header.php';
?>

<div class="max-w-3xl mx-auto">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
            <h5 class="font-bold text-gray-800 flex items-center">
                <i class="bi bi-people mr-2 text-blue-600"></i>นำเข้าสมาชิกจาก CSV
            </h5>
            <a href="members.php" class="text-sm text-gray-500 hover:text-gray-700">
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
                <p class="text-sm text-gray-600 mb-2">ลำดับคอลัมน์: <strong>Name, Email, Phone</strong></p>
                <div class="bg-gray-50 p-3 rounded-lg border border-gray-200 text-sm mb-3">
                    <strong>ตัวอย่างข้อมูล:</strong><br>
                    Somchai Jaidee, somchai@example.com, 0812345678<br>
                    Mana Jaihan, mana@example.com, 0899998888
                </div>
                <div class="alert alert-info bg-blue-50 text-blue-800 text-xs p-3 rounded-lg border border-blue-100">
                    <i class="bi bi-info-circle mr-1"></i>
                    รหัสผ่านเริ่มต้นของทุกคนจะเป็น: <strong>123456</strong>
                </div>
                <div class="mt-2">
                    <a href="data:text/csv;charset=utf-8,Name,Email,Phone%0ASomchai,somchai@test.com,0812345678" download="template_members.csv" class="text-primary-600 hover:text-primary-700 text-sm font-medium inline-flex items-center">
                        <i class="bi bi-download mr-1"></i>ดาวน์โหลดเทมเพลต
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
                    
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 px-4 rounded-xl transition-colors shadow-sm flex items-center justify-center">
                        <i class="bi bi-check-lg mr-2"></i>เริ่มนำเข้าสมาชิก
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
