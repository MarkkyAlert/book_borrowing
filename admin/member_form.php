<?php
/**
 * Member Form - เพิ่ม/แก้ไขสมาชิก
 */

require_once __DIR__ . '/../includes/functions.php';
requireStaff(); // Auth check ก่อนทำงานใดๆ
require_once __DIR__ . '/../includes/db.php';

$pdo = getDB();
$errors = [];
$member = [
    'id' => 0,
    'name' => '',
    'email' => '',
    'phone' => '',
    'password' => ''
];
$isEdit = false;

// Get member for editing
if (isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'member'");
    $stmt->execute([$id]);
    $existingMember = $stmt->fetch();
    
    if ($existingMember) {
        $member = $existingMember;
        $isEdit = true;
    } else {
        setFlash('error', 'ไม่พบสมาชิกที่ต้องการแก้ไข');
        redirect('members.php');
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง กรุณาลองใหม่');
        redirect('members.php');
    }
    
    $member['name'] = trim($_POST['name'] ?? '');
    $member['email'] = trim($_POST['email'] ?? '');
    $member['phone'] = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    
    $isEdit = !empty($_POST['id']);
    $member['id'] = (int) ($_POST['id'] ?? 0);
    
    // Validation
    if (empty($member['name'])) {
        $errors[] = 'กรุณากรอกชื่อ-นามสกุล';
    } elseif (mb_strlen($member['name']) > 100) {
        $errors[] = 'ชื่อต้องไม่เกิน 100 ตัวอักษร';
    }
    
    if (empty($member['email'])) {
        $errors[] = 'กรุณากรอกอีเมล';
    } elseif (!filter_var($member['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
    }
    
    // Check Email duplicate
    if (!empty($member['email'])) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$member['email'], $member['id']]);
        if ($stmt->fetch()) {
            $errors[] = 'อีเมลนี้มีในระบบแล้ว';
        }
    }
    
    // Password validation
    if (!$isEdit && empty($password)) {
        $errors[] = 'กรุณากรอกรหัสผ่าน';
    } elseif (!empty($password) && strlen($password) < 6) {
        $errors[] = 'รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร';
    }
    
    if (empty($errors)) {
        if ($isEdit) {
            // Update
            $sql = "UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?";
            $params = [$member['name'], $member['email'], $member['phone'], $member['id']];
            
            // Update password only if provided
            if (!empty($password)) {
                $sql = "UPDATE users SET name = ?, email = ?, phone = ?, password = ? WHERE id = ?";
                $params = [
                    $member['name'], 
                    $member['email'], 
                    $member['phone'], 
                    password_hash($password, PASSWORD_DEFAULT),
                    $member['id']
                ];
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            setFlash('success', 'อัปเดตข้อมูลสมาชิกสำเร็จ');
        } else {
            // Insert
            $stmt = $pdo->prepare("
                INSERT INTO users (name, email, password, phone, role)
                VALUES (?, ?, ?, ?, 'member')
            ");
            $stmt->execute([
                $member['name'],
                $member['email'],
                password_hash($password, PASSWORD_DEFAULT),
                $member['phone']
            ]);
            setFlash('success', 'เพิ่มสมาชิกสำเร็จ');
        }
        redirect('members.php');
    }
}

$pageTitle = $isEdit ? 'แก้ไขสมาชิก' : 'เพิ่มสมาชิก';
require_once __DIR__ . '/header.php';
?>

<div class="max-w-2xl mx-auto">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50 flex items-center">
            <h5 class="font-bold text-gray-800 flex items-center text-lg">
                <i class="bi bi-person-<?= $isEdit ? 'gear' : 'plus' ?>-fill mr-2 text-primary-600"></i>
                <?= $isEdit ? 'แก้ไขข้อมูลสมาชิก' : 'เพิ่มสมาชิกใหม่' ?>
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
            
            <form method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <?php if ($isEdit): ?>
                    <input type="hidden" name="id" value="<?= $member['id'] ?>">
                <?php endif; ?>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2">
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">
                            ชื่อ-นามสกุล <span class="text-red-500">*</span>
                        </label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="bi bi-person text-gray-400"></i>
                            </div>
                            <input type="text" id="name" name="name" value="<?= e($member['name']) ?>" required autofocus
                                   class="pl-10 block w-full rounded-xl border-gray-300 focus:ring-primary-500 focus:border-primary-500 sm:text-sm h-11"
                                   placeholder="เช่น สมชาย ใจดี">
                        </div>
                    </div>
                    
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">
                            อีเมล <span class="text-red-500">*</span>
                        </label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="bi bi-envelope text-gray-400"></i>
                            </div>
                            <input type="email" id="email" name="email" value="<?= e($member['email']) ?>" required
                                   class="pl-10 block w-full rounded-xl border-gray-300 focus:ring-primary-500 focus:border-primary-500 sm:text-sm h-11"
                                   placeholder="user@example.com">
                        </div>
                    </div>
                    
                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">เบอร์โทรศัพท์</label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="bi bi-telephone text-gray-400"></i>
                            </div>
                            <input type="text" id="phone" name="phone" value="<?= e($member['phone'] ?? '') ?>"
                                   class="pl-10 block w-full rounded-xl border-gray-300 focus:ring-primary-500 focus:border-primary-500 sm:text-sm h-11"
                                   placeholder="08xxxxxxxx">
                        </div>
                    </div>
                    
                    <div class="md:col-span-2">
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                            <?= $isEdit ? 'รหัสผ่านใหม่ (ว่างไว้ถ้าไม่ต้องการเปลี่ยน)' : 'รหัสผ่าน' ?> 
                            <?php if (!$isEdit): ?><span class="text-red-500">*</span><?php endif; ?>
                        </label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="bi bi-key text-gray-400"></i>
                            </div>
                            <input type="password" id="password" name="password" minlength="6" <?= !$isEdit ? 'required' : '' ?>
                                   class="pl-10 block w-full rounded-xl border-gray-300 focus:ring-primary-500 focus:border-primary-500 sm:text-sm h-11"
                                   placeholder="<?= $isEdit ? 'เปลี่ยนรหัสผ่านใหม่' : 'กำหนดรหัสผ่านอย่างน้อย 6 ตัวอักษร' ?>">
                        </div>
                        <?php if ($isEdit): ?>
                            <p class="mt-1 text-xs text-gray-500">
                                <i class="bi bi-info-circle mr-1"></i>
                                หากไม่ต้องการเปลี่ยนรหัสผ่าน ให้เว้นช่องนี้ว่างไว้
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="pt-6 flex items-center justify-between border-t border-gray-100 mt-2">
                    <a href="members.php" class="px-5 py-2.5 border border-gray-300 shadow-sm text-sm font-medium rounded-xl text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                        <i class="bi bi-arrow-left mr-1"></i>กลับ
                    </a>
                    <button type="submit" class="px-5 py-2.5 border border-transparent shadow-sm text-sm font-medium rounded-xl text-white <?= $isEdit ? 'bg-amber-500 hover:bg-amber-600 focus:ring-amber-500 shadow-amber-500/30' : 'bg-primary-600 hover:bg-primary-700 focus:ring-primary-500 shadow-primary-500/30' ?> focus:outline-none focus:ring-2 focus:ring-offset-2 transition-colors shadow-lg">
                        <i class="bi bi-check-lg mr-1"></i>
                        <?= $isEdit ? 'บันทึกการแก้ไข' : 'เพิ่มสมาชิก' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
