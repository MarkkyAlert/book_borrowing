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
                'data' => $repo->getTopMembersReport(50, $start, $end),
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
                'data' => $repo->getOverdueReport(),
                'headers' => ['ชื่อผู้ยืม', 'เบอร์โทร', 'หนังสือ', 'วันที่ยืม', 'กำหนดคืน', 'เกินกำหนด (วัน)'],
                'filename' => "overdue_books_" . date('Y-m-d'),
                'title' => 'รายงานหนังสือค้างส่ง',
            ];

        case 'due_soon':
            // 📝 ใบรายชื่อโทรตามก่อนครบกำหนด (ไม่ใช้ date range เพราะดูไปข้างหน้าจาก "วันนี้")
            //    🧠 คู่กับ 'overdue' — ตัวนั้นตามหลัง ตัวนี้ตามก่อน
            //    ระบบไม่ส่งอีเมล บรรณารักษ์จึงพิมพ์ใบนี้ออกมาแล้วโทรเอง
            return [
                'data' => $repo->getDueSoonReport(DUE_SOON_DAYS),
                'headers' => ['ชื่อผู้ยืม', 'เบอร์โทร', 'หนังสือ', 'วันที่ยืม', 'กำหนดคืน', 'เหลืออีก (วัน)'],
                'filename' => "due_soon_" . date('Y-m-d'),
                'title' => 'ใบรายชื่อโทรตาม — ครบกำหนดภายใน ' . DUE_SOON_DAYS . ' วัน',
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
                // 🔴 ต้องมี 6 คอลัมน์ให้ตรงกับที่ query คืนมา (ชื่อ · โทร · หนังสือ · วันที่ · status · เงิน)
                //    ROADMAP ข้อ 4 เติม b.status เข้าไปใน query แต่ลืมเติมหัวตาราง
                //    ผลคือ CSV 217 แถวมีคอลัมน์เกินหัว 1 ช่อง ทุกคอลัมน์ตั้งแต่ "ค่าปรับ" เลื่อนผิดตำแหน่ง
                //    และค่า enum ภาษาอังกฤษ (returned/lost/damaged) โผล่ในไฟล์ที่ลูกค้าเอาไปใช้
                // 🔴 [F-50] เพิ่ม "ค้างมา (วัน)" — เรียงตามยอดเงินอย่างเดียวไม่พอ
                //    ⚠️ ลำดับต้องตรงกับที่ query คืนมาเป๊ะ (ดู getUnpaidFinesReport)
                'headers' => ['ชื่อสมาชิก', 'เบอร์โทร', 'หนังสือ', 'คืนเมื่อ', 'ประเภท', 'ค้างมา (วัน)', 'ค่าปรับ (บาท)'],
                'filename' => "unpaid_fines_" . date('Y-m-d'),
                'title' => 'รายงานสมาชิกค้างชำระ (' . $dateRangeText . ')',
            ];

        case 'dormant':
            // 📝 [F-50] หนังสือที่ไม่มีใครยืมเลยในช่วงที่เลือก — ใช้ตัดสินใจจำหน่ายออก
            //    เป็นด้านตรงข้ามของรายงาน 'books' (ยอดนิยม) ซึ่งเดิมมีอยู่ด้านเดียว
            return [
                'data' => $repo->getDormantBooksReport($start, $end),
                'headers' => ['ชื่อหนังสือ', 'ผู้แต่ง', 'หมวดหมู่', 'จำนวน' . ($forPdf ? '' : ' (เล่ม)'), 'ยืมครั้งสุดท้าย'],
                'filename' => "dormant_books_" . date('Y-m-d'),
                'title' => 'รายงานหนังสือที่ไม่มีการยืม (' . $dateRangeText . ')',
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

// =====================================================
// 🔢 การจัดรูปแบบค่าในรายงาน
// =====================================================
/**
 * 🎯 คอลัมน์ที่เป็น "จำนวนนับ" — แสดงเป็นจำนวนเต็ม มีคอมมาคั่นหลัก
 * 🎯 คอลัมน์ที่เป็น "จำนวนเงิน" — แสดงทศนิยม 2 ตำแหน่ง
 *
 * 🔴 [สำคัญ] ห้ามใช้ is_numeric() ตัดสินว่าคอลัมน์ไหนเป็นตัวเลข!
 *    เบอร์โทรที่เก็บเป็น string เช่น "0891234567" ก็ผ่าน is_numeric()
 *    → ถูก number_format() แปลงเป็น "891,234,567" (เลข 0 นำหน้าหาย + มีคอมมา)
 *    รายงาน "หนังสือค้างส่ง" กับ "สมาชิกค้างชำระ" มีคอลัมน์เบอร์โทร
 *    ซึ่งเป็นรายงานที่เจ้าหน้าที่พิมพ์ไปโทรตามคนพอดี
 *
 * ⚙️ เพิ่มรายงานใหม่ที่มีคอลัมน์ตัวเลข → เพิ่มชื่อคอลัมน์ในลิสต์นี้
 */
const REPORT_COUNT_COLUMNS = ['borrow_count', 'currently_borrowed', 'active_loans', 'transaction_count', 'days_overdue', 'days_unpaid', 'quantity'];
const REPORT_MONEY_COLUMNS = ['total_amount', 'fine', 'fine_amount'];

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: จัดรูปแบบค่าในเซลล์รายงานตาม "ชื่อคอลัมน์" (ไม่ใช่ตามชนิดข้อมูล)
 * ==========================================================================
 *
 * 📥 Input: @param string $key ชื่อคอลัมน์, @param mixed $value ค่าจาก DB
 * 📤 Output: @return string ข้อความพร้อมแสดง (ยังไม่ escape — ผู้เรียกต้อง e() เอง)
 * ✅ Use case: admin/export_pdf.php, admin/reports.php
 */
function formatReportValue(string $key, mixed $value): string
{
    // 🧠 ค่า enum จาก DB เป็นภาษาอังกฤษ ห้ามปล่อยดิบ ๆ ลงรายงานที่ลูกค้าเอาไปใช้
    //    ในรายงานค้างชำระ status บอกว่าเงินก้อนนี้มาจากอะไร — คืนช้า หรือทำหาย
    //    ซึ่งเป็นข้อมูลที่คนตามหนี้ต้องรู้ (คุยกันคนละแบบ)
    if ($key === 'status') {
        return match ((string) $value) {
            'returned'  => 'ค่าปรับคืนช้า',
            'lost'      => 'ค่าชดใช้ (หาย)',
            'damaged'   => 'ค่าชดใช้ (ชำรุด)',
            'borrowing' => 'กำลังยืม',
            default     => (string) $value,
        };
    }

    if (in_array($key, REPORT_MONEY_COLUMNS, true)) {
        return number_format((float) $value, 2);
    }
    if (in_array($key, REPORT_COUNT_COLUMNS, true)) {
        return number_format((int) $value);
    }
    // 📝 ที่เหลือถือเป็นข้อความล้วน — เบอร์โทร/ISBN/ชื่อ/วันที่ ต้องไม่ถูกแปลงเป็นตัวเลข
    return (string) $value;
}

/**
 * 🎯 คอลัมน์ที่ Excel จะ "กินเลข 0 นำหน้า" ถ้าปล่อยเป็นตัวเลขเปล่า — F-44
 *
 * 🔴 [สำคัญ] ห้ามเดาจาก is_numeric() — เบอร์โทร "0891234567" ก็ผ่าน is_numeric()
 *    ต้องระบุตามชื่อคอลัมน์เท่านั้น (เหตุผลเดียวกับ REPORT_COUNT_COLUMNS)
 * ⚙️ เพิ่มรายงานใหม่ที่มีเบอร์โทร/รหัส/ISBN → เพิ่มชื่อคอลัมน์ในลิสต์นี้
 */
const REPORT_TEXT_CODE_COLUMNS = ['phone', 'user_phone', 'isbn', 'member_code', 'barcode'];

/**
 * 🎯 คอลัมน์ตัวเลขที่ **ห้ามรวมยอด** ถึงจะเป็นตัวเลขก็ตาม — F-50
 *
 * 🔴 เจอตอนทดสอบจริง: แถวรวมของรายงานค้างชำระขึ้นว่า "ค้างมา 11,660 วัน"
 *    ซึ่งคือผลบวกอายุหนี้ของทุกแถว — ไม่มีความหมายอะไรเลย
 *    (ไร้สาระแบบเดียวกับรวมเบอร์โทร แค่มองออกยากกว่า)
 *
 * 🧠 "จัดรูปแบบเป็นตัวเลข" กับ "รวมยอดได้" เป็นคนละเรื่องกัน
 *    days_overdue / days_unpaid = **อายุของแต่ละแถว** ไม่ใช่ปริมาณที่บวกกันได้
 *    ส่วน quantity / borrow_count = ปริมาณจริง บวกแล้วมีความหมาย
 *
 * ⚙️ เพิ่มคอลัมน์ที่เป็น "อายุ/ระยะเวลา/ค่าเฉลี่ย/เปอร์เซ็นต์" ในลิสต์นี้
 */
const REPORT_NO_TOTAL_COLUMNS = ['days_overdue', 'days_unpaid', 'days_left'];

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: คำนวณยอดรวมท้ายตารางรายงาน — F-50
 * ==========================================================================
 * 🧠 ปัญหาเดิม: ตาราง 217 แถวไม่มีแถวรวม ต้องเปิด Excel บวกเอง
 *
 * 🔴 **ห้ามเดาว่าคอลัมน์ไหนเป็นตัวเลขด้วย is_numeric()**
 *    เบอร์โทร "0891234567" ก็ผ่าน is_numeric() → จะได้ "ยอดรวมเบอร์โทร" ที่ไร้ความหมาย
 *    ใช้รายชื่อคอลัมน์ที่ประกาศไว้แล้ว (REPORT_MONEY_COLUMNS / REPORT_COUNT_COLUMNS)
 *    เป็นตัวตัดสินเท่านั้น — เหตุผลเดียวกับที่ F-44 เขียนเตือนไว้
 *
 * 🧠 คอลัมน์ที่ไม่ใช่ตัวเลขจะได้ค่า null → ผู้เรียกเว้นช่องนั้นไว้ว่าง
 *    ไม่ใส่ 0 เพราะ "รวมชื่อหนังสือได้ 0" อ่านแล้วชวนสับสนกว่าไม่ใส่อะไรเลย
 *
 * 📥 Input: @param array $data แถวข้อมูลทั้งหมด (ไม่ใช่แค่หน้าเดียว — รายงานไม่แบ่งหน้า)
 * 📤 Output: @return array<string, float|null> map ชื่อคอลัมน์ → ยอดรวม (null = รวมไม่ได้)
 */
function reportColumnTotals(array $data): array
{
    if (!$data) return [];

    $totals = [];
    foreach (array_keys((array) reset($data)) as $key) {
        // 🔴 ตัวเลขบางตัวรวมยอดไม่ได้ — ดู REPORT_NO_TOTAL_COLUMNS
        if (in_array($key, REPORT_NO_TOTAL_COLUMNS, true)) {
            $totals[$key] = null;
            continue;
        }
        $isMoney = in_array($key, REPORT_MONEY_COLUMNS, true);
        $isCount = in_array($key, REPORT_COUNT_COLUMNS, true);
        if (!$isMoney && !$isCount) {
            $totals[$key] = null;
            continue;
        }
        $sum = 0.0;
        foreach ($data as $row) {
            $sum += (float) ($row[$key] ?? 0);
        }
        $totals[$key] = $sum;
    }
    return $totals;
}

/**
 * 🎯 จัดรูปแบบยอดรวมสำหรับ **หน้าจอ** — ใช้คอมมาคั่นหลักให้อ่านง่าย
 */
function formatReportTotal(string $key, ?float $total): string
{
    if ($total === null) return '';
    return in_array($key, REPORT_MONEY_COLUMNS, true)
        ? number_format($total, 2)
        : number_format($total);
}

/**
 * 🎯 จัดรูปแบบยอดรวมสำหรับ **ไฟล์ CSV** — ไม่ใส่คอมมา ให้ Excel SUM ต่อได้
 */
function csvReportTotal(string $key, ?float $total): string
{
    if ($total === null) return '';
    return in_array($key, REPORT_MONEY_COLUMNS, true)
        ? number_format($total, 2, '.', '')
        : (string) (int) $total;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: จัดรูปแบบค่าสำหรับ **ไฟล์ CSV** โดยเฉพาะ — F-44
 * ==========================================================================
 *
 * 🧠 **ทำไมไม่ใช้ formatReportValue() ตัวเดียวกับหน้าจอ**
 *    หน้าจอกับ CSV ต้องการคนละอย่างในคอลัมน์ตัวเลข:
 *      หน้าจอ → "1,250.00" อ่านง่าย
 *      CSV    → "1250.00" ไม่มีคอมมา **ไม่งั้น Excel มองเป็นข้อความแล้ว SUM ไม่ได้**
 *    ลูกค้าเอาไฟล์ไปรวมยอดต่อ ถ้าใส่คอมมาให้ = ทำพังใหม่แทนที่จะแก้
 *
 * 📥 Input: @param string $key ชื่อคอลัมน์, @param mixed $value ค่าจาก DB
 * 📤 Output: @return string ค่าที่พร้อมเขียนลง CSV
 *
 * 🛡️ ยังต้องส่งต่อให้ csvSafeValue() อีกชั้นเพื่อกัน formula injection
 */
function csvReportValue(string $key, mixed $value): string
{
    // 📞 เบอร์โทร / ISBN / รหัส — เติม ' นำหน้าให้ Excel มองเป็นข้อความ
    //    🧠 ครอบด้วย " เฉย ๆ **ไม่พอ** — Excel ยังตีความเป็นตัวเลขแล้วตัด 0 นำหน้าทิ้งอยู่ดี
    //       (0891809067 → 891809067) เจ้าหน้าที่เอาไปโทรตามคนไม่ได้
    //       เครื่องหมาย ' จะไม่แสดงบนหน้าจอ Excel — เป็นวิธีเดียวกับที่ csvSafeValue() ใช้
    if (in_array($key, REPORT_TEXT_CODE_COLUMNS, true)) {
        $value = (string) $value;
        return $value === '' ? '' : "'" . $value;
    }

    // 🔤 ค่า enum จาก DB — แปลเป็นภาษาไทยเหมือนที่หน้าจอเห็น
    //    ห้ามปล่อย returned/lost/damaged ดิบ ๆ ลงไฟล์ที่ลูกค้าเอาไปใช้
    if ($key === 'status') {
        return formatReportValue($key, $value);
    }

    // 💰 เงินและจำนวนนับ — **ไม่ใส่คอมมา** เพื่อให้ Excel SUM ได้
    if (in_array($key, REPORT_MONEY_COLUMNS, true)) {
        return number_format((float) $value, 2, '.', '');
    }
    if (in_array($key, REPORT_COUNT_COLUMNS, true)) {
        return (string) (int) $value;
    }

    return (string) $value;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: กัน CSV Formula Injection ก่อนเขียนลงไฟล์
 * ==========================================================================
 *
 * 🛡️ [SECURITY] Excel/LibreOffice ตีความเซลล์ที่ขึ้นต้นด้วย = + - @ (รวมถึง TAB/CR)
 *    ว่าเป็น "สูตร" แล้วสั่งรันทันทีที่เปิดไฟล์
 *    เช่น ชื่อหนังสือ =cmd|' /C calc'!A0 → รันคำสั่งบนเครื่องคนที่เปิดไฟล์
 *
 * ⚠️ การใส่ quote ของ fputcsv() **ไม่ได้ป้องกัน** เรื่องนี้ — ต้องเติม ' นำหน้าเอง
 *    Excel จะแสดงผลเป็นข้อความธรรมดาโดยไม่โชว์เครื่องหมาย ' ที่เติมเข้าไป
 *
 * 📥 Input: @param mixed $value ค่าที่จะเขียนลง CSV
 * 📤 Output: @return string ค่าที่ปลอดภัยแล้ว
 * ✅ Use case: admin/reports.php ตอน export CSV
 */
function csvSafeValue(mixed $value): string
{
    $value = (string) $value;

    if ($value === '') {
        return $value;
    }

    // 🔢 ตัวเลขล้วน (รวมค่าติดลบและทศนิยม) เป็นสูตรไม่ได้ → ปล่อยผ่าน
    //    🔴 ถ้าไม่ยกเว้นตรงนี้ ค่าเงินติดลบอย่าง -50.00 จะกลายเป็น '-50.00
    //       แล้ว Excel มองเป็นข้อความ → ลูกค้า SUM คอลัมน์นั้นไม่ได้
    //    ตอนนี้รายงานยังไม่มีค่าติดลบ แต่วันที่มีระบบคืนเงิน/ปรับยอด จะพังทันทีถ้าไม่กันไว้
    //    "-1+cmd|..." ไม่ผ่านเงื่อนไขนี้ จึงยังถูกเติม ' ตามเดิม
    if (preg_match('/^-?\d+(\.\d+)?$/', $value)) {
        return $value;
    }

    // 📝 ตัวอักษรตัวแรกที่ Excel ใช้ตัดสินว่าเป็นสูตร
    if (str_contains("=+-@\t\r", $value[0])) {
        return "'" . $value;
    }

    return $value;
}
