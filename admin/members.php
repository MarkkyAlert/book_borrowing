<?php
/**
 * Members Management - จัดการสมาชิก
 */

require_once __DIR__ . '/../bootstrap.php';
requireStaff();

use App\Services\MemberService;

$pdo = getDB();
$memberService = new MemberService($pdo);

// Get parameters
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'newest';

// Get members via Service
$members = $memberService->getMembers([
    'search' => $search,
    'status' => $status,
    'sort' => $sort
]);

$pageTitle = 'จัดการสมาชิก';
require_once __DIR__ . '/header.php';
?>

<!-- Actions Bar -->
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
    <div>
        <h3 class="text-lg font-bold text-gray-800">จัดการสมาชิก</h3>
        <p class="text-sm text-gray-500">ทั้งหมด <?= count($members) ?> คน</p>
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
        <div class="md:col-span-3">
            <label class="block text-xs font-medium text-gray-700 mb-1">สถานะ</label>
            <select class="w-full border-gray-300 rounded-lg text-sm focus:ring-primary-500 focus:border-primary-500" name="status">
                <option value="">ทั้งหมด</option>
                <option value="has_borrow" <?= $status === 'has_borrow' ? 'selected' : '' ?>>กำลังยืมหนังสือ</option>
                <option value="no_borrow" <?= $status === 'no_borrow' ? 'selected' : '' ?>>ปกติ (ไม่ได้ยืม)</option>
            </select>
        </div>
        <div class="md:col-span-3">
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
                <h4 class="text-lg font-medium text-gray-600">ไม่พบสมาชิก</h4>
                <p class="text-sm">ลองปรับเปลี่ยนคำค้นหา</p>
            </div>
        <?php else: ?>
            <table class="w-full text-sm text-left">
                <thead class="text-xs text-gray-500 uppercase bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="px-6 py-4 font-medium" width="50">#</th>
                        <th class="px-6 py-4 font-medium">ชื่อ-นามสกุล</th>
                        <th class="px-6 py-4 font-medium">อีเมล</th>
                        <th class="px-6 py-4 font-medium">เบอร์โทร</th>
                        <th class="px-6 py-4 font-medium text-center">กำลังยืม</th>
                        <th class="px-6 py-4 font-medium text-center">ยืมทั้งหมด</th>
                        <th class="px-6 py-4 font-medium">สมัครเมื่อ</th>
                        <th class="px-6 py-4 font-medium text-end" width="100">การจัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($members as $index => $member): ?>
                        <tr class="hover:bg-gray-50/50 transition-colors">
                            <td class="px-6 py-4 text-gray-500"><?= $index + 1 ?></td>
                            <td class="px-6 py-4">
                                <div class="flex items-center">
                                    <div class="h-8 w-8 rounded-full bg-primary-100 text-primary-600 flex items-center justify-center mr-3 font-bold text-xs">
                                        <?= mb_substr($member['name'], 0, 1) ?>
                                    </div>
                                    <div class="font-medium text-gray-900"><?= e($member['name']) ?></div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-gray-600"><?= e($member['email']) ?></td>
                            <td class="px-6 py-4 text-gray-600 font-mono text-xs"><?= e($member['phone'] ?: '-') ?></td>
                            <td class="px-6 py-4 text-center">
                                <?php if ($member['active_borrows'] > 0): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                        <?= $member['active_borrows'] ?> เล่ม
                                    </span>
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
                                    <a href="member_form.php?id=<?= $member['id'] ?>" class="p-1.5 text-amber-500 hover:text-amber-700 hover:bg-amber-50 rounded-lg transition-colors" title="แก้ไข">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

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
                    <td class="px-6 py-3 font-medium text-gray-900 line-clamp-1 max-w-[200px]">${item.book_title}</td>
                    <td class="px-6 py-3 text-gray-500">${item.borrow_date || '-'}</td>
                    <td class="px-6 py-3 text-gray-500">${item.due_date || '-'}</td>
                    <td class="px-6 py-3 text-gray-500">${item.return_date || '-'}</td>
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
