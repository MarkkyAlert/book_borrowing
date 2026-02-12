<?php
/**
 * Admin: Advanced Reports - รายงานเชิงลึก
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้แสดงรายงาน 6 ประเภท: books, members, revenue, overdue, borrows, unpaid
 * - รองรับ CSV export (GET ?export=csv)
 * - สิทธิ์: admin เท่านั้น (requireAdmin)
 * 
 * 📂 Flow:
 * 1. รับ report type + date range จาก GET params
 * 2. ดึงข้อมูลผ่าน report_helper.php::getReportConfig() (shared กับ export_pdf.php)
 * 3. ถ้า ?export=csv → stream CSV download แล้ว exit
 * 4. ถ้าไม่ → แสดง HTML table
 * 
 * ⚠️ ระวัง:
 * - เพิ่ม report type ใหม่ที่ includes/report_helper.php (Single Source of Truth)
 * - อย่าเพิ่มที่นี่อย่างเดียว ไม่งั้น PDF export จะไม่มีข้อมูล
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/../bootstrap.php';
// 🔒 [AUTH] admin เท่านั้น — staff ดูรายงานไม่ได้
requireAdmin();

use App\Repositories\ReportRepository; // ดึงข้อมูลรายงานต่างๆ
use App\Repositories\BorrowRepository;  // ดึงข้อมูลการยืมสำหรับรายงาน

// 📦 สร้าง repository instances
$pdo = getDB();
$reportRepo = new ReportRepository($pdo);
$borrowRepo = new BorrowRepository($pdo);
// 📥 รับประเภทรายงาน + flag ว่าเป็น CSV export หรือไม่
$reportType = $_GET['report'] ?? 'books';
$isExport = isset($_GET['export']) && $_GET['export'] === 'csv';

// ── Date Range Filter + Validation ──
// 📅 ค่าเริ่มต้น: ต้นเดือนนี้, ค่าสิ้นสุด: วันนี้
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// [VALIDATION] ตรวจสอบ format วันที่ (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !strtotime($startDate)) {
    $startDate = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) || !strtotime($endDate)) {
    $endDate = date('Y-m-d');
}

// [VALIDATION] startDate ต้องไม่เกิน endDate
if ($startDate > $endDate) {
    $startDate = $endDate;
}

// [VALIDATION] endDate ต้องไม่เกินวันนี้
$today = date('Y-m-d');
if ($endDate > $today) {
    $endDate = $today;
}

// Detect active range for button highlighting
$activeRange = '';
$today = date('Y-m-d');
if ($startDate === $today && $endDate === $today) {
    $activeRange = 'today';
} elseif ($startDate === date('Y-m-d', strtotime('-7 days')) && $endDate === $today) {
    $activeRange = 'week';
} elseif ($startDate === date('Y-m-d', strtotime('-30 days')) && $endDate === $today) {
    $activeRange = 'month';
} elseif ($startDate === date('Y-m-d', strtotime('-1 year')) && $endDate === $today) {
    $activeRange = 'year';
}

// 📦 ดึงข้อมูลผ่าน report_helper.php (Single Source of Truth)
//    ใช้ร่วมกับ export_pdf.php — เพิ่ม report type ใหม่ที่ report_helper.php เท่านั้น
require_once __DIR__ . '/../includes/report_helper.php';
$reportConfig = getReportConfig($reportType, $startDate, $endDate, $reportRepo, false);
$data = $reportConfig['data'];       // array ข้อมูลรายงาน
$headers = $reportConfig['headers']; // หัวคอลัมน์ตาราง
$filename = $reportConfig['filename']; // ชื่อไฟล์สำหรับ export

// ── CSV Export: stream download แล้ว exit ──
if ($isExport) {
    // 📤 ตั้ง header ให้ browser download เป็นไฟล์ CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // 🧠 BOM (Byte Order Mark) — ทำให้ Excel รู้จัก UTF-8 (ไม่งั้นภาษาไทยจะเพี้ยน)
    fputs($output, "\xEF\xBB\xBF");
    
    fputcsv($output, $headers);  // เขียนหัวคอลัมน์
    foreach ($data as $row) {
        fputcsv($output, $row);  // เขียนข้อมูลทีละแถว
    }
    
    fclose($output);
    exit; // ⚠️ ต้อง exit — ห้ามให้ HTML ด้านล่างถูกต่อเข้าไปในไฟล์
}

$pageTitle = 'รายงานและสถิติ';
require_once __DIR__ . '/header.php';
?>

<div class="mb-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h3 class="text-2xl font-bold text-gray-800 flex items-center">
                <i class="bi bi-bar-chart-line mr-3 text-primary-600"></i>
                รายงานและสถิติ
            </h3>
            <p class="text-gray-500">วิเคราะห์ข้อมูลเพื่อการวางแผน</p>
        </div>
        <div class="flex gap-2">
            <a href="reports.php?report=<?= $reportType ?>&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>&export=csv" class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-xl transition-colors shadow-sm">
                <i class="bi bi-file-earmark-spreadsheet mr-2"></i>
                CSV
            </a>
            <a href="export_pdf.php?report=<?= $reportType ?>&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>" target="_blank" class="inline-flex items-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-xl transition-colors shadow-sm">
                <i class="bi bi-file-earmark-pdf mr-2"></i>
                PDF
            </a>
        </div>
    </div>
</div>

<!-- Flatpickr CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

<!-- Date Range Filter -->
<?php 
$startDateDisplay = date('d/m/Y', strtotime($startDate));
$endDateDisplay = date('d/m/Y', strtotime($endDate));
?>
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-6">
    <form method="GET" class="flex flex-wrap items-end gap-4" id="dateFilterForm">
        <input type="hidden" name="report" value="<?= $reportType ?>">
        <input type="hidden" name="start_date" id="start_date_hidden" value="<?= $startDate ?>">
        <input type="hidden" name="end_date" id="end_date_hidden" value="<?= $endDate ?>">
        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">วันที่เริ่มต้น</label>
            <input type="text" id="start_date_picker" value="<?= $startDateDisplay ?>" 
                   class="border-gray-300 rounded-lg text-sm focus:ring-primary-500 focus:border-primary-500 w-32 cursor-pointer" readonly>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">วันที่สิ้นสุด</label>
            <input type="text" id="end_date_picker" value="<?= $endDateDisplay ?>" 
                   class="border-gray-300 rounded-lg text-sm focus:ring-primary-500 focus:border-primary-500 w-32 cursor-pointer" readonly>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="inline-flex items-center px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-lg transition-colors">
                <i class="bi bi-funnel mr-1"></i>กรอง
            </button>
            <a href="reports.php?report=<?= $reportType ?>&start_date=<?= date('Y-m-01') ?>&end_date=<?= date('Y-m-d') ?>" class="inline-flex items-center px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition-colors">
                <i class="bi bi-arrow-counterclockwise mr-1"></i>รีเซ็ต
            </a>
        </div>
        <div class="flex gap-2 ml-auto">
            <button type="submit" onclick="setDateRange('today')" class="px-3 py-2 text-xs rounded-lg <?= $activeRange === 'today' ? 'bg-primary-600 text-white' : 'bg-gray-100 hover:bg-gray-200' ?>">วันนี้</button>
            <button type="submit" onclick="setDateRange('week')" class="px-3 py-2 text-xs rounded-lg <?= $activeRange === 'week' ? 'bg-primary-600 text-white' : 'bg-gray-100 hover:bg-gray-200' ?>">7 วัน</button>
            <button type="submit" onclick="setDateRange('month')" class="px-3 py-2 text-xs rounded-lg <?= $activeRange === 'month' ? 'bg-primary-600 text-white' : 'bg-gray-100 hover:bg-gray-200' ?>">30 วัน</button>
            <button type="submit" onclick="setDateRange('year')" class="px-3 py-2 text-xs rounded-lg <?= $activeRange === 'year' ? 'bg-primary-600 text-white' : 'bg-gray-100 hover:bg-gray-200' ?>">1 ปี</button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/th.js"></script>
<script>
// Helper functions - ต้องประกาศก่อนใช้งาน
function formatDateISO(date) {
    // ใช้ local date แทน toISOString() เพื่อป้องกัน timezone shift
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
}

// Initialize Flatpickr with Thai date format
const fpConfig = {
    dateFormat: 'd/m/Y',
    locale: 'th',
    allowInput: false,
    maxDate: 'today'  // ไม่อนุญาตเลือกวันที่เกินวันนี้
};

const startPicker = flatpickr('#start_date_picker', {
    ...fpConfig,
    defaultDate: '<?= $startDate ?>',
    onChange: function(selectedDates, dateStr) {
        document.getElementById('start_date_hidden').value = formatDateISO(selectedDates[0]);
    }
});

const endPicker = flatpickr('#end_date_picker', {
    ...fpConfig,
    defaultDate: '<?= $endDate ?>',
    onChange: function(selectedDates, dateStr) {
        document.getElementById('end_date_hidden').value = formatDateISO(selectedDates[0]);
    }
});

function setDateRange(range) {
    const today = new Date();
    let startDate = new Date();
    
    switch(range) {
        case 'today':
            startDate = new Date(today);
            break;
        case 'week':
            startDate = new Date(today);
            startDate.setDate(today.getDate() - 7);
            break;
        case 'month':
            startDate = new Date(today);
            startDate.setDate(today.getDate() - 30);
            break;
        case 'year':
            startDate = new Date(today);
            startDate.setFullYear(today.getFullYear() - 1);
            break;
    }
    
    // Update Flatpickr instances
    startPicker.setDate(startDate);
    endPicker.setDate(today);
    
    // Update hidden fields
    document.getElementById('start_date_hidden').value = formatDateISO(startDate);
    document.getElementById('end_date_hidden').value = formatDateISO(today);
}
</script>

<!-- Report Navigation -->
<div class="border-b border-gray-200 mb-6">
    <nav class="-mb-px flex space-x-8 overflow-x-auto" aria-label="Tabs">
        <a href="reports.php?report=books" class="<?= $reportType === 'books' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center">
            <i class="bi bi-book mr-2"></i>หนังสือยอดนิยม
        </a>
        <a href="reports.php?report=members" class="<?= $reportType === 'members' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center">
            <i class="bi bi-people mr-2"></i>นักอ่านตัวยง
        </a>
        <a href="reports.php?report=revenue" class="<?= $reportType === 'revenue' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center">
            <i class="bi bi-cash-coin mr-2"></i>สรุปรายได้
        </a>
        <a href="reports.php?report=overdue" class="<?= $reportType === 'overdue' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center">
            <i class="bi bi-exclamation-triangle mr-2"></i>หนังสือค้างส่ง
        </a>
        <a href="reports.php?report=unpaid" class="<?= $reportType === 'unpaid' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center">
            <i class="bi bi-cash-coin mr-2 text-red-500"></i>สมาชิกค้างชำระ
        </a>
    </nav>
</div>

<!-- Report Content -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <?php if (empty($data)): ?>
        <div class="text-center py-12">
            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="bi bi-inbox text-3xl text-gray-400"></i>
            </div>
            <p class="text-gray-500 text-lg">ไม่มีข้อมูลสำหรับรายงานช่วงเวลานี้</p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-10">#</th>
                        <?php foreach ($headers as $index => $header): ?>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <?= $header ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($data as $index => $row): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= $index + 1 ?>
                            </td>
                            <?php foreach ($row as $key => $value): ?>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php if ($key === 'active_loans' || $key === 'currently_borrowed' || $key === 'borrow_count'): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                            <?= number_format($value) ?>
                                        </span>
                                    <?php elseif ($key === 'total_amount'): ?>
                                        <span class="text-green-600 font-bold"><?= number_format($value, 2) ?> ฿</span>
                                    <?php elseif ($key === 'role'): ?>
                                        <?= $value === 'staff' ? 'เจ้าหน้าที่' : 'สมาชิก' ?>
                                    <?php elseif ($key === 'payment_day'): ?>
                                        <?= formatDate($value) ?>
                                    <?php else: ?>
                                        <?= e($value) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
