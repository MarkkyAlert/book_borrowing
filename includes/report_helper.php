<?php
/**
 * Report Data Helper - Single Source of Truth สำหรับ report type mapping
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * รวม mapping ระหว่าง report type → {data, headers, filename, title}
 * ใช้ร่วมกันระหว่าง admin/reports.php (แสดงตาราง) และ admin/export_pdf.php (PDF)
 *
 * 🏗️ สถาปัตยกรรม:
 * admin/reports.php → require report_helper.php → getReportConfig()
 * admin/export_pdf.php → require report_helper.php → getReportConfig(forPdf: true)
 *
 * 🧠 เหตุผล:
 * Single Source of Truth — เพิ่ม report type ใหม่ที่นี่ที่เดียว
 * ไม่ต้องแก้หลายที่ (ป้องกัน header ไม่ตรงกัน)
 *
 * ⚠️ ห้ามแก้:
 * - headers array ต้องตรงกับจำนวนคอลัมน์ของ data
 * - เพิ่ม case ใหม่ต้องเพิ่มทั้ง ReportRepository method + case ที่นี่
 */

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ดึงข้อมูล + config ของ report ตาม type
 * ==========================================================================
 *
 * 📥 Input:
 *   @param string $type   report type: books|members|revenue|overdue|borrows|unpaid
 *   @param string $start  start date (Y-m-d)
 *   @param string $end    end date (Y-m-d)
 *   @param object $repo   ReportRepository instance
 *   @param bool   $forPdf true = PDF export (ปรับ header บางตัว)
 *
 * 📤 Output: @return array {data, headers, filename, title}
 *
 * 🔄 Flow: switch($type) → เรียก ReportRepository method ที่ตรงกับ → คืน config array
 *
 * ✅ Use case:
 *   $config = getReportConfig('books', '2024-01-01', '2024-12-31', $repo);
 *   // { data: [...], headers: [...], filename: 'top_books_2024-01-15', title: '...' }
 */
function getReportConfig(string $type, string $start, string $end, $repo, bool $forPdf = false): array
{
    // 📝 สร้างข้อความช่วงวันที่สำหรับใส่ใน title
    $dateRangeText = formatDate($start) . ' - ' . formatDate($end);
    
    // 📝 switch ตาม type → เรียก ReportRepository method ที่ตรงกัน
    //    เพิ่ม report ใหม่ → เพิ่ม case + method ใน ReportRepository
    switch ($type) {
        case 'books':
            // 📝 หนังสือยอดนิยม (top 50)
            return [
                'data' => $repo->getTopBooksReport(50, $start, $end),
                'headers' => ['ชื่อหนังสือ', 'หมวดหมู่', 'จำนวนการยืม' . ($forPdf ? '' : ' (ครั้ง)'), 'กำลังถูกยืม' . ($forPdf ? '' : ' (เล่ม)')],
                'filename' => "top_books_" . date('Y-m-d'),
                'title' => 'รายงานหนังสือยอดนิยม (' . $dateRangeText . ')',
            ];

        case 'members':
            // 📝 สมาชิกใช้บริการบ่อย (top 50)
            //    forPdf = true → ปรับ format สำหรับ PDF
            return [
                'data' => $repo->getTopMembersReport(50, $forPdf, $start, $end),
                'headers' => ['ชื่อสมาชิก', 'อีเมล', 'สถานะ', 'ประวัติการยืม' . ($forPdf ? '' : ' (เล่ม)'), 'กำลังยืมอยู่' . ($forPdf ? '' : ' (เล่ม)')],
                'filename' => "top_members_" . date('Y-m-d'),
                'title' => 'รายงานสมาชิกที่ใช้บริการบ่อย (' . $dateRangeText . ')',
            ];

        case 'revenue':
            // 📝 สรุปรายได้ค่าปรับรายวัน
            return [
                'data' => $repo->getDailyRevenueReport($start, $end),
                'headers' => ['วันที่', 'จำนวนรายการ', 'ยอดรวม (บาท)'],
                'filename' => "daily_revenue_" . date('Y-m-d'),
                'title' => 'รายงานสรุปรายได้ค่าปรับ (' . $dateRangeText . ')',
            ];

        case 'overdue':
            // 📝 หนังสือค้างส่ง (ไม่ใช้ date range เพราะดูแค่ "ตอนนี้")
            return [
                'data' => $repo->getOverdueReport($forPdf),
                'headers' => ['ชื่อผู้ยืม', 'เบอร์โทร', 'หนังสือ', 'วันที่ยืม', 'กำหนดคืน', 'เกินกำหนด (วัน)'],
                'filename' => "overdue_books_" . date('Y-m-d'),
                'title' => 'รายงานหนังสือค้างส่ง',
            ];

        case 'borrows':
            // 📝 รายการยืม-คืนทั้งหมดตามช่วงวัน
            return [
                'data' => $repo->getBorrowsReport($start, $end),
                'headers' => ['ผู้ยืม', 'หนังสือ', 'วันที่ยืม', 'กำหนดคืน', 'สถานะ', 'ค่าปรับ'],
                'filename' => "borrows_" . date('Y-m-d'),
                'title' => 'รายงานการยืม-คืน (' . $dateRangeText . ')',
            ];

        case 'unpaid':
            // 📝 สมาชิกค้างชำระ
            return [
                'data' => $repo->getUnpaidFinesReport($start, $end),
                'headers' => ['ชื่อสมาชิก', 'เบอร์โทร', 'หนังสือ', 'คืนเมื่อ', 'ค่าปรับ (บาท)'],
                'filename' => "unpaid_fines_" . date('Y-m-d'),
                'title' => 'รายงานสมาชิกค้างชำระ (' . $dateRangeText . ')',
            ];

        default:
            // 📝 Fallback: type ไม่รู้จัก → คืนว่าง (ไม่พัง)
            return [
                'data' => [],
                'headers' => [],
                'filename' => "report_" . date('Y-m-d'),
                'title' => 'รายงาน',
            ];
    }
}
