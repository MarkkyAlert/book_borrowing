<?php
/**
 * Report Data Helper - Single Source of Truth สำหรับ report type mapping
 * 
 * ใช้ร่วมกันระหว่าง admin/reports.php และ admin/export_pdf.php
 * เพิ่ม report type ใหม่ที่ไฟล์นี้ที่เดียว
 * 
 * @package Includes
 */

/**
 * ดึงข้อมูล report ตาม type
 * 
 * @param string $type    report type: books, members, revenue, overdue, borrows, unpaid
 * @param string $start   start date (Y-m-d)
 * @param string $end     end date (Y-m-d)
 * @param object $repo    ReportRepository instance
 * @param bool   $forPdf  true = เรียกจาก PDF export (อาจมี param ต่างกัน)
 * @return array { data: array, headers: array[], filename: string, title?: string }
 */
function getReportConfig(string $type, string $start, string $end, $repo, bool $forPdf = false): array
{
    $dateRangeText = formatDate($start) . ' - ' . formatDate($end);
    
    switch ($type) {
        case 'books':
            return [
                'data' => $repo->getTopBooksReport(50, $start, $end),
                'headers' => ['ชื่อหนังสือ', 'หมวดหมู่', 'จำนวนการยืม' . ($forPdf ? '' : ' (ครั้ง)'), 'กำลังถูกยืม' . ($forPdf ? '' : ' (เล่ม)')],
                'filename' => "top_books_" . date('Y-m-d'),
                'title' => 'รายงานหนังสือยอดนิยม (' . $dateRangeText . ')',
            ];

        case 'members':
            return [
                'data' => $repo->getTopMembersReport(50, $forPdf, $start, $end),
                'headers' => ['ชื่อสมาชิก', 'อีเมล', 'สถานะ', 'ประวัติการยืม' . ($forPdf ? '' : ' (เล่ม)'), 'กำลังยืมอยู่' . ($forPdf ? '' : ' (เล่ม)')],
                'filename' => "top_members_" . date('Y-m-d'),
                'title' => 'รายงานสมาชิกที่ใช้บริการบ่อย (' . $dateRangeText . ')',
            ];

        case 'revenue':
            return [
                'data' => $repo->getDailyRevenueReport($start, $end),
                'headers' => ['วันที่', 'จำนวนรายการ', 'ยอดรวม (บาท)'],
                'filename' => "daily_revenue_" . date('Y-m-d'),
                'title' => 'รายงานสรุปรายได้ค่าปรับ (' . $dateRangeText . ')',
            ];

        case 'overdue':
            return [
                'data' => $repo->getOverdueReport($forPdf),
                'headers' => ['ชื่อผู้ยืม', 'เบอร์โทร', 'หนังสือ', 'วันที่ยืม', 'กำหนดคืน', 'เกินกำหนด (วัน)'],
                'filename' => "overdue_books_" . date('Y-m-d'),
                'title' => 'รายงานหนังสือค้างส่ง',
            ];

        case 'borrows':
            return [
                'data' => $repo->getBorrowsReport($start, $end),
                'headers' => ['ผู้ยืม', 'หนังสือ', 'วันที่ยืม', 'กำหนดคืน', 'สถานะ', 'ค่าปรับ'],
                'filename' => "borrows_" . date('Y-m-d'),
                'title' => 'รายงานการยืม-คืน (' . $dateRangeText . ')',
            ];

        case 'unpaid':
            return [
                'data' => $repo->getUnpaidFinesReport($start, $end),
                'headers' => ['ชื่อสมาชิก', 'เบอร์โทร', 'หนังสือ', 'คืนเมื่อ', 'ค่าปรับ (บาท)'],
                'filename' => "unpaid_fines_" . date('Y-m-d'),
                'title' => 'รายงานสมาชิกค้างชำระ (' . $dateRangeText . ')',
            ];

        default:
            return [
                'data' => [],
                'headers' => [],
                'filename' => "report_" . date('Y-m-d'),
                'title' => 'รายงาน',
            ];
    }
}
