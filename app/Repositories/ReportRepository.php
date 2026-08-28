<?php
/**
 * ReportRepository - Database Access สำหรับรายงานและสถิติ
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * Repository นี้รวบรวม query สำหรับรายงานและสถิติทั้งหมด
 * เป็น "read-only" repository — ไม่มี INSERT/UPDATE/DELETE
 *
 * 📍 Entrypoints:
 * - ReportService      → เรียกทุกเมธอด
 * - DashboardService   → getBorrowStats(), getFineStats(), getMonthlyReport(),
 *                        getCategoryDistribution(), getPopularBooks()
 * - admin/reports.php  → หน้ารายงาน
 * - admin/export_pdf.php → getOverdueReport(), getBorrowsReport(), getUnpaidFinesReport(),
 *                         getTopBooksReport(), getTopMembersReport()
 *
 * 🏗️ สถาปัตยกรรม:
 * - บางเมธอดเรียกจาก DashboardService/ReportService
 * - บางเมธอดเรียกจาก Controller โดยตรง (export_pdf.php)
 *
 * 🛡️ Security: ทุกเมธอดใช้ prepared statement สำหรับพารามิเตอร์
 *
 * @package App\Repositories
 *
 * ==========================================================================
 * 🗺️ Quick Map — เมธอดไหนใช้กับ flow ไหน
 * ==========================================================================
 *
 * 📊 Dashboard (admin/index.php → DashboardService):
 *   getBorrowStats()          → การ์ดสถิติยืม (active/overdue/today/month)
 *   getFineStats()            → การ์ดสถิติค่าปรับ (total/unpaid/month)
 *   getMonthlyReport()        → กราฟแท่ง ยืม vs คืน รายเดือน
 *   getCategoryDistribution() → กราฟวงกลม หมวดหมู่ยอดนิยม
 *   getPopularBooks()         → ตาราง หนังสือยอดนิยม
 *
 * 📋 Reports (admin/reports.php → ReportService):
 *   getAllCategoriesWithStats()→ ตารางสรุปหมวดหมู่ทั้งหมด
 *   getPopularBooks()         → ตารางหนังสือยอดนิยม (ใช้ร่วมกับ dashboard)
 *   getTopBorrowers()         → ตารางสมาชิกที่ยืมบ่อย
 *   getDailyReport()          → สรุปยืม/คืน/สมัคร ประจำวัน
 *   getDailyRevenueReport()   → รายงานรายได้ค่าปรับรายวัน
 *
 * 🖨️ PDF Export (admin/export_pdf.php):
 *   getOverdueReport()        → รายงานหนังสือเกินกำหนด
 *   getBorrowsReport()        → รายงานการยืมตามช่วงวันที่
 *   getUnpaidFinesReport()    → รายงานค้างชำระค่าปรับ
 *   getTopBooksReport()       → รายงานหนังสือยอดนิยม (มี date range)
 *   getTopMembersReport()     → รายงานสมาชิกที่ใช้บริการบ่อย
 *
 * ==========================================================================
 * ⚠️ Danger Zones — จุดเสี่ยงในไฟล์นี้
 * ==========================================================================
 *
 * 1. 🔴 getFineStats() unpaid logic
 *    - ใช้ LEFT JOIN payments + IS NULL → ถ้าเพิ่มระบบจ่ายบางส่วน logic จะพัง
 *    - ปัจจุบัน 1 borrow = 1 payment → ปลอดภัย
 *
 * 2. 🔴 getUnpaidFinesReport() ใช้ NOT IN subquery
 *    - ถ้าตาราง payments ใหญ่มาก → query ช้า (ไม่มีปัญหาในระดับ template)
 *    - production ขนาดใหญ่ → ควรเปลี่ยนเป็น LEFT JOIN + IS NULL
 *
 * 3. 🟡 getBorrowStats()/getFineStats() ใช้ query() ไม่ใช่ prepare()
 *    - ไม่มี user input → ปลอดภัย แต่ถ้าเพิ่ม parameter → ต้องเปลี่ยนเป็น prepare()
 *
 * 4. 🟡 getTopMembersReport() ต่อ SQL string ด้วย $roleCol
 *    - ค่ามาจาก code ภายใน → ปลอดภัย / ห้ามรับค่าจาก user input!
 *
 * 5. 🟡 getOverdueReport() ต่อ SQL string ด้วย $borrowDateCol/$dueDateCol
 *    - เหมือนข้อ 4 → ค่ามาจาก code ภายใน → ปลอดภัย
 *
 * 6. 🟢 ทุกเมธอดเป็น read-only (SELECT)
 *    - ไม่มี INSERT/UPDATE/DELETE → ไม่ทำข้อมูลเสียหาย
 *    - แต่ข้อมูลที่ส่งออก (email, phone) → ต้อง escape ก่อนแสดงผล HTML
 */

namespace App\Repositories;

use PDO;

class ReportRepository
{
    // 🗄️ PDO connection — ใช้ร่วมกันทุกเมธอด (inject ผ่าน constructor)
    private PDO $pdo;

    // 🏗️ Constructor: รับ PDO จากภายนอก (Dependency Injection)
    // → ใช้ connection เดียวกับ Service ที่เรียก
    // → ทำให้ transaction ที่เปิดใน Service ใช้ได้ใน Repository ด้วย
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // 📝 getBookStats() ถูกย้ายไป BookRepository::getStatistics()
    //    → Single Source of Truth: สถิติหนังสือควรอยู่ที่ BookRepository
    // NOTE: getBookStats() removed - use BookRepository::getStatistics()
    // 📝 getMemberStats() ถูกย้ายไป UserRepository
    //    → Single Source of Truth: สถิติสมาชิกควรอยู่ที่ UserRepository
    // NOTE: getMemberStats() removed - use UserRepository::countMembers() + countNewThisMonth()

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สถิติการยืมภาพรวม (สำหรับ admin dashboard)
     * ==========================================================================
     * 4 queries แยกกัน เพราะแต่ละตัวมีเงื่อนไขต่างกัน
     *
     * 📤 Output:
     * @return array {active, overdue, today, this_month}
     *         - active: กำลังยืมอยู่ (status='borrowing')
     *         - overdue: เกินกำหนดคืน (borrowing + due_date < today)
     *         - today: ยืมวันนี้
     *         - this_month: ยืมเดือนนี้
     *
     * ✅ Use case: admin/index.php → DashboardService
     */
    public function getBorrowStats(): array
    {
        // 🔄 Flow: admin/index.php → DashboardService → getBorrowStats()
        // 🎯 รวม 4 สถิติการยืมไว้ใน array เดียว ส่งกลับไปแสดงเป็นการ์ดบน dashboard
        //
        // 📝 ใช้ query() แทน prepare() เพราะไม่มี user input ใดเลย
        //    ทุก query เป็น static SQL → ไม่มีความเสี่ยง SQL Injection
        //    ⚠️ Danger Zone #3: ถ้าวันหนึ่งต้องเพิ่ม filter → ต้องเปลี่ยนเป็น prepare()
        return [
            // 📊 active: นับรายการที่สถานะ = 'borrowing' (กำลังยืมอยู่ ยังไม่คืน)
            // SQL: SELECT COUNT(*) FROM borrows WHERE status = 'borrowing'
            // → fetchColumn() ดึงค่า COUNT เดียว → cast เป็น int
            'active' => (int) $this->pdo->query("SELECT COUNT(*) FROM borrows WHERE status = 'borrowing'")->fetchColumn(),

            // 📊 overdue: นับรายการที่ยังไม่คืน + เลยกำหนดแล้ว
            // SQL: status = 'borrowing' AND due_date < CURDATE()
            // → CURDATE() = วันนี้ของ MySQL server (ไม่ใช่ PHP)
            // ⚠️ ถ้า timezone ของ MySQL กับ PHP ต่างกัน → ค่าอาจคลาดเคลื่อน 1 วัน
            'overdue' => (int) $this->pdo->query("
                SELECT COUNT(*) FROM borrows WHERE status = 'borrowing' AND due_date < CURDATE()
            ")->fetchColumn(),

            // 📊 today: นับรายการที่ยืมวันนี้ (ทุก status รวม returned ด้วย)
            // SQL: DATE(borrow_date) = CURDATE() → DATE() ตัดเอาเฉพาะวันที่ (ไม่สน time)
            'today' => (int) $this->pdo->query("
                SELECT COUNT(*) FROM borrows WHERE DATE(borrow_date) = CURDATE()
            ")->fetchColumn(),

            // 📊 this_month: นับรายการที่ยืมเดือนนี้
            // SQL: เทียบทั้ง MONTH + YEAR เพื่อไม่ให้นับเดือนเดียวกันข้ามปี
            // เช่น ม.ค. 2025 กับ ม.ค. 2024 ต้องแยกกัน
            'this_month' => (int) $this->pdo->query("
                SELECT COUNT(*) FROM borrows 
                WHERE MONTH(borrow_date) = MONTH(CURDATE()) AND YEAR(borrow_date) = YEAR(CURDATE())
            ")->fetchColumn(),
        ];
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สถิติค่าปรับภาพรวม (สำหรับ admin dashboard)
     * ==========================================================================
     *
     * 📤 Output:
     * @return array {total, unpaid, this_month}
     *         - total: SUM(fine_amount) ทั้งหมดตลอดกาล
     *         - unpaid: ค่าปรับค้างชำระ (LEFT JOIN payments WHERE p.id IS NULL)
     *         - this_month: ค่าปรับเดือนนี้ (ตาม return_date)
     *
     * 🧠 เหตุผล: unpaid ใช้ LEFT JOIN payments และหาที่ p.id IS NULL
     * ✅ Use case: admin/index.php → DashboardService
     */
    public function getFineStats(): array
    {
        // 🔄 Flow: admin/index.php → DashboardService → getFineStats()
        // 🎯 รวม 3 สถิติค่าปรับไว้ใน array เดียว แสดงเป็นการ์ดบน dashboard
        //
        // 📝 ใช้ query() เพราะไม่มี user input (เหมือน getBorrowStats)
        return [
            // 💰 total: ค่าปรับรวมทั้งหมดตลอดกาล (ทั้งจ่ายแล้ว + ยังไม่จ่าย)
            // SQL: COALESCE(SUM(fine_amount), 0) → ถ้าไม่มีข้อมูลเลย return 0 แทน NULL
            // ⚠️ นับรวม "ทุก" borrow ที่มี fine_amount (ไม่สนว่าจ่ายแล้วหรือยัง)
            'total' => (float) $this->pdo->query("SELECT COALESCE(SUM(fine_amount), 0) FROM borrows")->fetchColumn(),

            // 💰 unpaid: ค่าปรับค้างชำระ (ยังไม่มี record ใน payments)
            // SQL: LEFT JOIN payments → ถ้า p.id IS NULL = ยังไม่ได้ชำระ
            //
            // 🧠 วิธีคิด: เอาทุก borrow ที่มีค่าปรับ > 0
            //    แล้ว LEFT JOIN กับ payments → ถ้าไม่มี payment จับคู่ได้ → p.id = NULL
            //    → แปลว่ายังไม่ได้จ่าย
            //
            // ⚠️ Danger Zone #1: ถ้าระบบรองรับ "จ่ายบางส่วน" (partial payment)
            //    logic นี้จะพัง เพราะมี payment แล้ว → p.id ไม่ใช่ NULL → ไม่นับ
            //    แต่ปัจจุบัน 1 borrow = 1 payment เท่านั้น → ปลอดภัย
            'unpaid' => (float) $this->pdo->query("
                SELECT COALESCE(SUM(b.fine_amount), 0) 
                FROM borrows b
                LEFT JOIN payments p ON b.id = p.borrow_id
                WHERE b.fine_amount > 0 AND p.id IS NULL
                  AND b.fine_waived_at IS NULL   -- 💸 ยกเว้นแล้วไม่นับเป็นค้างชำระอีก (ROADMAP ข้อ 2)
            ")->fetchColumn(),

            // 💰 this_month: ค่าปรับเดือนนี้ (นับจาก return_date ไม่ใช่ borrow_date)
            // 🧠 ทำไมใช้ return_date? เพราะค่าปรับเกิดตอนคืน ไม่ใช่ตอนยืม
            // ⚠️ ถ้ายังไม่คืน (return_date = NULL) → ไม่ถูกนับ (ถูกต้อง)
            'this_month' => (float) $this->pdo->query("
                SELECT COALESCE(SUM(fine_amount), 0) FROM borrows 
                WHERE MONTH(return_date) = MONTH(CURDATE()) AND YEAR(return_date) = YEAR(CURDATE())
            ")->fetchColumn(),
        ];
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: รายงานการยืมรายเดือน (สำหรับ bar chart)
     * ==========================================================================
     *
     * 📥 Input: @param int $months จำนวนเดือนย้อนหลัง (default: 6)
     *
     * 📤 Output:
     * @return array[] [{month, month_name, total_borrows, returned}, ...]
     *         - ใช้ใน: admin dashboard bar chart (ยืม vs คืน รายเดือน)
     *
     * 🧠 เหตุผล: GROUP BY YYYY-MM, SUM(CASE) แยก returned ออกมา
     * ✅ Use case: admin/index.php → DashboardService → chart การยืมรายเดือน
     */
    public function getMonthlyReport(int $months = 6): array
    {
        // 🔄 Flow: admin/index.php → DashboardService → getMonthlyReport(6)
        // 🎯 ดึงข้อมูลยืม/คืนรายเดือน สำหรับสร้างกราฟแท่ง (bar chart) บน dashboard
        //
        // 📝 SQL อธิบาย:
        //    - DATE_FORMAT('%Y-%m') → จัดกลุ่มเป็น "2025-01", "2025-02"
        //    - DATE_FORMAT('%b')    → ชื่อเดือนย่อ เช่น "Jan", "Feb" (สำหรับแสดงบน chart)
        //    - COUNT(*)            → จำนวนยืมทั้งหมดในเดือนนั้น
        //    - SUM(CASE WHEN status='returned') → นับเฉพาะที่คืนแล้ว
        //    - WHERE borrow_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
        //      → กรองเฉพาะ N เดือนล่าสุด (? = $months)
        //    - GROUP BY เดือน → ORDER BY ASC → เรียงจากเก่าไปใหม่ (ซ้ายไปขวาบน chart)
        //
        // 📤 Output: [{month: "2025-01", month_name: "Jan", total_borrows: 15, returned: 12}, ...]
        // ⚠️ ถ้าเดือนไหนไม่มีการยืมเลย → เดือนนั้นจะไม่ปรากฏในผลลัพธ์ (ไม่ใช่ค่า 0)
        //    chart อาจข้ามเดือนได้ (ไม่มีปัญหาใน template แต่ production อาจต้อง fill gaps)
        $stmt = $this->pdo->prepare("
            SELECT 
                DATE_FORMAT(borrow_date, '%Y-%m') as month,
                DATE_FORMAT(borrow_date, '%b') as month_name,
                COUNT(*) as total_borrows,
                SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned
            FROM borrows 
            WHERE borrow_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
            GROUP BY DATE_FORMAT(borrow_date, '%Y-%m')
            ORDER BY month ASC
        ");
        // 🛡️ bind $months เป็น parameter → ป้องกัน SQL Injection
        $stmt->execute([$months]);
        // 📤 return array ของ associative arrays (PDO::FETCH_ASSOC จาก config)
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: หมวดหมู่ยอดนิยม Top N (สำหรับ pie chart)
     * ==========================================================================
     *
     * 📥 Input: @param int $limit จำนวนหมวดหมู่ (default: 6)
     * 📤 Output: @return array[] [{name, borrow_count}, ...]
     *
     * 🧠 เหตุผล: LEFT JOIN 2 ชั้น (categories → books → borrows)
     * ✅ Use case: admin/index.php → DashboardService → pie chart
     */
    public function getCategoryDistribution(int $limit = 6): array
    {
        // 🔄 Flow: admin/index.php → DashboardService → getCategoryDistribution(6)
        // 🎯 ดึงหมวดหมู่ยอดนิยม Top N สำหรับสร้างกราฟวงกลม (pie chart)
        //
        // 📝 SQL อธิบาย:
        //    - LEFT JOIN 2 ชั้น: categories → books → borrows
        //      (หมวดหมู่ไหนไม่มีหนังสือ/ไม่เคยถูกยืม → borrow_count = 0)
        //    - COUNT(b.id) → นับจำนวนการยืมต่อหมวดหมู่
        //    - GROUP BY c.id, c.name → จัดกลุ่มตามหมวดหมู่
        //    - ORDER BY borrow_count DESC → เรียงจากยอดยืมมากไปน้อย
        //    - LIMIT ? → แสดงแค่ Top N ($limit)
        //
        // 📤 Output: [{name: "นวนิยาย", borrow_count: 45}, ...]
        // ⚠️ ถ้าหมวดหมู่มีหนังสือหลายเล่ม → borrow_count เป็นผลรวมทุกเล่ม
        $stmt = $this->pdo->prepare("
            SELECT c.name, COUNT(b.id) as borrow_count
            FROM categories c
            LEFT JOIN books bk ON c.id = bk.category_id
            LEFT JOIN borrows b ON bk.id = b.book_id
            GROUP BY c.id, c.name
            ORDER BY borrow_count DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: หมวดหมู่ทั้งหมดพร้อมสถิติ (สำหรับตารางสรุป)
     * ==========================================================================
     *
     * 📤 Output: @return array[] [{name, book_count, borrow_count}, ...]
     *
     * 🧠 เหตุผล:
     * - COUNT(DISTINCT bk.id) นับหนังสือ (ไม่นับ borrows)
     * - COUNT(b.id) นับการยืม (รวมทุก status)
     * ✅ Use case: admin/reports.php → ตารางสรุปหมวดหมู่
     */
    public function getAllCategoriesWithStats(): array
    {
        // 🔄 Flow: admin/reports.php → ReportService → getAllCategoriesWithStats()
        // 🎯 ดึงหมวดหมู่ทั้งหมดพร้อมจำนวนหนังสือ + ยอดยืม สำหรับตารางสรุป
        //
        // 📝 SQL อธิบาย:
        //    - LEFT JOIN 2 ชั้น (เหมือน getCategoryDistribution)
        //    - COUNT(DISTINCT bk.id) → นับจำนวน "หนังสือ" (ไม่ซ้ำ)
        //      ⚠️ ต้องใช้ DISTINCT เพราะ 1 หนังสือถูกยืมหลายครั้ง → ถ้าไม่ DISTINCT จะนับซ้ำ
        //    - COUNT(b.id) → นับจำนวน "การยืม" ทั้งหมด (ยืมกี่ครั้งก็นับ)
        //    - ORDER BY c.name ASC → เรียงตามชื่อหมวดหมู่ (ก-ฮ / A-Z)
        //
        // 📤 Output: [{name: "นวนิยาย", book_count: 12, borrow_count: 45}, ...]
        // 📝 ใช้ query() เพราะไม่มี parameter → ไม่มีความเสี่ยง SQL Injection
        return $this->pdo->query("
            SELECT c.name, 
                   COUNT(DISTINCT bk.id) as book_count,
                   COUNT(b.id) as borrow_count
            FROM categories c
            LEFT JOIN books bk ON c.id = bk.category_id
            LEFT JOIN borrows b ON bk.id = b.book_id
            GROUP BY c.id, c.name
            ORDER BY c.name ASC
        ")->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: หนังสือยอดนิยม Top N (เรียงตามยอดยืม)
     * ==========================================================================
     *
     * 📥 Input: @param int $limit จำนวนสูงสุด (default: 10)
     * 📤 Output: @return array[] [{id, title, author, borrow_count}, ...]
     * ✅ Use case: admin/reports.php, DashboardService
     */
    public function getPopularBooks(int $limit = 10): array
    {
        // 🔄 Flow: admin/reports.php + admin/index.php → ReportService/DashboardService
        // 🎯 ดึงหนังสือยอดนิยมเรียงตามยอดยืม สำหรับตาราง Top Books
        //
        // 📝 SQL อธิบาย:
        //    - LEFT JOIN borrows → นับการยืมต่อหนังสือ
        //      (LEFT JOIN เพราะหนังสือที่ยังไม่เคยถูกยืม → borrow_count = 0)
        //    - GROUP BY bk.id → จัดกลุ่มตามหนังสือ
        //    - ORDER BY borrow_count DESC → เรียงจากยอดยืมมากไปน้อย
        //    - LIMIT ? → แสดงแค่ Top N ($limit)
        //
        // 📤 Output: [{id: 1, title: "...", author: "...", borrow_count: 30}, ...]
        $stmt = $this->pdo->prepare("
            SELECT bk.id, bk.title, bk.author, COUNT(b.id) as borrow_count
            FROM books bk
            LEFT JOIN borrows b ON bk.id = b.book_id
            GROUP BY bk.id, bk.title, bk.author
            ORDER BY borrow_count DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สมาชิกที่ยืมมากที่สุด Top N
     * ==========================================================================
     *
     * 📥 Input: @param int $limit (default: 10)
     * 📤 Output: @return array[] [{id, name, email, borrow_count}, ...]
     *
     * 🧠 เหตุผล: WHERE role='member' กรองเฉพาะสมาชิก (ไม่รวม admin/staff)
     * ✅ Use case: admin/reports.php, DashboardService
     */
    public function getTopBorrowers(int $limit = 10): array
    {
        // 🔄 Flow: admin/reports.php + admin/index.php → ReportService/DashboardService
        // 🎯 ดึงสมาชิกที่ยืมมากที่สุด Top N สำหรับตาราง Top Borrowers
        //
        // 📝 SQL อธิบาย:
        //    - WHERE u.role = 'member' → กรองเฉพาะสมาชิก (ไม่รวม admin/staff)
        //      🧠 เหตุผล: admin/staff อาจยืมทดสอบ → ไม่ควรปนกับสถิติสมาชิก
        //    - LEFT JOIN borrows → นับการยืมต่อ user
        //    - GROUP BY u.id → จัดกลุ่มตามสมาชิก
        //    - ORDER BY borrow_count DESC → เรียงจากยอดยืมมากไปน้อย
        //
        // 📤 Output: [{id: 5, name: "สมชาย", email: "somchai@...", borrow_count: 12}, ...]
        // ⚠️ email อยู่ในผลลัพธ์ → ต้อง escape ด้วย e() ก่อนแสดงใน HTML
        //    (ป้องกัน XSS ถ้ามี email แปลกๆ)
        $stmt = $this->pdo->prepare("
            SELECT u.id, u.name, u.email, COUNT(b.id) as borrow_count
            FROM users u
            LEFT JOIN borrows b ON u.id = b.user_id
            WHERE u.role = 'member'
            GROUP BY u.id, u.name, u.email
            ORDER BY borrow_count DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: รายงานรายวัน (สรุปยืม/คืน/สมัครใหม่ ในวันที่ระบุ)
     * ==========================================================================
     *
     * 📥 Input: @param string|null $date Y-m-d หรือ null = วันนี้
     *
     * 📤 Output:
     * @return array {date, borrows, returns, new_members}
     *
     * 🧠 เหตุผล: 3 queries แยกกัน เพราะ query คนละตาราง
     * ✅ Use case: admin/reports.php → รายงานประจำวัน
     */
    public function getDailyReport(?string $date = null): array
    {
        // 🔄 Flow: admin/reports.php → ReportService → getDailyReport()
        // 🎯 สรุปข้อมูล 3 อย่างของวันที่ระบุ: ยืมกี่รายการ / คืนกี่รายการ / สมัครใหม่กี่คน
        //
        // 📥 Input: $date = 'Y-m-d' หรือ null (null = วันนี้)
        // 📝 ถ้าไม่ส่งวันที่มา → ใช้วันนี้ของ PHP server
        // ⚠️ ถ้า timezone PHP ≠ MySQL → ค่า "วันนี้" อาจต่างกัน (ดู config.php → TIMEZONE)
        $date = $date ?? date('Y-m-d');

        // 📊 Query 1: นับรายการยืมของวันนั้น
        // SQL: DATE(borrow_date) = ? → ? = $date (bind parameter)
        // → fetchColumn() ได้ค่า COUNT เดียว → cast เป็น int
        $stmtBorrows = $this->pdo->prepare("SELECT COUNT(*) FROM borrows WHERE DATE(borrow_date) = ?");
        $stmtBorrows->execute([$date]);
        $borrows = (int) $stmtBorrows->fetchColumn();

        // 📊 Query 2: นับรายการคืนของวันนั้น
        // SQL: DATE(return_date) = ? → return_date อาจเป็น NULL (ยังไม่คืน) → ไม่ถูกนับ (ถูกต้อง)
        $stmtReturns = $this->pdo->prepare("SELECT COUNT(*) FROM borrows WHERE DATE(return_date) = ?");
        $stmtReturns->execute([$date]);
        $returns = (int) $stmtReturns->fetchColumn();

        // 📊 Query 3: นับสมาชิกใหม่ของวันนั้น
        // SQL: role = 'member' → ไม่นับ admin/staff ที่ถูกสร้างในวันนั้น
        $stmtMembers = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at) = ? AND role = 'member'");
        $stmtMembers->execute([$date]);
        $newMembers = (int) $stmtMembers->fetchColumn();

        // 📤 Output: {date: "2025-01-15", borrows: 5, returns: 3, new_members: 2}
        return [
            'date' => $date,
            'borrows' => $borrows,
            'returns' => $returns,
            'new_members' => $newMembers,
        ];
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: รายงานหนังสือยอดนิยมตามช่วงวันที่ (สำหรับ PDF + reports)
     * ==========================================================================
     *
     * 📥 Input:
     * @param int         $limit     จำนวนสูงสุด (default: 50)
     * @param string|null $startDate Y-m-d (default: 1 ปีย้อนหลัง)
     * @param string|null $endDate   Y-m-d (default: วันนี้)
     *
     * 📤 Output: @return array[] [{title, category, borrow_count, currently_borrowed}, ...]
     *
     * 🧠 เหตุผล: CASE WHEN borrow_date BETWEEN นับเฉพาะช่วงเวลา
     * ✅ Use case: admin/reports.php, export_pdf.php
     */
    public function getTopBooksReport(int $limit = 50, ?string $startDate = null, ?string $endDate = null): array
    {
        // 🔄 Flow: admin/reports.php + export_pdf.php → ReportService
        // 🎯 ดึงหนังสือยอดนิยมตามช่วงวันที่ (มี date range ต่างจาก getPopularBooks)
        //    ใช้สำหรับรายงานและ PDF export

        // 📝 Default: ถ้าไม่ส่ง date range → ใช้ 1 ปีย้อนหลังจากวันนี้
        if (!$startDate || !$endDate) {
            $endDate = date('Y-m-d');
            $startDate = date('Y-m-d', strtotime('-1 year'));
        }
        
        // 📝 SQL อธิบาย:
        //    - LEFT JOIN categories + borrows → เอาชื่อหมวดหมู่ + นับยอดยืม
        //    - COUNT(CASE WHEN br.borrow_date BETWEEN ? AND ? THEN br.id END)
        //      → นับเฉพาะการยืมในช่วงวันที่ระบุ (ไม่นับนอกช่วง)
        //      → ? ตัวแรก = $startDate, ? ตัวที่ 2 = $endDate
        //    - (b.quantity - b.available) = จำนวนที่ถูกยืม/จองอยู่ตอนนี้
        //    - GROUP BY b.id → จัดกลุ่มตามหนังสือ
        //    - LIMIT ? → ? ตัวที่ 3 = $limit
        //
        // 📤 Output: [{title: "...", category: "...", borrow_count: 30, currently_borrowed: 2}, ...]
        $stmt = $this->pdo->prepare("
            SELECT b.title, c.name as category, 
                   COUNT(CASE WHEN br.borrow_date BETWEEN ? AND ? THEN br.id END) as borrow_count,
                   (b.quantity - b.available) as currently_borrowed
            FROM books b
            LEFT JOIN categories c ON b.category_id = c.id
            LEFT JOIN borrows br ON b.id = br.book_id
            GROUP BY b.id
            ORDER BY borrow_count DESC
            LIMIT ?
        ");
        $stmt->execute([$startDate, $endDate, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สมาชิกที่ใช้บริการบ่อยตามช่วงวันที่ (สำหรับ PDF + reports)
     * ==========================================================================
     *
     * 📥 Input:
     * @param int         $limit         จำนวนสูงสุด (default: 50)
     * @param string|null $startDate     Y-m-d (default: 1 ปีย้อนหลัง)
     * @param string|null $endDate       Y-m-d (default: วันนี้)
     *
     * 📤 Output: @return array[] [{name, email, role/role_name, borrow_count, active_loans}, ...]
     *
     * 🧠 เหตุผล:
     * - role ถูกแปลเป็นไทยใน SQL เสมอ (ใช้ค่าเดียวกันทุกช่องทาง)
     * - HAVING borrow_count > 0 กรองเฉพาะที่เคยยืม
     * - WHERE role != 'admin' ไม่แสดง admin
     * ✅ Use case: admin/reports.php, export_pdf.php
     */
    public function getTopMembersReport(int $limit = 50, ?string $startDate = null, ?string $endDate = null): array
    {
        // 🔄 Flow: admin/reports.php + export_pdf.php → ReportService
        // 🎯 ดึงสมาชิกที่ใช้บริการบ่อยตามช่วงวันที่ + แปลง role เป็นไทย (สำหรับ PDF)

        // 📝 Default: ถ้าไม่ส่ง date range → ใช้ 1 ปีย้อนหลัง
        if (!$startDate || !$endDate) {
            $endDate = date('Y-m-d');
            $startDate = date('Y-m-d', strtotime('-1 year'));
        }
        
        // 📝 $roleCol: เลือกว่าจะแสดง role ดิบ ('member'/'staff') หรือแปลเป็นภาษาไทย
        //    - $translateRole = true  → PDF export ต้องการภาษาไทย
        //    - $translateRole = false → หน้าเว็บใช้ role ดิบ
        //
        // ⚠️ Danger Zone #4: $roleCol ถูกต่อเข้า SQL string โดยตรง (ไม่ได้ bind)
        //    แต่ปลอดภัย เพราะค่ามาจาก code ภายใน (boolean $translateRole)
        //    🔴 ห้ามเปลี่ยนให้รับค่าจาก user input → SQL Injection ทันที!
        // 🔴 [FIX] แปลเป็นไทยเสมอ — เดิมแปลเฉพาะ PDF ทำให้ CSV ได้คำว่า "member" ดิบ
        //    ส่วนหน้าเว็บแปลเองอีกที่หนึ่ง (กฎเดียวกันอยู่ 2 ที่)
        $roleCol = "CASE u.role WHEN 'staff' THEN 'เจ้าหน้าที่' ELSE 'สมาชิก' END as role_name,";
        
        // 📝 SQL อธิบาย:
        //    - WHERE u.role != 'admin' → ไม่แสดง admin ในรายงาน
        //    - COUNT(CASE WHEN br.borrow_date BETWEEN ? AND ?) → นับเฉพาะในช่วงวันที่
        //    - SUM(CASE WHEN br.status = 'borrowing') → นับรายการที่ยังยืมอยู่ตอนนี้
        //    - HAVING borrow_count > 0 → กรองเฉพาะที่เคยยืม (ไม่แสดงคนไม่เคยยืม)
        //    - ? ตำแหน่ง: [startDate, endDate, limit]
        //
        // 📤 Output: [{name, email, role/role_name, borrow_count, active_loans}, ...]
        // ⚠️ email อยู่ในผลลัพธ์ → ต้อง escape ด้วย e() ก่อนแสดงใน HTML
        $stmt = $this->pdo->prepare("
            SELECT u.name, u.email, {$roleCol} 
                   COUNT(CASE WHEN br.borrow_date BETWEEN ? AND ? THEN br.id END) as borrow_count,
                   SUM(CASE WHEN br.status = 'borrowing' THEN 1 ELSE 0 END) as active_loans
            FROM users u
            LEFT JOIN borrows br ON u.id = br.user_id
            WHERE u.role != 'admin'
            GROUP BY u.id
            HAVING borrow_count > 0
            ORDER BY borrow_count DESC
            LIMIT ?
        ");
        $stmt->execute([$startDate, $endDate, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: รายงานรายได้รายวัน (ยอดชำระค่าปรับต่อวัน)
     * ==========================================================================
     *
     * 📥 Input:
     * @param string $startDate Y-m-d
     * @param string $endDate   Y-m-d
     *
     * 📤 Output: @return array[] [{payment_day, transaction_count, total_amount}, ...]
     * ✅ Use case: admin/reports.php → รายงานรายได้
     */
    public function getDailyRevenueReport(string $startDate, string $endDate): array
    {
        // 🔄 Flow: admin/reports.php → ReportService → getDailyRevenueReport()
        // 🎯 ดึงยอดชำระค่าปรับรายวัน สำหรับรายงานรายได้
        //
        // 📝 SQL อธิบาย:
        //    - ดึงจากตาราง payments (ไม่ใช่ borrows)
        //    - DATE(created_at) → จัดกลุ่มตามวันที่ชำระ
        //    - COUNT(id) → จำนวน transaction ต่อวัน
        //    - SUM(amount) → ยอดรวมต่อวัน
        //    - WHERE BETWEEN ? AND ? → กรองช่วงวันที่ [startDate, endDate]
        //    - ORDER BY created_at DESC → เรียงจากวันล่าสุดก่อน
        //
        // 📤 Output: [{payment_day: "2025-01-15", transaction_count: 3, total_amount: 150.00}, ...]
        // ⚠️ ถ้าวันไหนไม่มีการชำระ → วันนั้นจะไม่ปรากฏ (ไม่ใช่ค่า 0)
        $stmt = $this->pdo->prepare("
            SELECT DATE_FORMAT(created_at, '%d/%m/%Y') as payment_day, COUNT(id) as transaction_count, SUM(amount) as total_amount
            FROM payments
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY created_at DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: รายงานหนังสือเกินกำหนด (overdue list)
     * ==========================================================================
     * ดึงเฉพาะรายการที่ status='borrowing' และ due_date < วันนี้
     *
     * 📥 Input:
     * @param bool $formatDate true = d/m/Y (สำหรับ PDF), false = Y-m-d
     *
     * 📤 Output:
     * @return array[] [{name, phone, title, borrow_date, due_date, days_overdue}, ...]
     *
     * 🧠 เหตุผล: $formatDate เปลี่ยน format ใน SQL เลย (ไม่ต้อง format ใน PHP)
     * ✅ Use case: admin/reports.php, export_pdf.php (รายงานหนังสือเกินกำหนด)
     */
    public function getOverdueReport(): array
    {
        // 🔄 Flow: admin/reports.php + export_pdf.php → ReportService
        // 🎯 ดึงรายการหนังสือที่เกินกำหนดคืน (overdue) ทั้งหมด
        //
        // 📝 จัดรูปแบบวันที่ใน SQL เป็น dd/mm/yyyy เสมอ — เหมือน getBorrowsReport
        //    และ getUnpaidFinesReport เพื่อให้ทุกช่องทาง (หน้าเว็บ / CSV / PDF) ตรงกัน
        //    🔴 [FIX] เดิมผูกกับ $formatDate ทำให้หน้าเว็บโชว์ 2026-06-21
        //       แต่ PDF โชว์ 21/06/2026 — ทั้งระบบใช้ d/m/Y เป็นมาตรฐาน (ดู formatDate())
        $borrowDateCol = "DATE_FORMAT(b.borrow_date, '%d/%m/%Y') as borrow_date";
        $dueDateCol = "DATE_FORMAT(b.due_date, '%d/%m/%Y') as due_date";
        
        // 📝 SQL อธิบาย:
        //    - JOIN users + books → ดึงชื่อสมาชิก + เบอร์โทร + ชื่อหนังสือ
        //      (ใช้ INNER JOIN ไม่ใช่ LEFT → ถ้า user/book ถูกลบ → ไม่แสดง)
        //    - WHERE status = 'borrowing' AND due_date < CURDATE()
        //      → เฉพาะที่ยังไม่คืน + เลยกำหนดแล้ว
        //    - DATEDIFF(CURDATE(), b.due_date) → คำนวณจำนวนวันที่เกิน
        //    - ORDER BY b.due_date ASC → เกินนานสุดขึ้นก่อน (ด่วนสุดอยู่บน)
        //
        // 📤 Output: [{name, phone, title, borrow_date, due_date, days_overdue}, ...]
        // ⚠️ phone อยู่ในผลลัพธ์ → ข้อมูลส่วนบุคคล ต้อง escape ก่อนแสดงผล
        //    PDF ที่พิมพ์ออกมามีเบอร์โทร → ระวังเรื่อง privacy ถ้าเป็น production
        // 📝 ใช้ query() เพราะไม่มี user input (parameter ทั้งหมดมาจาก code ภายใน)
        return $this->pdo->query("
            SELECT u.name, u.phone, bk.title, {$borrowDateCol}, {$dueDateCol},
                   DATEDIFF(CURDATE(), b.due_date) as days_overdue
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            WHERE b.status = 'borrowing' AND b.due_date < CURDATE()
            ORDER BY b.due_date ASC
        ")->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: รายงานการยืมตามช่วงวันที่ (สำหรับ PDF export)
     * ==========================================================================
     *
     * 📥 Input:
     * @param string $dateFrom Y-m-d
     * @param string $dateTo   Y-m-d
     *
     * 📤 Output:
     * @return array[] [{name, title, borrow_date(dd/mm/yyyy), due_date, status_text, fine}, ...]
     *
     * 🧠 เหตุผล:
     * - DATE_FORMAT สำหรับแสดงใน PDF (ไทย format)
     * - CASE status แปลงเป็นภาษาไทย (คืนแล้ว / กำลังยืม)
     * - COALESCE(fine_amount, 0) ป้องกัน NULL
     * ✅ Use case: export_pdf.php → พิมพ์รายงานการยืม
     */
    public function getBorrowsReport(string $dateFrom, string $dateTo): array
    {
        // 🔄 Flow: admin/export_pdf.php → getBorrowsReport()
        // 🎯 ดึงรายการยืมทั้งหมดในช่วงวันที่ สำหรับพิมพ์รายงาน PDF
        //
        // 📝 SQL อธิบาย:
        //    - JOIN users + books → ดึงชื่อสมาชิก + ชื่อหนังสือ
        //    - DATE_FORMAT('%d/%m/%Y') → แปลงวันที่เป็นรูปแบบไทย (วัน/เดือน/ปี)
        //    - CASE b.status → แปลง status เป็นภาษาไทย ('returned' → 'คืนแล้ว')
        //    - COALESCE(b.fine_amount, 0) → ถ้า fine_amount เป็น NULL → แสดง 0
        //    - WHERE borrow_date BETWEEN ? AND ? → กรองช่วงวันที่
        //      ? ตัวแรก = $dateFrom, ? ตัวที่ 2 = $dateTo
        //    - ORDER BY borrow_date DESC → ยืมล่าสุดขึ้นก่อน
        //
        // 📤 Output: [{name, title, borrow_date, due_date, status_text, fine}, ...]
        $stmt = $this->pdo->prepare("
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
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: รายงานสมาชิกค้างชำระค่าปรับ (สำหรับ PDF + reports)
     * ==========================================================================
     *
     * 📥 Input:
     * @param string|null $startDate Y-m-d (null = ไม่จำกัดวันเริ่ม)
     * @param string|null $endDate   Y-m-d (null = ไม่จำกัดวันสิ้นสุด)
     *
     * 📤 Output:
     * @return array[] [{user_name, user_phone, book_title, return_date, fine_amount}, ...]
     *
     * 🧠 เหตุผล:
     * - กรอง: status IN ('returned','lost','damaged') + fine_amount > 0 + NOT IN payments
     * - NOT IN (SELECT borrow_id FROM payments) = ยังไม่ได้ชำระ
     * - date filter เป็น optional (null = ไม่กรอง)
     * ✅ Use case: admin/reports.php, export_pdf.php (รายงานค้างชำระ)
     */
    public function getUnpaidFinesReport(?string $startDate = null, ?string $endDate = null): array
    {
        // 🔄 Flow: admin/reports.php + export_pdf.php → ReportService
        // 🎯 ดึงรายการค่าปรับค้างชำระ สำหรับรายงานและ PDF
        //
        // 📥 Input: $startDate, $endDate → กรองช่วงวันที่ (optional, null = ไม่กรอง)

        // 📝 สร้าง date filter แบบ dynamic
        //    ถ้ามี date range → เพิ่ม AND DATE(COALESCE(return_date, lost_reported_at)) BETWEEN ? AND ?
        //    ถ้าไม่มี → $dateFilter = '' (ดึงทั้งหมด)
        $dateFilter = '';
        $params = [];
        
        if ($startDate && $endDate) {
            // 🧠 หนังสือที่แจ้งหายไม่มี return_date (ไม่ได้ถูกคืน) → ใช้ lost_reported_at แทน
            //    ถ้าใช้ return_date เฉย ๆ รายการหายจะหลุดทันทีที่เลือกช่วงวันที่
            $dateFilter = 'AND DATE(COALESCE(b.return_date, b.lost_reported_at)) BETWEEN ? AND ?';
            $params = [$startDate, $endDate];
        }
        
        // 📝 SQL อธิบาย:
        //    - WHERE status IN ('returned','lost','damaged') → รายการที่ปิดแล้วเท่านั้น
        //      🔴 ต้องมี lost/damaged ด้วย ไม่งั้นค่าชดใช้หนังสือหายจะโผล่ใน 5 หน้า
        //         แต่หายไปจากรายงานนี้หน้าเดียว — ระบบมี 6 query ที่นิยาม "ค้างชำระ"
        //         และนี่เป็นตัวเดียวที่กรอง status (อาการเดียวกับ F-35)
        //    - AND fine_amount > 0 → เฉพาะที่มีค่าปรับ
        //    - AND b.id NOT IN (SELECT borrow_id FROM payments)
        //      → เฉพาะที่ยังไม่มี record การชำระ
        //      ⚠️ Danger Zone #2: NOT IN subquery
        //         - ถ้าตาราง payments มีข้อมูลมาก → query อาจช้า
        //         - production ขนาดใหญ่ → ควรเปลี่ยนเป็น LEFT JOIN + IS NULL
        //         - template ขนาดเล็ก → ไม่มีปัญหา
        //    - {$dateFilter} → ต่อ SQL string (แต่ค่ามาจาก code ภายใน → ปลอดภัย)
        //    - ORDER BY fine_amount DESC → ค้างมากสุดขึ้นก่อน
        //
        // 📤 Output: [{user_name, user_phone, book_title, return_date, fine_amount}, ...]
        // ⚠️ user_phone อยู่ในผลลัพธ์ → ข้อมูลส่วนบุคคล ระวังเรื่อง privacy
        $stmt = $this->pdo->prepare("
            SELECT u.name as user_name, u.phone as user_phone,
                   bk.title as book_title,
                   -- 🧠 หนังสือที่แจ้งหายไม่มี return_date → แสดงวันที่แจ้งแทน ไม่งั้นช่องนี้ว่าง
                   DATE_FORMAT(COALESCE(b.return_date, b.lost_reported_at), '%d/%m/%Y') as return_date,
                   b.status,
                   b.fine_amount
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            WHERE b.status IN ('returned', 'lost', 'damaged')
              AND b.fine_amount > 0
              AND b.id NOT IN (SELECT borrow_id FROM payments)
              AND b.fine_waived_at IS NULL   -- 💸 ยกเว้นแล้วไม่นับเป็นค้างชำระอีก (ROADMAP ข้อ 2)
              {$dateFilter}
            ORDER BY b.fine_amount DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

