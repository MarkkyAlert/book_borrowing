<?php
/**
 * User Management - จัดการผู้ใช้ (member + staff)
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้แสดงรายการผู้ใช้ทุก role (member + staff, ไม่รวม admin)
 * - เพิ่ม/แก้ไข/เปลี่ยน role อยู่ที่ member_form.php, ลบอยู่ที่หน้านี้
 * - สิทธิ์: staff ขึ้นไป
 * 
 * 📂 Flow:
 * 1. GET → MemberService::getMembers(filters) → แสดงรายการ (พร้อม borrow stats)
 * 2. POST action=delete → MemberService::deleteMember() → redirect (PRG)
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/../bootstrap.php';
// 🔒 [AUTH] staff/admin เท่านั้น
requireStaff();

use App\Services\MemberService;

// 📦 สร้าง service instance — MemberService จัดการ filter, sort, borrow stats ให้
$pdo = getDB();
$memberService = new MemberService($pdo);

// ── POST: ลบผู้ใช้ (ทำก่อน fetch data — PRG pattern) ──
// 🧠 ดัดแปลงจาก books.php — ใช้แพทเทิร์นเดียวกัน
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    // 🛡️ [SECURITY] CSRF
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'คำขอไม่ถูกต้อง กรุณาลองใหม่');
        redirectToList('members.php', LIST_STATE_MEMBERS);
    }
    
    $id = (int) ($_POST['id'] ?? 0);
    
    // 🛡️ [IDEMPOTENCY] ป้องกัน double-submit (กดลบซ้ำ)
    $idempotencyKey = 'delete_member_' . $id;
    if (isset($_SESSION['processed_actions'][$idempotencyKey])) {
        setFlash('info', 'รายการนี้ถูกลบไปแล้ว');
        redirectToList('members.php', LIST_STATE_MEMBERS);
    }
    
    try {
        $memberService->deleteMember($id);
        // 🛡️ [IDEMPOTENCY] บันทึกว่า process แล้ว
        $_SESSION['processed_actions'][$idempotencyKey] = time();
        setFlash('success', 'ลบผู้ใช้สำเร็จ');
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
    }
    redirectToList('members.php', LIST_STATE_MEMBERS);
}

// 📥 รับ filter/sort จาก query string
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';    // has_borrow | no_borrow | ''
$role = $_GET['role'] ?? '';        // member | staff | ''
$sort = $_GET['sort'] ?? 'newest'; // newest | oldest | az | most_borrows
$page = (int) ($_GET['page'] ?? 1);

// 🔧 filter ชุดเดียว ใช้ทั้งนับยอดรวมและดึงรายการ — ต้องเป็นชุดเดียวกันเป๊ะ
//    ไม่งั้นจะบอกจำนวนหน้าผิด (เช่น บอกมี 5 หน้า แต่หน้า 5 ว่างเปล่า)
$memberFilters = [
    'search' => $search,
    'status' => $status,
    'role' => $role,
    'sort' => $sort
];

// 📄 นับยอดรวมก่อน แล้วคำนวณว่าอยู่หน้าไหน ต้องข้ามกี่แถว
$pagination = paginate($memberService->countFilteredMembers($memberFilters), $page, ITEMS_PER_PAGE);
$memberFilters['limit']  = $pagination['per_page'];
$memberFilters['offset'] = $pagination['offset'];

// 📊 ดึงผู้ใช้ "เฉพาะหน้านี้" พร้อม borrow stats (active_borrows, total_borrows) ผ่าน Service
$members = $memberService->getMembers($memberFilters);

// 📄 filter ที่ต้องติดไปกับลิงก์เปลี่ยนหน้า — ไม่งั้นกดหน้า 2 แล้วตัวกรองหาย
$paginationParams = ['search' => $search, 'status' => $status, 'role' => $role, 'sort' => $sort];
$paginationUnit = 'คน';

$pageTitle = 'จัดการผู้ใช้';
require_once __DIR__ . '/header.php';
?>

<!-- Actions Bar -->
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
    <div>
        <h3 class="text-lg font-bold text-gray-800">จัดการผู้ใช้</h3>
        <p class="text-sm text-gray-500">ทั้งหมด <?= number_format($pagination['total']) ?> คน</p>
    </div>
    <div class="flex gap-2">
        <a href="import_members.php" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium rounded-xl transition-colors shadow-sm">
            <i class="bi bi-file-earmark-spreadsheet mr-2 text-blue-600"></i>Import CSV
        </a>
        <a href="member_form.php" class="inline-flex items-center px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-xl transition-colors shadow-lg shadow-primary-500/30">
            <i class="bi bi-person-plus-fill mr-2"></i>เพิ่มสมาชิก
        </a>
    </div>
</div>

<!-- Search & Filter -->
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
    <div class="text-sm font-bold text-gray-700 mb-4 flex items-center">
        <i class="bi bi-funnel mr-2"></i>ตัวกรอง
    </div>
    <form method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
        <div class="md:col-span-4">
            <label class="block text-xs font-medium text-gray-700 mb-1">ค้นหา</label>
            <input type="text" class="w-full border-gray-300 rounded-lg text-sm focus:ring-primary-500 focus:border-primary-500" name="search" value="<?= e($search) ?>" placeholder="ชื่อ, อีเมล, เบอร์โทร...">
        </div>
        <div class="md:col-span-2">
            <label class="block text-xs font-medium text-gray-700 mb-1">สิทธิ์</label>
            <select class="w-full border-gray-300 rounded-lg text-sm focus:ring-primary-500 focus:border-primary-500" name="role">
                <option value="">ทั้งหมด</option>
                <option value="member" <?= $role === 'member' ? 'selected' : '' ?>>สมาชิก</option>
                <option value="staff" <?= $role === 'staff' ? 'selected' : '' ?>>เจ้าหน้าที่</option>
            </select>
        </div>
        <div class="md:col-span-2">
            <label class="block text-xs font-medium text-gray-700 mb-1">สถานะ</label>
            <select class="w-full border-gray-300 rounded-lg text-sm focus:ring-primary-500 focus:border-primary-500" name="status">
                <option value="">ทั้งหมด</option>
                <option value="has_borrow" <?= $status === 'has_borrow' ? 'selected' : '' ?>>กำลังยืมหนังสือ</option>
                <option value="no_borrow" <?= $status === 'no_borrow' ? 'selected' : '' ?>>ปกติ (ไม่ได้ยืม)</option>
            </select>
        </div>
        <div class="md:col-span-2">
            <label class="block text-xs font-medium text-gray-700 mb-1">เรียงลำดับ</label>
            <select class="w-full border-gray-300 rounded-lg text-sm focus:ring-primary-500 focus:border-primary-500" name="sort">
                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>สมัครล่าสุด</option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>เก่าที่สุด</option>
                <option value="az" <?= $sort === 'az' ? 'selected' : '' ?>>ชื่อ (A-Z, ก-ฮ)</option>
                <option value="most_borrows" <?= $sort === 'most_borrows' ? 'selected' : '' ?>>ยืมบ่อยสุด</option>
            </select>
        </div>
        <div class="md:col-span-2 flex gap-2">
            <button type="submit" class="flex-1 bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                <i class="bi bi-search mr-1"></i>ค้นหา
            </button>
            <a href="members.php" class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm font-medium transition-colors">ล้าง</a>
        </div>
    </form>
</div>

<!-- Members Table -->
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
        <?php if (empty($members)): ?>
            <div class="text-center py-12 text-gray-400">
                <i class="bi bi-people text-6xl mb-4 inline-block text-gray-300"></i>
                <h4 class="text-lg font-medium text-gray-600">ไม่พบผู้ใช้</h4>
                <p class="text-sm">ลองปรับเปลี่ยนคำค้นหา</p>
            </div>
        <?php else: ?>
            <table class="w-full text-sm text-left">
                <thead class="text-xs text-gray-500 uppercase bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="px-6 py-4 font-medium" width="50">#</th>
                        <th class="px-6 py-4 font-medium">ชื่อ-นามสกุล</th>
                        <th class="px-6 py-4 font-medium">สิทธิ์</th>
                        <th class="px-6 py-4 font-medium">อีเมล</th>
                        <th class="px-6 py-4 font-medium">เบอร์โทร</th>
                        <th class="px-6 py-4 font-medium text-center">โควตาที่ใช้</th>
                        <th class="px-6 py-4 font-medium text-center">ยืมทั้งหมด</th>
                        <th class="px-6 py-4 font-medium">สมัครเมื่อ</th>
                        <th class="px-6 py-4 font-medium text-end" width="100">การจัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($members as $index => $member): ?>
                        <tr class="hover:bg-gray-50/50 transition-colors">
                            <td class="px-6 py-4 text-gray-500"><?= $pagination['offset'] + $index + 1 ?></td>
                            <td class="px-6 py-4">
                                <div class="flex items-center">
                                    <div class="h-8 w-8 rounded-full bg-primary-100 text-primary-600 flex items-center justify-center mr-3 font-bold text-xs">
                                        <?= mb_substr($member['name'], 0, 1) ?>
                                    </div>
                                    <div class="font-medium text-gray-900"><?= e($member['name']) ?></div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($member['role'] === 'staff'): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">
                                        <i class="bi bi-shield-check mr-1"></i>เจ้าหน้าที่
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                        สมาชิก
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-gray-600"><?= e($member['email']) ?></td>
                            <td class="px-6 py-4 text-gray-600 font-mono text-xs"><?= e($member['phone'] ?: '-') ?></td>
                            <td class="px-6 py-4 text-center">
                                <?php
                                // 🔴 [F-41] โควตา = ยืมค้าง + จองที่ของพร้อมแล้ว
                                //    เดิมแสดงแค่ active_borrows → เจ้าหน้าที่เห็น "2 เล่ม"
                                //    แล้วสรุปว่ายืมได้อีก 1 ทั้งที่เต็มแล้ว เพราะมีการจองอีก 1
                                //
                                // 🔴 waiting (ต่อคิวรอ) **ห้ามเอามารวม** — ไม่กินโควตายืม
                                //    ถ้ารวม คนที่ต่อคิว 3 เล่มจะขึ้นว่าเต็มโควตา ทั้งที่ยืมได้อีก 3
                                //    แสดงแยกไว้เป็นบรรทัดรองแทน เพื่อให้เห็นภาพครบโดยไม่ปนตัวเลข
                                $qBorrow  = (int) ($member['active_borrows'] ?? 0);
                                $qPending = (int) ($member['pending_reservations'] ?? 0);
                                $qWaiting = (int) ($member['waiting_reservations'] ?? 0);
                                $qUsed    = $qBorrow + $qPending;
                                $qFull    = $qUsed >= MAX_BORROW_BOOKS;
                                ?>
                                <?php if ($qUsed > 0 || $qWaiting > 0): ?>
                                    <div class="flex flex-col items-center gap-1">
                                        <?php if ($qUsed > 0): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $qFull ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800' ?>"
                                                  title="<?= $qFull ? 'เต็มโควตาแล้ว ยืมเพิ่มไม่ได้' : 'ยืมได้อีก ' . (MAX_BORROW_BOOKS - $qUsed) . ' เล่ม' ?>">
                                                <?= $qUsed ?>/<?= MAX_BORROW_BOOKS ?>
                                                <?= $qFull ? ' เต็ม' : '' ?>
                                            </span>
                                            <?php if ($qPending > 0): ?>
                                                <?php // 📝 บอกที่มาของตัวเลขเมื่อไม่ได้มาจากการยืมล้วน ?>
                                                <span class="text-[11px] text-gray-500 leading-tight">
                                                    ยืม <?= $qBorrow ?> · จอง <?= $qPending ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-gray-300">-</span>
                                        <?php endif; ?>

                                        <?php if ($qWaiting > 0): ?>
                                            <?php // 🔄 คิวรอไม่กินโควตา แสดงแยกคนละสี ไม่ให้อ่านปนกับตัวเลขบน ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-indigo-50 text-indigo-700"
                                                  title="ต่อคิวรอหนังสือที่ถูกยืมหมด — ไม่กินโควตายืม">
                                                <i class="bi bi-people mr-1"></i>คิว <?= $qWaiting ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-gray-300">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                                    <?= $member['total_borrows'] ?> ครั้ง
                                </span>
                            </td>
                            <td class="px-6 py-4 text-gray-500 text-xs">
                                <?= formatDate($member['created_at'], 'd/m/Y') ?>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex justify-end gap-1">
                                    <button type="button" class="p-1.5 text-sky-500 hover:text-sky-700 hover:bg-sky-50 rounded-lg transition-colors" 
                                            onclick="openHistoryModal(<?= $member['id'] ?>)" 
                                            title="ประวัติการยืม">
                                        <i class="bi bi-clock-history"></i>
                                    </button>
                                    <button type="button" class="p-1.5 text-indigo-500 hover:text-indigo-700 hover:bg-indigo-50 rounded-lg transition-colors" 
                                            onclick="window.open('member_card.php?id=<?= $member['id'] ?>', '_blank', 'width=600,height=400')" 
                                            title="พิมพ์บัตร">
                                        <i class="bi bi-person-vcard"></i>
                                    </button>
                                    <a href="<?= e(listStateLink('member_form.php?id=' . $member['id'], LIST_STATE_MEMBERS)) ?>" class="p-1.5 text-amber-500 hover:text-amber-700 hover:bg-amber-50 rounded-lg transition-colors" title="แก้ไข">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>
                                    <?php
                                    // 🚫 ถามกฎจาก Service — ตรงกับ guard ใน MemberService::deleteMember()
                                    //    (เดิมเช็คแค่ active_borrows → ปุ่มเปิดใช้งานทั้งที่ลบไม่ได้)
                                    $deleteBlockedReason = $memberService->getDeleteBlockReason($member);
                                    ?>
                                    <?php if ($deleteBlockedReason === null): ?>
                                        <?php // 🔴 [F-47] บอกชื่อ + รหัสสมาชิก — ชื่อซ้ำกันเป๊ะมีจริง (ดู F-51)
                                              //    รหัสใช้สูตรเดียวกับบัตรสมาชิกและดรอปดาวน์ผู้ยืม ให้ตรงกันทั้งระบบ ?>
                                        <form method="POST" class="inline-block" onsubmit="return confirmSubmit(this, <?= jsString("ลบสมาชิก {$member['name']}\nรหัส " . str_pad((string) $member['id'], 6, '0', STR_PAD_LEFT)) ?>, {title: 'ลบสมาชิก', confirmText: 'ลบ', confirmClass: 'danger'})">
                                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $member['id'] ?>">
                                            <button type="submit" class="p-1.5 text-red-500 hover:text-red-700 hover:bg-red-50 rounded-lg transition-colors" title="ลบ">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button class="p-1.5 text-gray-300 rounded-lg cursor-not-allowed" disabled title="<?= e($deleteBlockedReason) ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php // 📄 แถบเลือกหน้า (ไม่แสดงถ้ามีหน้าเดียว) ?>
<?php require __DIR__ . '/../includes/pagination.php'; ?>

<!-- Single Borrow History Modal (AJAX loaded - Fix N+1 query) -->
<div id="historyModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop"></div>
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="relative transform overflow-hidden rounded-2xl bg-white text-left shadow-xl transition-all w-full max-w-4xl opacity-0 translate-y-4 modal-panel">
            <div class="bg-gray-50 px-4 py-3 border-b border-gray-100 sm:px-6 flex justify-between items-center">
                <h3 class="text-lg font-bold text-gray-900 flex items-center">
                    <i class="bi bi-clock-history mr-2 text-primary-600"></i>
                    ประวัติการยืม - <span id="modalMemberName"></span>
                </h3>
                <button type="button" class="text-gray-400 hover:text-gray-500 focus:outline-none" onclick="closeHistoryModal()">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="p-0" id="modalContent">
                <div class="text-center py-12 text-gray-400">
                    <i class="bi bi-arrow-repeat text-4xl mb-2 inline-block text-gray-300 animate-spin"></i>
                    <p>กำลังโหลด...</p>
                </div>
            </div>
            <div class="bg-gray-50 px-4 py-3 sm:px-6 flex justify-end">
                <button type="button" class="inline-flex justify-center rounded-xl bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50" onclick="closeHistoryModal()">
                    ปิด
                </button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>

<script>
const modal = document.getElementById('historyModal');
const backdrop = modal.querySelector('.modal-backdrop');
const panel = modal.querySelector('.modal-panel');

// 🛡️ [XSS] escape HTML entities ก่อนใส่ innerHTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// 📅 แปลง YYYY-MM-DD ที่ API คืนมา เป็น DD/MM/YYYY ให้ตรงกับทั้งระบบ (formatDate ฝั่ง PHP)
//    🔴 [F-19] ห้ามย้ายการแปลงนี้ไปไว้ใน api/member_history.php หรือ Repository!
//       เพราะโค้ดตรวจ "เกินกำหนด" ด้านล่างใช้ new Date(item.due_date) ซึ่ง
//       parse "2026-07-28" ได้ แต่ "28/07/2026" จะกลายเป็น Invalid Date
//       → เงื่อนไขเป็น false เสมอ รายการที่เกินกำหนดจะแสดงเป็น "กำลังยืม" แบบเงียบ ๆ
//       จึงต้องแปลงตอนแสดงผลเท่านั้น และให้ค่าดิบไปถึงการคำนวณ
function formatDateTH(isoDate) {
    if (!isoDate) return '-';
    const m = String(isoDate).match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? `${m[3]}/${m[2]}/${m[1]}` : String(isoDate);
}

function openHistoryModal(id) {
    const memberRow = document.querySelector(`[onclick="openHistoryModal(${id})"]`);
    const memberName = memberRow ? memberRow.closest('tr').querySelector('.font-medium.text-gray-900')?.textContent?.trim() : '';
    document.getElementById('modalMemberName').textContent = memberName || 'สมาชิก';
    document.getElementById('modalContent').innerHTML = '<div class="text-center py-12 text-gray-400"><i class="bi bi-arrow-repeat text-4xl mb-2 inline-block text-gray-300 animate-spin"></i><p>กำลังโหลด...</p></div>';
    
    modal.classList.remove('hidden');
    setTimeout(() => {
        backdrop.classList.remove('opacity-0');
        panel.classList.remove('opacity-0', 'translate-y-4');
        panel.classList.add('opacity-100', 'translate-y-0');
    }, 10);
    
    fetch('../api/member_history.php?id=' + id)
        .then(r => r.json())
        .then(data => {
            if (!data.length) {
                document.getElementById('modalContent').innerHTML = '<div class="text-center py-12 text-gray-400"><i class="bi bi-journal-x text-4xl mb-2 inline-block text-gray-300"></i><p>ยังไม่มีประวัติการยืม</p></div>';
                return;
            }
            let html = '<div class="overflow-x-auto"><table class="w-full text-sm text-left"><thead class="text-xs text-gray-500 uppercase bg-gray-50 border-b border-gray-100"><tr><th class="px-6 py-3">หนังสือ</th><th class="px-6 py-3">วันที่ยืม</th><th class="px-6 py-3">กำหนดคืน</th><th class="px-6 py-3">วันที่คืน</th><th class="px-6 py-3">สถานะ</th></tr></thead><tbody class="divide-y divide-gray-100">';
            data.forEach(item => {
                let statusClass, statusText;
                if (item.status === 'returned') {
                    statusClass = 'bg-green-100 text-green-800';
                    statusText = '<i class="bi bi-check-circle-fill mr-1"></i>คืนแล้ว';
                } else if (item.due_date && new Date(item.due_date) < new Date(new Date().toDateString())) {
                    statusClass = 'bg-red-100 text-red-800';
                    statusText = '<i class="bi bi-exclamation-circle-fill mr-1"></i>เกินกำหนด';
                } else {
                    statusClass = 'bg-blue-100 text-blue-800';
                    statusText = '<i class="bi bi-clock-fill mr-1"></i>กำลังยืม';
                }
                html += `<tr class="hover:bg-gray-50/50">
                    <td class="px-6 py-3 font-medium text-gray-900 line-clamp-1 max-w-[200px]">${escapeHtml(item.book_title)}</td>
                    <td class="px-6 py-3 text-gray-500">${escapeHtml(formatDateTH(item.borrow_date))}</td>
                    <td class="px-6 py-3 text-gray-500">${escapeHtml(formatDateTH(item.due_date))}</td>
                    <td class="px-6 py-3 text-gray-500">${escapeHtml(formatDateTH(item.return_date))}</td>
                    <td class="px-6 py-3"><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${statusClass}">${statusText}</span></td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            document.getElementById('modalContent').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('modalContent').innerHTML = '<div class="text-center py-12 text-red-400"><p>เกิดข้อผิดพลาด กรุณาลองใหม่</p></div>';
        });
}

function closeHistoryModal() {
    backdrop.classList.add('opacity-0');
    panel.classList.remove('opacity-100', 'translate-y-0');
    panel.classList.add('opacity-0', 'translate-y-4');
    setTimeout(() => { modal.classList.add('hidden'); }, 300);
}

modal.addEventListener('click', function(e) {
    if (e.target === backdrop || e.target === modal) closeHistoryModal();
});
</script>
