<?php
/**
 * Member Form - เพิ่ม/แก้ไขสมาชิก
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้ทำ 2 อย่าง: สร้างสมาชิกใหม่ หรือ แก้ไขข้อมูลสมาชิก (รวมเปลี่ยนรหัสผ่าน)
 * - admin เท่านั้นที่เห็น dropdown เปลี่ยน role (member ↔ staff)
 * - ใช้ MemberService เป็น single source of truth สำหรับ business logic
 * - สิทธิ์: staff ขึ้นไป (แต่เปลี่ยน role ได้เฉพาะ admin)
 * 
 * 📂 Flow:
 * 1. GET ?id=X      → โหลดข้อมูลสมาชิกเข้า form (edit mode)
 * 2. GET (ไม่มี id) → form ว่าง (create mode)
 * 3. POST (id=0)    → createMember() (ถ้าไม่กรอกรหัสผ่าน Service จะ auto-generate)
 * 4. POST (id>0)    → updateMember(role?) + updatePassword() ถ้ากรอกรหัสผ่านใหม่
 * 
 * ⚠️ ระวัง:
 * - createMember() อาจ auto-generate password — ต้องแจ้ง staff
 * - การลบสมาชิกไม่ได้อยู่ในหน้านี้ (อยู่ที่ members.php)
 * - role whitelist: member, staff เท่านั้น (Service ป้องกัน privilege escalation)
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/../bootstrap.php';
// 🔒 [AUTH] staff/admin เท่านั้น
requireStaff();

use App\Services\MemberService;

// 📦 สร้าง service instance — MemberService เป็น Single Source of Truth สำหรับ member CRUD
$pdo = getDB();
$memberService = new MemberService($pdo);

$errors = [];
// 📝 ค่า default สำหรับ create mode — จะถูก overwrite ถ้าเป็น edit mode
$member = [
    'id' => 0,
    'name' => '',
    'email' => '',
    'phone' => '',
    'password' => ''
];
$isEdit = false;

// ── Edit Mode: โหลดข้อมูลสมาชิกเข้า form ──
if (isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    $existingMember = $memberService->getMemberById($id);
    
    if ($existingMember) {
        $member = $existingMember;
        $isEdit = true;
    } else {
        setFlash('error', 'ไม่พบสมาชิกที่ต้องการแก้ไข');
        redirectToList('members.php', LIST_STATE_MEMBERS, $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET, 'ret_');
    }
}

// ── POST: บันทึกข้อมูลสมาชิก (create หรือ update) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ [SECURITY] CSRF — ป้องกัน attacker หลอกให้ staff แก้ข้อมูลสมาชิก
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง กรุณาลองใหม่');
        redirectToList('members.php', LIST_STATE_MEMBERS, $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET, 'ret_');
    }
    
    $member['name'] = trim($_POST['name'] ?? '');
    $member['email'] = trim($_POST['email'] ?? '');
    $member['phone'] = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? null;
    
    $member['id'] = (int) ($_POST['id'] ?? 0);
    $isEdit = $member['id'] > 0;
    
    // 🔍 Validation ผ่าน shared helper (Single Source of Truth — ใช้ร่วมกับหน้าอื่นที่จัดการสมาชิก)
    //    Email duplicate ตรวจที่ Service ฝ่ายเดียว — ไม่ต้องตรวจซ้ำที่นี่
    $errors = array_merge($errors, validateMemberData([
        'name' => $member['name'], 'email' => $member['email'],
        'phone' => $member['phone'], 'password' => $password
    ], $isEdit));
    
    if (empty($errors)) {
        try {
            if ($isEdit) {
                // [WRITE] อัปเดตข้อมูลสมาชิก ผ่าน Service (ตรวจ email ซ้ำให้)
                $updateData = [
                    'name' => $member['name'],
                    'email' => $member['email'],
                    'phone' => $member['phone']
                ];
                // 🏷️ admin เท่านั้นที่เปลี่ยน role ได้ (Service whitelist ซ้ำอีกชั้น)
                if (isAdmin() && $role !== null) {
                    $updateData['role'] = $role;
                }
                $memberService->updateMember($member['id'], $updateData);
                
                // 🔑 อัปเดตรหัสผ่านเฉพาะถ้ากรอก — Service จะ validate + hash ให้
                if (!empty($password)) {
                    $memberService->updatePassword($member['id'], $password);
                }
                
                setFlash('success', 'อัปเดตข้อมูลสมาชิกสำเร็จ');
            } else {
                // [WRITE] สร้างสมาชิกใหม่ — ถ้าไม่กรอกรหัสผ่าน Service จะ auto-generate ให้
                //    🔑 [F-53] ส่ง true = บังคับเปลี่ยนรหัสตอนล็อกอินครั้งแรก
                //       เพราะทางนี้ **เจ้าหน้าที่เป็นคนรู้รหัส** (กรอกเอง หรือระบบสุ่มแล้วโชว์ให้ดู)
                //       สมาชิกจึงต้องตั้งรหัสที่มีแต่ตัวเองรู้ก่อนใช้งานจริง
                $memberService->createMember([
                    'name' => $member['name'],
                    'email' => $member['email'],
                    'phone' => $member['phone'],
                    'password' => $password
                ], true);
                setFlash('success', 'เพิ่มสมาชิกสำเร็จ');
            }
            redirectToList('members.php', LIST_STATE_MEMBERS, $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET, 'ret_');
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
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
            
            <form method="POST" class="space-y-6" id="memberForm" onsubmit="return confirmRoleChange(this);">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <?php // 📄 พาหน้า/ตัวกรองของรายการกลับไปด้วยหลังบันทึก (F-37)
                      //    ถ้าไม่ส่งต่อตรงนี้ ต่อให้ redirect ถูกก็กู้สถานะไม่ได้
                      //    เพราะมันหายไปตั้งแต่ตอนกดลิงก์ "แก้ไข" แล้ว ?>
                <?= listStateHiddenInputs(LIST_STATE_MEMBERS) ?>
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
                    
                    <?php if (isAdmin() && $isEdit): ?>
                    <div class="md:col-span-2">
                        <label for="role" class="block text-sm font-medium text-gray-700 mb-1">
                            สิทธิ์การใช้งาน
                        </label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="bi bi-shield-lock text-gray-400"></i>
                            </div>
                            <?php // 🔴 [F-47] เก็บ role เดิมไว้ให้ JS เทียบ — ยืนยันเฉพาะตอน "เปลี่ยนจริง"
                                  //    ถ้าเด้งกล่องทุกครั้งที่กดบันทึก เจ้าหน้าที่จะกดตกลงอัตโนมัติจนกล่องไร้ความหมาย ?>
                            <select id="role" name="role"
                                    data-original="<?= e($member['role'] ?? 'member') ?>"
                                    data-member-name="<?= e($member['name'] ?? '') ?>"
                                    class="pl-10 block w-full rounded-xl border-gray-300 focus:ring-primary-500 focus:border-primary-500 sm:text-sm h-11">
                                <option value="member" <?= ($member['role'] ?? 'member') === 'member' ? 'selected' : '' ?>>สมาชิก (Member)</option>
                                <option value="staff" <?= ($member['role'] ?? 'member') === 'staff' ? 'selected' : '' ?>>เจ้าหน้าที่ (Staff)</option>
                            </select>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">
                            <i class="bi bi-info-circle mr-1"></i>
                            เจ้าหน้าที่สามารถเข้าถึงระบบจัดการ (admin panel) ได้
                        </p>
                    </div>
                    <?php endif; ?>

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
                    <a href="members.php<?= listStateQuery(listState(LIST_STATE_MEMBERS, null, 'ret_')) ?>" class="px-5 py-2.5 border border-gray-300 shadow-sm text-sm font-medium rounded-xl text-gray-700 bg-white hover:bg-gray-50 transition-colors">
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

<script>
/**
 * 🔴 [F-47] เดิมเปลี่ยนสิทธิ์เป็น "เจ้าหน้าที่" ได้ด้วยการกดบันทึกครั้งเดียว ไม่มีการยืนยันเลย
 *    ทั้งที่ผลคือคนนั้นเข้าระบบจัดการหลังบ้านได้ทันที — เห็นข้อมูลสมาชิกทุกคน
 *    แก้/ลบหนังสือได้ รับชำระค่าปรับได้ ร้ายแรงกว่าการลบหนังสือหนึ่งเล่มมาก
 *
 * 🧠 ยืนยันเฉพาะตอน role เปลี่ยนจริง (เทียบกับ data-original)
 *    กดบันทึกเพื่อแก้เบอร์โทรเฉย ๆ ต้องไม่มีกล่องเด้ง ไม่งั้นคนจะกดตกลงโดยไม่อ่าน
 */
function confirmRoleChange(form) {
    var sel = form.querySelector('#role');

    // ไม่มีดรอปดาวน์ (ไม่ใช่ admin หรือเป็นการเพิ่มสมาชิกใหม่) → ไม่ต้องยืนยัน
    if (!sel) return true;

    var original = sel.getAttribute('data-original');
    if (sel.value === original) return true;

    var name = sel.getAttribute('data-member-name') || 'สมาชิกคนนี้';
    var msg, opts;

    if (sel.value === 'staff') {
        msg = 'ให้สิทธิ์เจ้าหน้าที่กับ ' + name + '\n\n'
            + 'คนนี้จะเข้าระบบจัดการหลังบ้านได้ทันที — เห็นข้อมูลสมาชิกทุกคน '
            + 'แก้ไข/ลบหนังสือ และรับชำระค่าปรับได้';
        opts = { title: 'ให้สิทธิ์เจ้าหน้าที่', confirmText: 'ให้สิทธิ์', confirmClass: 'danger', type: 'danger' };
    } else {
        msg = 'ถอดสิทธิ์เจ้าหน้าที่ของ ' + name + '\n\n'
            + 'คนนี้จะเข้าระบบจัดการหลังบ้านไม่ได้อีก และจะกลายเป็นสมาชิกทั่วไป';
        opts = { title: 'ถอดสิทธิ์เจ้าหน้าที่', confirmText: 'ถอดสิทธิ์', confirmClass: 'danger', type: 'danger' };
    }

    return confirmSubmit(form, msg, opts);
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
