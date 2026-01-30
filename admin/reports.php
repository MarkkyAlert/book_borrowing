<?php
/**
 * Admin: Advanced Reports
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireAdmin(); // Reports are for Admin only

$pdo = getDB();
$reportType = $_GET['report'] ?? 'books'; // books, members, revenue
$isExport = isset($_GET['export']) && $_GET['export'] === 'csv';

// Prepare Data based on Report Type
$data = [];
$headers = [];
$filename = "report_" . date('Y-m-d');

if ($reportType === 'books') {
    // Top Borrowed Books
    $sql = "
        SELECT b.title, c.name as category, COUNT(br.id) as borrow_count,
               (b.quantity - b.available) as currently_borrowed
        FROM books b
        LEFT JOIN categories c ON b.category_id = c.id
        LEFT JOIN borrows br ON b.id = br.book_id
        GROUP BY b.id
        ORDER BY borrow_count DESC
        LIMIT 50
    ";
    $headers = ['ชื่อหนังสือ', 'หมวดหมู่', 'จำนวนการยืม (ครั้ง)', 'กำลังถูกยืม (เล่ม)'];
    $filename = "top_books_" . date('Y-m-d');
    
} elseif ($reportType === 'members') {
    // Top Active Members
    $sql = "
        SELECT u.name, u.email, u.role, COUNT(br.id) as borrow_count,
               SUM(CASE WHEN br.status = 'borrowing' THEN 1 ELSE 0 END) as active_loans
        FROM users u
        JOIN borrows br ON u.id = br.user_id
        WHERE u.role != 'admin'
        GROUP BY u.id
        ORDER BY borrow_count DESC
        LIMIT 50
    ";
    $headers = ['ชื่อสมาชิก', 'อีเมล', 'สถานะ', 'ประวัติการยืม (เล่ม)', 'กำลังยืมอยู่ (เล่ม)'];
    $filename = "top_members_" . date('Y-m-d');

} elseif ($reportType === 'revenue') {
    // Daily Revenue
    $sql = "
        SELECT DATE(payment_date) as payment_day, COUNT(id) as transaction_count, SUM(amount) as total_amount
        FROM payments
        GROUP BY DATE(payment_date)
        ORDER BY payment_day DESC
        LIMIT 30
    ";
    $headers = ['วันที่', 'จำนวนรายการ', 'ยอดรวม (บาท)'];
    $filename = "daily_revenue_" . date('Y-m-d');

} elseif ($reportType === 'overdue') {
    // Overdue Books
    $sql = "
        SELECT u.name, u.phone, bk.title, b.borrow_date, b.due_date,
               DATEDIFF(CURDATE(), b.due_date) as days_overdue
        FROM borrows b
        JOIN users u ON b.user_id = u.id
        JOIN books bk ON b.book_id = bk.id
        WHERE b.status = 'borrowing' AND b.due_date < CURDATE()
        ORDER BY b.due_date ASC
    ";
    $headers = ['ชื่อผู้ยืม', 'เบอร์โทร', 'หนังสือ', 'วันที่ยืม', 'กำหนดคืน', 'เกินกำหนด (วัน)'];
    $filename = "overdue_books_" . date('Y-m-d');
}

if (isset($sql)) {
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle Export
if ($isExport) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for Excel UTF-8 compatibility
    fputs($output, "\xEF\xBB\xBF");
    
    // Write Headers
    fputcsv($output, $headers);
    
    // Write Data
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
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
            <a href="reports.php?report=<?= $reportType ?>&export=csv" class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-xl transition-colors shadow-sm">
                <i class="bi bi-file-earmark-spreadsheet mr-2"></i>
                CSV
            </a>
            <a href="export_pdf.php?report=<?= $reportType ?>" target="_blank" class="inline-flex items-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-xl transition-colors shadow-sm">
                <i class="bi bi-file-earmark-pdf mr-2"></i>
                PDF
            </a>
        </div>
    </div>
</div>

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
