<?php
/**
 * Admin: Export Report as PDF (Print-friendly HTML)
 * ใช้ window.print() เพื่อแปลงเป็น PDF โดยไม่ต้องติดตั้ง library
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireAdmin();

$pdo = getDB();
$reportType = $_GET['report'] ?? 'books';

// Prepare Data
$data = [];
$headers = [];
$reportTitle = '';
$filename = "report_" . date('Y-m-d');

if ($reportType === 'books') {
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
    $headers = ['ชื่อหนังสือ', 'หมวดหมู่', 'จำนวนการยืม', 'กำลังถูกยืม'];
    $reportTitle = 'รายงานหนังสือยอดนิยม';
    $filename = "top_books_" . date('Y-m-d');
    
} elseif ($reportType === 'members') {
    $sql = "
        SELECT u.name, u.email, 
               CASE u.role WHEN 'staff' THEN 'เจ้าหน้าที่' ELSE 'สมาชิก' END as role_name,
               COUNT(br.id) as borrow_count,
               SUM(CASE WHEN br.status = 'borrowing' THEN 1 ELSE 0 END) as active_loans
        FROM users u
        JOIN borrows br ON u.id = br.user_id
        WHERE u.role != 'admin'
        GROUP BY u.id
        ORDER BY borrow_count DESC
        LIMIT 50
    ";
    $headers = ['ชื่อสมาชิก', 'อีเมล', 'สถานะ', 'ประวัติการยืม', 'กำลังยืมอยู่'];
    $reportTitle = 'รายงานสมาชิกที่ใช้บริการบ่อย';
    $filename = "top_members_" . date('Y-m-d');

} elseif ($reportType === 'revenue') {
    $sql = "
        SELECT DATE_FORMAT(payment_date, '%d/%m/%Y') as payment_day, 
               COUNT(id) as transaction_count, 
               SUM(amount) as total_amount
        FROM payments
        GROUP BY DATE(payment_date)
        ORDER BY payment_date DESC
        LIMIT 30
    ";
    $headers = ['วันที่', 'จำนวนรายการ', 'ยอดรวม (บาท)'];
    $reportTitle = 'รายงานสรุปรายได้ค่าปรับ';
    $filename = "daily_revenue_" . date('Y-m-d');

} elseif ($reportType === 'overdue') {
    $sql = "
        SELECT u.name, u.phone, bk.title, 
               DATE_FORMAT(b.borrow_date, '%d/%m/%Y') as borrow_date,
               DATE_FORMAT(b.due_date, '%d/%m/%Y') as due_date,
               DATEDIFF(CURDATE(), b.due_date) as days_overdue
        FROM borrows b
        JOIN users u ON b.user_id = u.id
        JOIN books bk ON b.book_id = bk.id
        WHERE b.status = 'borrowing' AND b.due_date < CURDATE()
        ORDER BY b.due_date ASC
    ";
    $headers = ['ชื่อผู้ยืม', 'เบอร์โทร', 'หนังสือ', 'วันที่ยืม', 'กำหนดคืน', 'เกินกำหนด (วัน)'];
    $reportTitle = 'รายงานหนังสือค้างส่ง';
    $filename = "overdue_" . date('Y-m-d');

} elseif ($reportType === 'borrows') {
    $dateFrom = $_GET['from'] ?? date('Y-m-01');
    $dateTo = $_GET['to'] ?? date('Y-m-d');
    
    $sql = "
        SELECT u.name, bk.title, 
               DATE_FORMAT(b.borrow_date, '%d/%m/%Y') as borrow_date,
               DATE_FORMAT(b.due_date, '%d/%m/%Y') as due_date,
               CASE b.status WHEN 'returned' THEN 'คืนแล้ว' ELSE 'กำลังยืม' END as status_text,
               COALESCE(b.fine_amount, 0) as fine
        FROM borrows b
        JOIN users u ON b.user_id = u.id
        JOIN books bk ON b.book_id = bk.id
        WHERE b.borrow_date BETWEEN ? AND ?
        ORDER BY b.borrow_date DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$dateFrom, $dateTo]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $headers = ['ผู้ยืม', 'หนังสือ', 'วันที่ยืม', 'กำหนดคืน', 'สถานะ', 'ค่าปรับ'];
    $reportTitle = 'รายงานการยืม-คืน (' . formatDate($dateFrom) . ' - ' . formatDate($dateTo) . ')';
    $filename = "borrows_" . date('Y-m-d');
}

// Execute query if not already done
if (empty($data) && isset($sql)) {
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$orgName = getSetting('org_name', 'ระบบห้องสมุด');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($reportTitle) ?> - <?= e($orgName) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Sarabun', 'Segoe UI', Tahoma, sans-serif;
            font-size: 12pt;
            line-height: 1.4;
            color: #333;
            background: #fff;
        }
        .container {
            max-width: 210mm;
            margin: 0 auto;
            padding: 15mm;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #333;
        }
        .header h1 {
            font-size: 18pt;
            margin-bottom: 5px;
        }
        .header .org-name {
            font-size: 14pt;
            color: #666;
        }
        .header .date {
            font-size: 10pt;
            color: #888;
            margin-top: 5px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px 10px;
            text-align: left;
        }
        th {
            background: #f5f5f5;
            font-weight: bold;
            font-size: 11pt;
        }
        tr:nth-child(even) {
            background: #fafafa;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
            font-size: 10pt;
            color: #888;
            display: flex;
            justify-content: space-between;
        }
        .no-print {
            position: fixed;
            top: 10px;
            right: 10px;
            z-index: 1000;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            margin: 5px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
        }
        .btn-primary {
            background: #4f46e5;
            color: white;
        }
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        .summary {
            margin-top: 20px;
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
        }
        .summary p {
            margin: 5px 0;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
            .container {
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn btn-primary" onclick="window.print()">
            🖨️ พิมพ์ / บันทึก PDF
        </button>
        <a href="reports.php?report=<?= e($reportType) ?>" class="btn btn-secondary">
            ← กลับ
        </a>
    </div>

    <div class="container">
        <div class="header">
            <div class="org-name"><?= e($orgName) ?></div>
            <h1><?= e($reportTitle) ?></h1>
            <div class="date">พิมพ์เมื่อ: <?= date('d/m/Y H:i') ?> น.</div>
        </div>

        <?php if (empty($data)): ?>
            <p style="text-align: center; padding: 40px; color: #888;">ไม่มีข้อมูล</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th class="text-center" style="width: 40px;">#</th>
                        <?php foreach ($headers as $header): ?>
                            <th><?= e($header) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $index => $row): ?>
                        <tr>
                            <td class="text-center"><?= $index + 1 ?></td>
                            <?php foreach ($row as $key => $value): ?>
                                <td class="<?= in_array($key, ['borrow_count', 'active_loans', 'currently_borrowed', 'transaction_count', 'days_overdue', 'fine']) ? 'text-center' : '' ?><?= in_array($key, ['total_amount']) ? 'text-right' : '' ?>">
                                    <?php if ($key === 'total_amount' || $key === 'fine'): ?>
                                        <?= number_format($value, 2) ?>
                                    <?php elseif (is_numeric($value)): ?>
                                        <?= number_format($value) ?>
                                    <?php else: ?>
                                        <?= e($value) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="summary">
                <p><strong>จำนวนรายการทั้งหมด:</strong> <?= number_format(count($data)) ?> รายการ</p>
                <?php if ($reportType === 'revenue'): ?>
                    <?php $totalRevenue = array_sum(array_column($data, 'total_amount')); ?>
                    <p><strong>รายได้รวมทั้งหมด:</strong> <?= number_format($totalRevenue, 2) ?> บาท</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="footer">
            <span>ระบบห้องสมุดออนไลน์</span>
            <span>หน้า 1</span>
        </div>
    </div>

    <script>
        // Auto print on load (optional)
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>
