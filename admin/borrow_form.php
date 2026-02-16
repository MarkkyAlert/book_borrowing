<?php

/**
 * Borrow Form - บันทึกการยืม (Enhanced UX)
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้มี 2 mode ที่ทำงานในไฟล์เดียวกัน:
 *   1. AJAX scan (POST action=scan) → ค้นหาสมาชิก/หนังสือแบบ real-time → return JSON
 *   2. Form submit (POST) → BorrowService::createBorrow() → สร้างรายการยืม
 * - รองรับยืมหลายเล่มพร้อมกัน (book_ids เป็น array)
 * - สิทธิ์: staff ขึ้นไป
 * 
 * ⚠️ ระวัง:
 * - AJAX scan ต้อง exit หลัง echo JSON — ห้ามให้ไหลไปถึง HTML
 * - createBorrow() ใช้ transaction — ยืมทุกเล่มสำเร็จหรือ rollback ทั้งหมด
 * - Idempotency key ใช้ hash ของ userId + bookIds ป้องกัน double-submit
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/../bootstrap.php';
// 🔒 [AUTH] staff/admin เท่านั้น
requireStaff();

use App\Repositories\BookRepository;  // ดึงหนังสือที่ available > 0
use App\Repositories\UserRepository;  // ดึงรายชื่อสมาชิก + ค้นหาสมาชิก
use App\Services\BorrowService;       // Business logic: createBorrow (transaction + stock check)

// 📦 สร้าง service/repository instances
$pdo = getDB();
$bookRepo = new BookRepository($pdo);
$userRepo = new UserRepository($pdo);
$borrowService = new BorrowService($pdo);

$errors = [];

// 📚 ดึงหนังสือที่ available > 0 สำหรับ dropdown (Select2)
$availableBooks = $bookRepo->findAvailable();

// 👥 ดึงสมาชิกทั้งหมดสำหรับ dropdown (Select2)
$members = $userRepo->findAllMembers();

// ── Mode 1: AJAX Scan (POST action=scan) ──
// 🔍 ค้นหาสมาชิก/หนังสือแบบ real-time → return JSON
//    ใช้กับ barcode scanner หรือพิมพ์ ID แล้วกด Enter
// ⚠️ ต้อง exit หลัง echo JSON — ห้ามให้ไหลไปถึง HTML
if (isset($_POST['action']) && $_POST['action'] === 'scan') {
    header('Content-Type: application/json');

    // [SECURITY] CSRF check - defense-in-depth แม้จะเป็น read-only
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit;
    }

    $type = $_POST['type'] ?? '';
    $id = trim($_POST['id'] ?? '');

    try {
        if ($type === 'user') {
            $user = $userRepo->findMemberById((int)$id);
            echo json_encode(['success' => !!$user, 'data' => $user, 'message' => $user ? 'พบสมาชิก' : 'ไม่พบสมาชิก']);
        } elseif ($type === 'book') {
            // Find book by ID or ISBN
            $book = $bookRepo->findByIdOrIsbn($id);

            if ($book) {
                if ($book['available'] > 0) {
                    echo json_encode(['success' => true, 'data' => $book]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'หนังสือหมด']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'ไม่พบหนังสือ']);
            }
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── Mode 2: Form Submit (POST) ──
// 📝 สร้างรายการยืมจริง → BorrowService::createBorrow()
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ [SECURITY] CSRF — ป้องกัน attacker หลอกให้ staff กดยืมโดยไม่รู้ตัว
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง กรุณาลองใหม่');
        redirect('borrow_form.php');
    }

    // 📥 Sanitize input — validation หนักๆ ทำใน BorrowService (Single Source of Truth)
    $userId = (int) ($_POST['user_id'] ?? 0);
    $bookIds = $_POST['book_ids'] ?? [];               // array ของ book IDs ที่เลือก
    $borrowDays = (int) ($_POST['borrow_days'] ?? DEFAULT_BORROW_DAYS); // จำนวนวันยืม

    if (!is_array($bookIds)) {
        $bookIds = [$bookIds];
    }
    $bookIds = array_filter(array_map('intval', $bookIds));

    // 🚀 เรียก BorrowService — จัดการ validation + transaction + stock lock
    {
        // [IDEMPOTENCY] ป้องกัน double-submit (กดปุ่มซ้ำ / refresh)
        // 🧠 sort เพื่อให้ key เหมือนกันไม่ว่าจะเลือกลำดับไหน
        //    เช่น [3,1,2] กับ [1,2,3] → md5 เดียวกัน
        sort($bookIds);
        $idempotencyKey = 'borrow_' . $userId . '_' . md5(json_encode($bookIds));

        if (isset($_SESSION['processed_actions'][$idempotencyKey])) {
            $processedAt = $_SESSION['processed_actions'][$idempotencyKey];
            if (time() - $processedAt < 60) { // ภายใน 60 วินาที ถือว่าซ้ำ
                setFlash('info', 'รายการนี้ถูกบันทึกไปแล้ว');
                redirect('borrows.php');
            }
        }

        try {
            $result = $borrowService->createBorrow($userId, $bookIds, $borrowDays);

            // บันทึก idempotency key หลังสำเร็จ
            $_SESSION['processed_actions'][$idempotencyKey] = time();

            // 🛡️ [ATOMIC] ถ้า createBorrow() สำเร็จ จะ return success=true เสมอ
            //    ถ้าเล่มใดมีปัญหา จะ throw Exception → ไปที่ catch block ด้านล่าง
            setFlash('success', $result['message']);

            redirect('borrows.php');
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = 'บันทึกการยืม';
require_once __DIR__ . '/header.php';
?>

<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    /* Custom Select2 Styles for Tailwind */
    .select2-container .select2-selection--single,
    .select2-container .select2-selection--multiple {
        height: auto !important;
        min-height: 42px;
        border-color: #d1d5db !important;
        /* gray-300 */
        border-radius: 0.75rem !important;
        /* rounded-xl */
        padding: 0.25rem;
    }

    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 40px;
    }

    .select2-container--default .select2-selection--multiple .select2-selection__choice {
        background-color: #f3f4f6;
        /* gray-100 */
        border: 1px solid #e5e7eb;
        /* gray-200 */
        border-radius: 0.5rem;
        padding: 2px 8px;
        margin-top: 4px;
        color: #374151;
        /* gray-700 */
    }

    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
        color: #ef4444;
        /* red-500 */
        border-right: 1px solid #e5e7eb;
        margin-right: 5px;
    }

    .select2-container--focus .select2-selection--single,
    .select2-container--focus .select2-selection--multiple {
        border-color: #0d9488 !important;
        /* teal-600 (primary) */
        box-shadow: 0 0 0 1px #0d9488;
    }
</style>

<div class="max-w-3xl mx-auto">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50 flex items-center justify-between">
            <h5 class="font-bold text-gray-800 flex items-center text-lg">
                <i class="bi bi-plus-circle mr-2 text-primary-600"></i>บันทึกการยืมหนังสือ
            </h5>
            <div class="flex items-center space-x-2 text-sm text-gray-500">
                <span class="inline-flex items-center px-2 py-1 rounded-md bg-white border border-gray-200">
                    <i class="bi bi-qr-code-scan mr-1.5"></i>Quick Scan Enabled
                </span>
            </div>
        </div>

        <!-- Scan Section -->
        <div class="px-6 pt-6 pb-2 bg-indigo-50/50 border-b border-indigo-100">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Scan User -->
                <div>
                    <label class="block text-xs font-bold text-indigo-900 uppercase tracking-wider mb-1">
                        1. สแกนบัตรสมาชิก (User ID)
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="bi bi-person-badge text-indigo-400"></i>
                        </div>
                        <input type="text" id="scan_user" class="block w-full pl-10 h-10 border-indigo-200 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 text-sm font-mono" placeholder="คลิกแล้วยิงบาร์โค้ด..." autocomplete="off">
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                            <span id="scan_user_status" class="text-xs"></span>
                        </div>
                    </div>
                </div>

                <!-- Scan Book -->
                <div>
                    <label class="block text-xs font-bold text-indigo-900 uppercase tracking-wider mb-1">
                        2. สแกนหนังสือ (Book ID/ISBN)
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="bi bi-upc-scan text-indigo-400"></i>
                        </div>
                        <input type="text" id="scan_book" class="block w-full pl-10 h-10 border-indigo-200 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 text-sm font-mono" placeholder="ยิงบาร์โค้ดหนังสือ..." autocomplete="off">
                    </div>
                </div>
            </div>
            <p class="text-xs text-indigo-400 mt-2 mb-2 flex items-center">
                <i class="bi bi-lightning-charge-fill mr-1"></i>
                ระบบจะค้นหาและเลือกให้อัตโนมัติเมื่อกด Enter (หรือยิงจากเครื่องสแกน)
            </p>
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

            <?php if (empty($availableBooks)): ?>
                <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-amber-800 flex items-center">
                    <i class="bi bi-exclamation-triangle mr-2 text-xl"></i>
                    ไม่มีหนังสือที่พร้อมให้ยืม
                </div>
            <?php elseif (empty($members)): ?>
                <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-amber-800 flex items-center">
                    <i class="bi bi-exclamation-triangle mr-2 text-xl"></i>
                    ยังไม่มีสมาชิกในระบบ
                </div>
            <?php else: ?>
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">

                    <div>
                        <div class="flex justify-between items-center mb-1">
                            <label for="user_id" class="block text-sm font-medium text-gray-700">
                                ผู้ยืม <span class="text-red-500">*</span>
                            </label>
                            <button type="button" class="text-sm text-emerald-600 hover:text-emerald-700 font-medium flex items-center" onclick="openAddMemberModal()">
                                <i class="bi bi-person-plus mr-1"></i>เพิ่มสมาชิกใหม่
                            </button>
                        </div>
                        <select id="user_id" name="user_id" class="w-full" required>
                            <option value="">พิมพ์เพื่อค้นหาสมาชิก...</option>
                            <?php foreach ($members as $member): ?>
                                <option value="<?= $member['id'] ?>"
                                    data-email="<?= e($member['email']) ?>"
                                    data-phone="<?= e($member['phone'] ?? '-') ?>">
                                    <?= e($member['name']) ?> (<?= e($member['email']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="book_ids" class="block text-sm font-medium text-gray-700 mb-1">
                            หนังสือ <span class="text-red-500">*</span>
                        </label>
                        <select id="book_ids" name="book_ids[]" multiple="multiple" class="w-full" required>
                            <?php foreach ($availableBooks as $book): ?>
                                <option value="<?= $book['id'] ?>">
                                    <?= e($book['title']) ?> - <?= e($book['author']) ?> (ว่าง <?= $book['available'] ?> เล่ม)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="mt-2 flex items-center justify-between text-xs text-gray-500">
                            <span>พิมพ์เพื่อค้นหา</span>
                            <span class="bg-blue-50 text-blue-600 px-2 py-0.5 rounded-full border border-blue-100">
                                เลือกได้สูงสุด <?= MAX_BORROW_BOOKS ?> เล่ม
                            </span>
                        </div>
                    </div>

                    <div>
                        <label for="borrow_days" class="block text-sm font-medium text-gray-700 mb-1">
                            จำนวนวันที่ยืม
                        </label>
                        <div class="flex rounded-md shadow-sm w-32">
                            <input type="number" id="borrow_days" name="borrow_days" value="<?= DEFAULT_BORROW_DAYS ?>"
                                class="focus:ring-primary-500 focus:border-primary-500 flex-1 block w-full rounded-none rounded-l-xl border-gray-300 sm:text-sm text-center"
                                min="1" max="30">
                            <span class="inline-flex items-center px-3 rounded-r-xl border border-l-0 border-gray-300 bg-gray-50 text-gray-500 sm:text-sm">
                                วัน
                            </span>
                        </div>
                    </div>

                    <div class="pt-4 flex items-center justify-between border-t border-gray-100">
                        <a href="borrows.php" class="px-5 py-2.5 border border-gray-300 shadow-sm text-sm font-medium rounded-xl text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                            <i class="bi bi-arrow-left mr-1"></i>กลับ
                        </a>
                        <button type="submit" class="px-5 py-2.5 border border-transparent shadow-sm text-sm font-medium rounded-xl text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors shadow-lg shadow-primary-500/30">
                            <i class="bi bi-check-lg mr-1"></i>บันทึกการยืม
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Info Card -->
    <div class="mt-6 bg-blue-50 border border-blue-100 rounded-2xl p-6">
        <h6 class="text-blue-800 font-bold flex items-center mb-3">
            <i class="bi bi-info-circle mr-2 text-xl"></i>ข้อมูล
        </h6>
        <ul class="space-y-2 text-sm text-blue-800/80 list-disc list-inside">
            <li>ยืมได้สูงสุด <?= MAX_BORROW_BOOKS ?> เล่มต่อคน</li>
            <li>ระยะเวลายืมเริ่มต้น <?= DEFAULT_BORROW_DAYS ?> วัน</li>
            <li>พิมพ์ชื่อเพื่อค้นหาผู้ยืมหรือหนังสือ</li>
            <li>สามารถเลือกยืมหลายเล่มพร้อมกันได้</li>
            <li><strong>ไม่เจอสมาชิก?</strong> กดปุ่ม "เพิ่มสมาชิกใหม่"</li>
        </ul>
    </div>
</div>

<!-- Add Member Modal (Tailwind) -->
<div id="addMemberModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity opacity-0" id="addMemberBackdrop"></div>

    <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0">
        <div class="relative w-full max-w-md transform overflow-hidden rounded-2xl bg-white text-left shadow-xl transition-all sm:my-8 opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" id="addMemberPanel">

            <div class="bg-gradient-to-r from-emerald-500 to-teal-600 px-4 py-4 sm:px-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold leading-6 text-white flex items-center">
                        <i class="bi bi-person-plus-fill mr-2"></i>เพิ่มสมาชิกใหม่
                    </h3>
                    <button type="button" class="text-white/80 hover:text-white focus:outline-none" onclick="closeAddMemberModal()">
                        <i class="bi bi-x-lg text-lg"></i>
                    </button>
                </div>
            </div>

            <div class="px-4 py-6 sm:p-6">
                <div id="addMemberAlert" class="hidden mb-4 p-3 rounded-lg text-sm bg-red-50 text-red-700 border border-red-200 items-center">
                    <i class="bi bi-exclamation-circle-fill mr-2"></i>
                    <span id="addMemberAlertText"></span>
                </div>

                <form id="addMemberForm" class="space-y-4">
                    <div>
                        <label for="new_name" class="block text-sm font-medium text-gray-700 mb-1">ชื่อ-นามสกุล <span class="text-red-500">*</span></label>
                        <input type="text" id="new_name" name="name" required
                            class="w-full rounded-xl border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 shadow-sm"
                            placeholder="กรอกชื่อ-นามสกุล">
                    </div>
                    <div>
                        <label for="new_email" class="block text-sm font-medium text-gray-700 mb-1">อีเมล <span class="text-red-500">*</span></label>
                        <input type="email" id="new_email" name="email" required
                            class="w-full rounded-xl border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 shadow-sm"
                            placeholder="example@mail.com">
                    </div>
                    <div>
                        <label for="new_phone" class="block text-sm font-medium text-gray-700 mb-1">เบอร์โทร</label>
                        <input type="text" id="new_phone" name="phone"
                            class="w-full rounded-xl border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 shadow-sm"
                            placeholder="08xxxxxxxx">
                    </div>
                </form>
            </div>

            <div class="bg-gray-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6 gap-2">
                <button type="button" class="inline-flex w-full items-center justify-center gap-1.5 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 transition-all sm:w-auto shadow-emerald-500/30" id="saveMemberBtn">
                    <i class="bi bi-check-lg text-base leading-none"></i>
                    <span class="leading-none">บันทึกสมาชิก</span>
                </button>
                <button type="button" class="mt-3 inline-flex w-full justify-center rounded-xl bg-white px-5 py-2.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto" onclick="closeAddMemberModal()">
                    ยกเลิก
                </button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>

<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() {
        // Initialize Select2 with simple theme
        $('#user_id').select2({
            width: '100%'
        });

        $('#book_ids').select2({
            width: '100%',
            maximumSelectionLength: <?= MAX_BORROW_BOOKS ?>,
            language: {
                maximumSelected: function(e) {
                    return 'เลือกได้สูงสุด ' + e.maximum + ' เล่ม';
                },
                noResults: function() {
                    return 'ไม่พบหนังสือ';
                },
                searching: function() {
                    return 'กำลังค้นหา...';
                }
            }
        });

        // Add Member Modal Logic
        const modal = document.getElementById('addMemberModal');
        const backdrop = document.getElementById('addMemberBackdrop');
        const panel = document.getElementById('addMemberPanel');

        window.openAddMemberModal = function() {
            modal.classList.remove('hidden');
            setTimeout(() => {
                backdrop.classList.remove('opacity-0');
                panel.classList.remove('opacity-0', 'translate-y-4', 'sm:translate-y-0', 'sm:scale-95');
                panel.classList.add('opacity-100', 'translate-y-0', 'sm:scale-100');
            }, 10);
        };

        window.closeAddMemberModal = function() {
            backdrop.classList.add('opacity-0');
            panel.classList.remove('opacity-100', 'translate-y-0', 'sm:scale-100');
            panel.classList.add('opacity-0', 'translate-y-4', 'sm:translate-y-0', 'sm:scale-95');
            setTimeout(() => {
                modal.classList.add('hidden');
                $('#addMemberForm')[0].reset();
                $('#addMemberAlert').addClass('hidden');
            }, 300);
        };

        // Quick Add Member AJAX
        $('#saveMemberBtn').on('click', function() {
            var btn = $(this);
            var alert = $('#addMemberAlert');
            var alertText = $('#addMemberAlertText');

            var name = $('#new_name').val().trim();
            var email = $('#new_email').val().trim();
            var phone = $('#new_phone').val().trim();

            if (!name || !email) {
                alert.removeClass('hidden bg-green-50 text-green-700 border-green-200').addClass('bg-red-50 text-red-700 border-red-200 flex');
                alertText.text('กรุณากรอกชื่อและอีเมล');
                return;
            }

            var originalBtnHtml = btn.html();
            btn.prop('disabled', true).html('<span class="inline-block animate-spin mr-2">&#9696;</span>กำลังบันทึก...');

            $.ajax({
                url: '../api/add_member.php',
                method: 'POST',
                data: {
                    name: name,
                    email: email,
                    phone: phone,
                    csrf_token: '<?= generateCSRFToken() ?>'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Add new option to Select2
                        var newOption = new Option(
                            response.member.name + ' (' + response.member.email + ')',
                            response.member.id,
                            true, true
                        );
                        $('#user_id').append(newOption).trigger('change');

                        closeAddMemberModal();

                        // Show success toast
                        const toast = document.createElement('div');
                        toast.className = 'fixed bottom-4 right-4 bg-emerald-600 text-white px-6 py-3 rounded-xl shadow-lg transform transition-all duration-300 translate-y-20 opacity-0 z-20 flex items-center';
                        toast.innerHTML = '<i class="bi bi-check-circle-fill mr-2"></i> เพิ่มสมาชิก "' + response.member.name + '" สำเร็จ';
                        document.body.appendChild(toast);

                        requestAnimationFrame(() => {
                            toast.classList.remove('translate-y-20', 'opacity-0');
                        });

                        setTimeout(() => {
                            toast.classList.add('translate-y-20', 'opacity-0');
                            setTimeout(() => toast.remove(), 300);
                        }, 3000);

                    } else {
                        alert.removeClass('hidden').addClass('flex');
                        alertText.text(response.message);
                    }
                },
                error: function() {
                    alert.removeClass('hidden').addClass('flex');
                    alertText.text('เกิดข้อผิดพลาด กรุณาลองใหม่');
                },
                complete: function() {
                    btn.prop('disabled', false).html(originalBtnHtml);
                }
            });
        });

        // === Quick Scan Logic ===

        // Scan User Input
        $('#scan_user').on('keypress', function(e) {
            if (e.which === 13) { // Enter key
                e.preventDefault();
                var userId = $(this).val().trim();
                if (!userId) return;

                // Show loading state
                $('#scan_user_status').html('<span class="text-indigo-500 animate-pulse">กำลังค้นหา...</span>');

                $.ajax({
                    url: 'borrow_form.php',
                    method: 'POST',
                    // 
                    data: {
                        action: 'scan',
                        type: 'user',
                        id: userId,
                        csrf_token: '<?= generateCSRFToken() ?>'
                    },
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            $('#user_id').val(res.data.id).trigger('change');
                            $('#scan_user').val(''); // Clear input
                            $('#scan_user_status').html('<span class="text-emerald-600 font-bold"><i class="bi bi-check-circle-fill"></i> พบสมาชิก</span>');

                            // Move focus to book scan
                            $('#scan_book').focus();

                            // Clear status after 2s
                            setTimeout(function() {
                                $('#scan_user_status').text('');
                            }, 2000);
                        } else {
                            $('#scan_user_status').html('<span class="text-red-500 font-bold"><i class="bi bi-x-circle-fill"></i> ไม่พบ</span>');
                            // Select nothing
                            $('#user_id').val(null).trigger('change');

                            // Play error sound (optional beep)
                        }
                    },
                    error: function() {
                        $('#scan_user_status').html('<span class="text-red-500">Error</span>');
                    }
                });
            }
        });

        // Scan Book Input
        $('#scan_book').on('keypress', function(e) {
            if (e.which === 13) { // Enter key
                e.preventDefault();
                var bookId = $(this).val().trim();
                if (!bookId) return;

                // Disable input temporarily
                var input = $(this);
                input.prop('disabled', true);

                $.ajax({
                    url: 'borrow_form.php',
                    method: 'POST',
                    // 
                    data: {
                        action: 'scan',
                        type: 'book',
                        id: bookId,
                        csrf_token: '<?= generateCSRFToken() ?>'
                    },
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            // Check if already selected
                            var currentVal = $('#book_ids').val() || [];
                            var idStr = res.data.id.toString();

                            if (currentVal.includes(idStr)) {
                                // Already selected
                                showScanToast('หนังสือนี้ถูกเลือกแล้ว', 'warning');
                            } else {
                                // Add to selection
                                var newSet = currentVal.concat(idStr);
                                $('#book_ids').val(newSet).trigger('change');
                                showScanToast('เพิ่ม "' + res.data.title + '" แล้ว', 'success');
                            }
                            input.val(''); // Clear
                        } else {
                            showScanToast(res.message || 'ไม่พบหนังสือ', 'error');
                        }
                    },
                    error: function() {
                        showScanToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
                    },
                    complete: function() {
                        input.prop('disabled', false).focus(); // Re-enable and keep focus
                    }
                });
            }
        });

        // Helper Toast for Scan
        function showScanToast(msg, type) {
            var bgColor = type === 'success' ? 'bg-emerald-600' : (type === 'warning' ? 'bg-amber-500' : 'bg-red-500');
            var icon = type === 'success' ? 'bi-check-lg' : (type === 'warning' ? 'bi-exclamation-triangle-fill' : 'bi-x-lg');

            var toast = $('<div class="fixed top-20 right-4 ' + bgColor + ' text-white px-4 py-2 rounded-lg shadow-lg z-20 flex items-center text-sm font-medium animate-bounce-in">' +
                '<i class="bi ' + icon + ' mr-2"></i>' + msg + '</div>');

            $('body').append(toast);
            setTimeout(function() {
                toast.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 2000);
        }
    });
</script>