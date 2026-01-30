<?php
/**
 * Borrow Form - บันทึกการยืม (Enhanced UX)
 */

require_once __DIR__ . '/../includes/functions.php';
requireStaff(); // Auth check ก่อนทำงานใดๆ
require_once __DIR__ . '/../includes/db.php';

$pdo = getDB();
$errors = [];
$successCount = 0;

// Get available books (with at least 1 copy available)
$availableBooks = $pdo->query("
    SELECT * FROM books WHERE available > 0 ORDER BY title
")->fetchAll();

// Get members
$members = $pdo->query("
    SELECT id, name, email, phone FROM users WHERE role = 'member' ORDER BY name
")->fetchAll();

// Handle AJAX Scan Requests
if (isset($_POST['action']) && $_POST['action'] === 'scan') {
    header('Content-Type: application/json');
    $type = $_POST['type'] ?? '';
    $id = trim($_POST['id'] ?? '');

    try {
        if ($type === 'user') {
            $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ? AND role = 'member'");
            $stmt->execute([$id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => !!$user, 'data' => $user, 'message' => $user ? 'พบสมาชิก' : 'ไม่พบสมาชิก']);
        } elseif ($type === 'book') {
            // Find book by ID (Barcode often used as ID in simple systems, or dedicated barcode field if exists. Here using ID)
            $stmt = $pdo->prepare("SELECT id, title, author, available FROM books WHERE (id = ? OR isbn = ?)");
            $stmt->execute([$id, $id]);
            $book = $stmt->fetch(PDO::FETCH_ASSOC);
            
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง กรุณาลองใหม่');
        redirect('borrow_form.php');
    }
    
    $userId = (int) ($_POST['user_id'] ?? 0);
    $bookIds = $_POST['book_ids'] ?? [];
    $borrowDays = (int) ($_POST['borrow_days'] ?? DEFAULT_BORROW_DAYS);
    
    // Ensure book_ids is array
    if (!is_array($bookIds)) {
        $bookIds = [$bookIds];
    }
    $bookIds = array_filter(array_map('intval', $bookIds));
    
    // Validation
    if ($userId <= 0) {
        $errors[] = 'กรุณาเลือกผู้ยืม';
    }
    
    if (empty($bookIds)) {
        $errors[] = 'กรุณาเลือกหนังสืออย่างน้อย 1 เล่ม';
    }
    
    if ($borrowDays < 1 || $borrowDays > 30) {
        $errors[] = 'จำนวนวันยืมต้องอยู่ระหว่าง 1-30 วัน';
    }
    
    // Validate user exists and is member
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'member'");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            $errors[] = 'ไม่พบสมาชิกที่เลือก';
        }
    }
    
    if (empty($errors)) {
        $borrowDate = date('Y-m-d');
        $dueDate = date('Y-m-d', strtotime("+{$borrowDays} days"));
        
        // Begin transaction
        $pdo->beginTransaction();
        
        try {
            // Check member's current borrows (with lock)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrows WHERE user_id = ? AND status = 'borrowing' FOR UPDATE");
            $stmt->execute([$userId]);
            $currentBorrows = $stmt->fetchColumn();
            
            $availableSlots = MAX_BORROW_BOOKS - $currentBorrows;
            
            if ($availableSlots <= 0) {
                throw new Exception('ผู้ยืมถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว (' . MAX_BORROW_BOOKS . ' เล่ม)');
            }
            
            if (count($bookIds) > $availableSlots) {
                throw new Exception("ผู้ยืมสามารถยืมได้อีก {$availableSlots} เล่มเท่านั้น");
            }
            
            $borrowedBooks = [];
            $skippedBooks = [];
            
            foreach ($bookIds as $bookId) {
                // Lock book row
                $stmt = $pdo->prepare("SELECT id, title, available FROM books WHERE id = ? FOR UPDATE");
                $stmt->execute([$bookId]);
                $book = $stmt->fetch();
                
                if (!$book) {
                    $skippedBooks[] = "หนังสือ ID: {$bookId} ไม่พบ";
                    continue;
                }
                
                if ($book['available'] <= 0) {
                    $skippedBooks[] = $book['title'] . ' (ไม่มีเล่มว่าง)';
                    continue;
                }
                
                // Check if already borrowing this book
                $stmt = $pdo->prepare("SELECT id FROM borrows WHERE user_id = ? AND book_id = ? AND status = 'borrowing'");
                $stmt->execute([$userId, $bookId]);
                if ($stmt->fetch()) {
                    $skippedBooks[] = $book['title'] . ' (ยืมอยู่แล้ว)';
                    continue;
                }
                
                // Insert borrow record
                $stmt = $pdo->prepare("
                    INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status)
                    VALUES (?, ?, ?, ?, 'borrowing')
                ");
                $stmt->execute([$userId, $bookId, $borrowDate, $dueDate]);
                
                // Update book available count
                $stmt = $pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?");
                $stmt->execute([$bookId]);
                
                $borrowedBooks[] = $book['title'];
                $successCount++;
            }
            
            $pdo->commit();
            
            if ($successCount > 0) {
                $message = "บันทึกการยืมสำเร็จ {$successCount} เล่ม";
                if (!empty($skippedBooks)) {
                    $message .= " (ข้าม: " . implode(', ', $skippedBooks) . ")";
                }
                $message .= " | กำหนดคืน: " . formatDate($dueDate);
                setFlash('success', $message);
            } else {
                setFlash('warning', 'ไม่สามารถยืมหนังสือได้: ' . implode(', ', $skippedBooks));
            }
            
            redirect('borrows.php');
        } catch (Exception $e) {
            $pdo->rollBack();
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
        border-color: #d1d5db !important; /* gray-300 */
        border-radius: 0.75rem !important; /* rounded-xl */
        padding: 0.25rem;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 40px;
    }
    .select2-container--default .select2-selection--multiple .select2-selection__choice {
        background-color: #f3f4f6; /* gray-100 */
        border: 1px solid #e5e7eb; /* gray-200 */
        border-radius: 0.5rem;
        padding: 2px 8px;
        margin-top: 4px;
        color: #374151; /* gray-700 */
    }
    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
        color: #ef4444; /* red-500 */
        border-right: 1px solid #e5e7eb;
        margin-right: 5px;
    }
    .select2-container--focus .select2-selection--single,
    .select2-container--focus .select2-selection--multiple {
        border-color: #0d9488 !important; /* teal-600 (primary) */
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

    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
        <div class="relative transform overflow-hidden rounded-2xl bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" id="addMemberPanel">
            
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
                <button type="button" class="inline-flex w-full justify-center rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 transition-all sm:w-auto shadow-emerald-500/30" id="saveMemberBtn">
                    <i class="bi bi-check-lg mr-1.5 text-lg"></i>บันทึกสมาชิก
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
            url: 'ajax_add_member.php',
            method: 'POST',
            data: { name: name, email: email, phone: phone, csrf_token: '<?= generateCSRFToken() ?>' },
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
                    toast.className = 'fixed bottom-4 right-4 bg-emerald-600 text-white px-6 py-3 rounded-xl shadow-lg transform transition-all duration-300 translate-y-20 opacity-0 z-50 flex items-center';
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

    // ----------------------------------------------------
    // QUICK SCAN LOGIC
    // ----------------------------------------------------
    
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
                data: { action: 'scan', type: 'user', id: userId },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        $('#user_id').val(res.data.id).trigger('change');
                        $('#scan_user').val(''); // Clear input
                        $('#scan_user_status').html('<span class="text-emerald-600 font-bold"><i class="bi bi-check-circle-fill"></i> พบสมาชิก</span>');
                        
                        // Move focus to book scan
                        $('#scan_book').focus();
                        
                        // Clear status after 2s
                        setTimeout(function() { $('#scan_user_status').text(''); }, 2000);
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
                data: { action: 'scan', type: 'book', id: bookId },
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
        
        var toast = $('<div class="fixed top-20 right-4 ' + bgColor + ' text-white px-4 py-2 rounded-lg shadow-lg z-50 flex items-center text-sm font-medium animate-bounce-in">' +
                      '<i class="bi ' + icon + ' mr-2"></i>' + msg + '</div>');
        
        $('body').append(toast);
        setTimeout(function() {
             toast.fadeOut(300, function() { $(this).remove(); });
        }, 2000);
    }
});
</script>
