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

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/../bootstrap.php';
// 🔒 [AUTH] admin เท่านั้น
requireAdmin();

use App\Repositories\ReportRepository;

// 📦 สร้าง repository instance + รับประเภทรายงาน
$reportRepo = new ReportRepository(getDB());
$reportType = $_GET['report'] ?? 'books';

// ── Date Range Validation (เหมือน reports.php) ──
// 📅 ตรวจ format YYYY-MM-DD + fallback ถ้าไม่ถูกต้อง
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !strtotime($startDate)) {
    $startDate = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) || !strtotime($endDate)) {
    $endDate = date('Y-m-d');
}
// 📦 ดึงข้อมูลผ่าน report_helper.php (Single Source of Truth — shared กับ reports.php)
//    เพิ่ม report type ใหม่ที่ report_helper.php เท่านั้น
require_once __DIR__ . '/../includes/report_helper.php';
$onlyUncalled = ($_GET['uncalled'] ?? '') === '1';
$reportConfig = getReportConfig($reportType, $startDate, $endDate, $reportRepo, true, $onlyUncalled);
$data = $reportConfig['data'];         // array ข้อมูลรายงาน
$headers = $reportConfig['headers'];   // หัวคอลัมน์
$reportTitle = $reportConfig['title']; // ชื่อรายงาน (แสดงบนหัวกระดาษ)
$filename = $reportConfig['filename']; // ชื่อไฟล์

// 🏢 ชื่อหน่วยงานจาก settings — แสดงบนหัวกระดาษ PDF
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
            /* 🔴 [UAT รอบ 4] ชื่อหนังสือ/ชื่อคนภาษาไทยไม่มีช่องว่างคั่นคำ
                  เบราว์เซอร์จึงมองทั้งชื่อเป็น "คำเดียว" ที่ตัดบรรทัดไม่ได้
                  ตาราง table-layout:auto เลยถูกดันกว้างตามชื่อที่ยาวที่สุด
                  วัดจริง: ใบตามหนังสือค้างส่ง 193.6mm · ใบค้างชำระ 194.2mm
                  แต่ A4 หัก margin 15mm สองข้างแล้วพิมพ์ได้แค่ 180mm
                  → คอลัมน์ขวาสุดโดนตัด ซึ่งคือ "โทรแล้วเมื่อ" กับ "ค่าปรับ"
                    อันเป็นช่องที่ใบนั้นมีไว้เพื่อมันพอดี
               🧠 ยอมให้ตัดกลางคำ — บนใบที่พิมพ์แล้ว การขึ้นบรรทัดกลางคำ
                  ยังอ่านออก แต่คอลัมน์ที่หายไปทั้งคอลัมน์อ่านไม่ได้เลย
                  (วิธีเดียวกับที่ใช้กับชื่อยาวบนบัตรสมาชิก) */
            overflow-wrap: anywhere;
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

            /* 🖨️ [PRINT] หัวตารางต้องซ้ำทุกหน้า
               🧠 รายงานส่วนใหญ่ยาวเกิน 1 หน้า (เช่นรายงานหนังสือยอดนิยม 29 แถว)
                  ถ้าไม่ใส่บรรทัดนี้ หน้า 2 เป็นต้นไปจะเป็นตัวเลขลอย ๆ ไม่มีหัวคอลัมน์
                  อ่านไม่รู้ว่าคอลัมน์ไหนคือจำนวนการยืม คอลัมน์ไหนคือกำลังถูกยืม */
            thead {
                display: table-header-group;
            }

            /* 🖨️ [PRINT] ห้ามตัดแถวคาบเกี่ยว 2 หน้า
               ชื่อหนังสือไทยยาว ๆ ทำให้แถวสูง ถ้าไม่กันไว้จะถูกผ่าครึ่งกลางบรรทัด */
            tr {
                break-inside: avoid;
                page-break-inside: avoid;   /* เบราว์เซอร์รุ่นเก่า */
            }

            /* 🖨️ [PRINT] ส่วนสรุปท้ายรายงานต้องไม่ถูกแยกจากกัน */
            .total-row td {
                background: #f1f5f9;
                border-top: 2px solid #94a3b8;
            }
            .summary {
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }

        /* 🖨️ [PRINT] กำหนดขนาดกระดาษและขอบให้แน่นอน
           🧠 ถ้าไม่กำหนด ขอบจะแล้วแต่ค่า default ของเบราว์เซอร์แต่ละตัว
              ทำให้พิมพ์จาก Chrome กับ Safari ได้หน้าตาไม่เหมือนกัน
           📌 A4 เพราะเป็นขนาดมาตรฐานที่ใช้ในไทย (ไม่ใช่ Letter) */
        @page {
            size: A4;
            margin: 15mm;
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
                                <td class="<?= in_array($key, REPORT_COUNT_COLUMNS, true) ? 'text-center' : '' ?><?= in_array($key, REPORT_MONEY_COLUMNS, true) ? 'text-right' : '' ?>">
                                    <?php
                                    // 🔴 [FIX] เดิมใช้ is_numeric($value) ตัดสินว่าเป็นตัวเลขไหม
                                    //    ทำให้เบอร์โทร "0891234567" ถูกแปลงเป็น "891,234,567"
                                    //    ตอนนี้ตัดสินจาก "ชื่อคอลัมน์" แทน (ดู includes/report_helper.php)
                                    ?>
                                    <?php // 📞 [UAT รอบ 4 ข้อ 4] บนใบที่พิมพ์ออกมา ช่องว่างยิ่งอ่านไม่ออก
                                          //    เพราะบรรณารักษ์กำลังไล่โทรทีละแถวอยู่ ต้องรู้ว่าข้ามได้เลย
                                          //    🧠 บอกที่ชั้นแสดงผลเท่านั้น — ไฟล์ Excel ยังต้องเป็นช่องว่างจริง
                                          //       ไม่งั้นลูกค้าเอาไปกรอง/นำเข้าต่อแล้วได้คำว่า "ไม่มีเบอร์" เป็นเบอร์
                                          $isEmptyPhone = in_array($key, REPORT_PHONE_COLUMNS, true)
                                              && ($value === null || $value === ''); ?>
                                    <?php if ($isEmptyPhone): ?>
                                        <span style="color:#999">— ไม่มีเบอร์ —</span>
                                    <?php else: ?>
                                        <?= e(formatReportValue($key, $value)) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php
                // 🔢 [F-50] แถวยอดรวมท้ายตาราง — ต้องมีในไฟล์ที่พิมพ์ออกไปด้วย
                //    ไม่ใช่แค่หน้าเว็บ ไม่งั้นบรรณารักษ์ที่ถือกระดาษไปประชุมก็ยังต้องบวกเอง
                $totals = reportColumnTotals($data);
                ?>
                <?php if (array_filter($totals, fn($t) => $t !== null)): ?>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="2"><strong>รวมทั้งหมด</strong></td>
                        <?php
                        $totalKeys = array_keys($totals);
                        array_shift($totalKeys);   // ช่องแรกถูก colspan=2 กลืนไปแล้ว
                        ?>
                        <?php foreach ($totalKeys as $key): ?>
                            <td class="<?= in_array($key, REPORT_COUNT_COLUMNS, true) ? 'text-center' : '' ?><?= in_array($key, REPORT_MONEY_COLUMNS, true) ? 'text-right' : '' ?>">
                                <strong><?= e(formatReportTotal($key, $totals[$key])) ?></strong>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tfoot>
                <?php endif; ?>
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
