<?php
/**
 * Admin: Export Report as PDF (Print-friendly HTML)
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้สร้าง print-friendly HTML แล้วใช้ window.print() แปลงเป็น PDF
 * - ไม่ต้องติดตั้ง PDF library — ใช้ browser print dialog
 * - สิทธิ์: admin เท่านั้น
 * 
 * 📂 Flow:
 * GET ?report=TYPE&start_date=X&end_date=Y → report_helper.php → render HTML table → window.print()
 * 
 * ⚠️ ระวัง:
 * - เพิ่ม report type ใหม่ที่ includes/report_helper.php (shared กับ reports.php)
 */

require_once __DIR__ . '/../bootstrap.php';

requireAdmin();

use App\Repositories\ReportRepository;

$reportRepo = new ReportRepository(getDB());
$reportType = $_GET['report'] ?? 'books';

// Date range filter (validate format like reports.php)
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !strtotime($startDate)) {
    $startDate = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) || !strtotime($endDate)) {
    $endDate = date('Y-m-d');
}
// Prepare Data via Helper (Single Source of Truth - shared with reports.php)
require_once __DIR__ . '/../includes/report_helper.php';
$reportConfig = getReportConfig($reportType, $startDate, $endDate, $reportRepo, true);
$data = $reportConfig['data'];
$headers = $reportConfig['headers'];
$reportTitle = $reportConfig['title'];
$filename = $reportConfig['filename'];

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
