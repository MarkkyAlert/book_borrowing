<?php
/**
 * Admin: System Settings
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireAdmin();

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token ไม่ถูกต้อง');
    } else {
        updateSetting('org_name', $_POST['org_name']);
        updateSetting('card_color_primary', $_POST['card_color_primary']);
        updateSetting('card_color_secondary', $_POST['card_color_secondary']);
        
        setFlash('success', 'บันทึกการตั้งค่าเรียบร้อยแล้ว');
        redirect('settings.php');
    }
}

$orgName = getSetting('org_name', 'LIBRARY CARD');
$cardColorPrimary = getSetting('card_color_primary', '#1e3a8a');
$cardColorSecondary = getSetting('card_color_secondary', '#3b82f6');

$pageTitle = 'ตั้งค่าระบบ';
require_once __DIR__ . '/header.php';
?>

<div class="mb-6">
    <h3 class="text-2xl font-bold text-gray-800 flex items-center">
        <i class="bi bi-gear-fill mr-3 text-primary-600"></i>
        ตั้งค่าระบบ (System Settings)
    </h3>
    <p class="text-gray-500">ปรับแต่งค่าต่างๆ ของระบบ</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Settings Form -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50">
                <h5 class="font-bold text-gray-800">ตั้งค่าบัตรสมาชิก (Member Card)</h5>
            </div>
            
            <div class="p-6">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <div>
                        <label for="org_name" class="block text-sm font-medium text-gray-700 mb-1">
                            ชื่อหน่วยงาน / หัวบัตร
                        </label>
                        <input type="text" id="org_name" name="org_name" value="<?= e($orgName) ?>" required
                               class="w-full rounded-xl border-gray-300 focus:border-primary-500 focus:ring-primary-500 shadow-sm"
                               placeholder="เช่น A.B.C. SCHOOL LIBRARY">
                        <p class="mt-1 text-xs text-gray-500">ข้อความที่จะแสดงบนหัวบัตรสมาชิก</p>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="card_color_primary" class="block text-sm font-medium text-gray-700 mb-1">
                                สีธีมหลัก (Primary Color)
                            </label>
                            <div class="flex items-center space-x-2">
                                <input type="color" id="card_color_primary" name="card_color_primary" value="<?= e($cardColorPrimary) ?>" 
                                       class="h-10 w-14 p-1 rounded border border-gray-300 cursor-pointer">
                                <input type="text" value="<?= e($cardColorPrimary) ?>" readonly 
                                       class="flex-1 rounded-xl border-gray-300 bg-gray-50 text-gray-500 text-sm">
                            </div>
                        </div>
                        
                        <div>
                            <label for="card_color_secondary" class="block text-sm font-medium text-gray-700 mb-1">
                                สีธีมรอง (Secondary Color)
                            </label>
                            <div class="flex items-center space-x-2">
                                <input type="color" id="card_color_secondary" name="card_color_secondary" value="<?= e($cardColorSecondary) ?>" 
                                       class="h-10 w-14 p-1 rounded border border-gray-300 cursor-pointer">
                                <input type="text" value="<?= e($cardColorSecondary) ?>" readonly 
                                       class="flex-1 rounded-xl border-gray-300 bg-gray-50 text-gray-500 text-sm">
                            </div>
                        </div>
                    </div>
                    
                    <div class="pt-4 border-t border-gray-100 flex justify-end">
                        <button type="submit" class="px-6 py-2.5 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-xl shadow-lg shadow-primary-500/30 transition-all transform hover:scale-105">
                            <i class="bi bi-save mr-2"></i>บันทึกการตั้งค่า
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Preview Card -->
    <div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden sticky top-6">
            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50">
                <h5 class="font-bold text-gray-800">ตัวอย่าง (Preview)</h5>
            </div>
            <div class="p-6 flex justify-center bg-gray-100 min-h-[300px] items-center">
                <!-- CSS Filter to simulate card look tailored to settings -->
                <!-- Note: Ideally we'd use iframe, but let's approximate with inline css js -->
                <div id="cardPreview" class="relative bg-white rounded-lg shadow-md overflow-hidden border border-gray-200" style="width: 320px; height: 200px;">
                    <div id="previewSideBar" style="position: absolute; top: 0; left: 0; width: 15px; height: 100%; background: linear-gradient(180deg, <?= $cardColorPrimary ?> 0%, <?= $cardColorSecondary ?> 100%);"></div>
                    
                    <div style="margin-left: 20px; padding: 15px; height: 100%; display: flex; flex-direction: column;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 5px;">
                            <i class="bi bi-book-half text-xl" style="color: <?= $cardColorPrimary ?>;"></i>
                            <div id="previewOrgName" style="font-weight: 800; font-size: 14px; text-transform: uppercase; color: <?= $cardColorPrimary ?>;">
                                <?= e($orgName) ?>
                            </div>
                        </div>
                        
                        <div style="margin-top: 10px; padding-left: 10px;">
                            <div style="font-size: 10px; background: #e0f2fe; color: #0369a1; padding: 1px 6px; border-radius: 4px; display: inline-block; margin-bottom: 5px; font-weight: 600;">MEMBER</div>
                            <div style="font-size: 8px; color: #64748b;">NAME</div>
                            <div style="font-size: 14px; font-weight: 700; color: #0f172a;">Somchai Jaidee</div>
                            <div style="font-size: 14px; font-weight: 700; color: #0f172a;">ID: 000001</div>
                        </div>
                        
                        <div style="margin-top: auto; padding-left: 5px; opacity: 0.5;">
                            [Barcode Area]
                        </div>
                    </div>
                </div>
            </div>
            <p class="text-center text-xs text-gray-500 py-3">ตัวอย่างการแสดงผลเบื้องต้น</p>
        </div>
    </div>
</div>

<script>
    // Real-time Preview Logic
    const orgInput = document.getElementById('org_name');
    const color1Input = document.getElementById('card_color_primary');
    const color2Input = document.getElementById('card_color_secondary');
    
    const previewOrg = document.getElementById('previewOrgName');
    const previewBar = document.getElementById('previewSideBar');
    const previewIcon = document.querySelector('#cardPreview i');

    function updatePreview() {
        previewOrg.textContent = orgInput.value || 'LIBRARY CARD';
        previewOrg.style.color = color1Input.value;
        previewIcon.style.color = color1Input.value;
        previewBar.style.background = `linear-gradient(180deg, ${color1Input.value} 0%, ${color2Input.value} 100%)`;
    }

    orgInput.addEventListener('input', updatePreview);
    color1Input.addEventListener('input', updatePreview);
    color2Input.addEventListener('input', updatePreview);
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
