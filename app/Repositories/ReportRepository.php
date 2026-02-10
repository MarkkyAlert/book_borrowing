<?php
/**
 * ReportRepository - Database Access สำหรับรายงานและสถิติ
 * 
 * @package App\Repositories
 */

namespace App\Repositories;

use PDO;

class ReportRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // NOTE: getBookStats() removed - use BookRepository::getStatistics()
    // NOTE: getMemberStats() removed - use UserRepository::countMembers() + countNewThisMonth()

    /**
     * สถิติการยืมภาพรวม (สำหรับ admin dashboard)
     * 
     * @return array {
     *     active: int,      // จำนวนที่กำลังยืมอยู่
     *     overdue: int,     // จำนวนที่เกินกำหนด
     *     today: int,       // จำนวนที่ยืมวันนี้
     *     this_month: int   // จำนวนที่ยืมเดือนนี้
     * }
     */
    public function getBorrowStats(): array
    {
        return [
            'active' => (int) $this->pdo->query("SELECT COUNT(*) FROM borrows WHERE status = 'borrowing'")->fetchColumn(),
            'overdue' => (int) $this->pdo->query("
                SELECT COUNT(*) FROM borrows WHERE status = 'borrowing' AND due_date < CURDATE()
            ")->fetchColumn(),
            'today' => (int) $this->pdo->query("
                SELECT COUNT(*) FROM borrows WHERE DATE(borrow_date) = CURDATE()
            ")->fetchColumn(),
            'this_month' => (int) $this->pdo->query("
                SELECT COUNT(*) FROM borrows 
                WHERE MONTH(borrow_date) = MONTH(CURDATE()) AND YEAR(borrow_date) = YEAR(CURDATE())
            ")->fetchColumn(),
        ];
    }

    /**
     * สถิติค่าปรับภาพรวม (สำหรับ admin dashboard)
     * 
     * @return array {
     *     total: float,      // ค่าปรับทั้งหมดตลอดกาล
     *     unpaid: float,     // ค่าปรับค้างชำระ (ยังไม่มี payment record)
     *     this_month: float  // ค่าปรับที่เกิดเดือนนี้ (ตาม return_date)
     * }
     */
    public function getFineStats(): array
    {
        return [
            'total' => (float) $this->pdo->query("SELECT COALESCE(SUM(fine_amount), 0) FROM borrows")->fetchColumn(),
            'unpaid' => (float) $this->pdo->query("
                SELECT COALESCE(SUM(b.fine_amount), 0) 
                FROM borrows b
                LEFT JOIN payments p ON b.id = p.borrow_id
                WHERE b.fine_amount > 0 AND p.id IS NULL
            ")->fetchColumn(),
            'this_month' => (float) $this->pdo->query("
                SELECT COALESCE(SUM(fine_amount), 0) FROM borrows 
                WHERE MONTH(return_date) = MONTH(CURDATE()) AND YEAR(return_date) = YEAR(CURDATE())
            ")->fetchColumn(),
        ];
    }

    /**
     * รายงานการยืมรายเดือน (สำหรับ chart)
     * 
     * @param int $months จำนวนเดือนย้อนหลัง (default: 6)
     * @return array[] แต่ละ element: {
     *     month: string,         // 'YYYY-MM'
     *     month_name: string,    // 'Jan', 'Feb', ...
     *     total_borrows: int,    // จำนวนยืมทั้งหมดในเดือน
     *     returned: int          // จำนวนที่คืนแล้ว
     * }
     */
    public function getMonthlyReport(int $months = 6): array
    {
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
        $stmt->execute([$months]);
        return $stmt->fetchAll();
    }

    /**
     * รายงานหมวดหมู่ยอดนิยม (สำหรับ pie chart)
     * 
     * @param int $limit จำนวนหมวดหมู่สูงสุด (default: 6)
     * @return array[] แต่ละ element: { name: string, borrow_count: int }
     */
    public function getCategoryDistribution(int $limit = 6): array
    {
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
     * รายงานหมวดหมู่ทั้งหมดพร้อมสถิติ (สำหรับตารางสรุป)
     * 
     * @return array[] แต่ละ element: { name: string, book_count: int, borrow_count: int }
     */
    public function getAllCategoriesWithStats(): array
    {
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
     * หนังสือยอดนิยม (เรียงตามจำนวนครั้งที่ถูกยืม)
     * 
     * @param int $limit จำนวนรายการสูงสุด (default: 10)
     * @return array[] แต่ละ element: { id: int, title: string, author: string, borrow_count: int }
     */
    public function getPopularBooks(int $limit = 10): array
    {
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
     * สมาชิกที่ยืมมากที่สุด (เรียงตามจำนวนครั้ง)
     * 
     * @param int $limit จำนวนรายการสูงสุด (default: 10)
     * @return array[] แต่ละ element: { id: int, name: string, email: string, borrow_count: int }
     */
    public function getTopBorrowers(int $limit = 10): array
    {
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
     * รายงานรายวัน (สรุปกิจกรรมของวันที่ระบุ)
     * 
     * @param string|null $date วันที่ (Y-m-d) หรือ null = วันนี้
     * @return array {
     *     date: string,         // วันที่ที่ query
     *     borrows: int,        // จำนวนยืมในวันนั้น
     *     returns: int,        // จำนวนคืนในวันนั้น
     *     new_members: int     // สมาชิกใหม่ในวันนั้น
     * }
     */
    public function getDailyReport(?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');

        $stmtBorrows = $this->pdo->prepare("SELECT COUNT(*) FROM borrows WHERE DATE(borrow_date) = ?");
        $stmtBorrows->execute([$date]);
        $borrows = (int) $stmtBorrows->fetchColumn();

        $stmtReturns = $this->pdo->prepare("SELECT COUNT(*) FROM borrows WHERE DATE(return_date) = ?");
        $stmtReturns->execute([$date]);
        $returns = (int) $stmtReturns->fetchColumn();

        $stmtMembers = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at) = ? AND role = 'member'");
        $stmtMembers->execute([$date]);
        $newMembers = (int) $stmtMembers->fetchColumn();

        return [
            'date' => $date,
            'borrows' => $borrows,
            'returns' => $returns,
            'new_members' => $newMembers,
        ];
    }

    /**
     * รายงานหนังสือยอดนิยม (สำหรับหน้า reports)
     * 
     * @param int $limit จำนวนรายการสูงสุด
     * @param string|null $startDate วันเริ่มต้น (Y-m-d)
     * @param string|null $endDate วันสิ้นสุด (Y-m-d)
     */
    public function getTopBooksReport(int $limit = 50, ?string $startDate = null, ?string $endDate = null): array
    {
        // ถ้าไม่มีวันที่ ใช้ช่วง 1 ปีย้อนหลัง
        if (!$startDate || !$endDate) {
            $endDate = date('Y-m-d');
            $startDate = date('Y-m-d', strtotime('-1 year'));
        }
        
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
     * รายงานสมาชิกที่ใช้บริการบ่อย
     * 
     * @param int $limit จำนวนรายการสูงสุด
     * @param bool $translateRole true = แปลง role เป็นภาษาไทย (สำหรับ PDF)
     * @param string|null $startDate วันเริ่มต้น (Y-m-d)
     * @param string|null $endDate วันสิ้นสุด (Y-m-d)
     */
    public function getTopMembersReport(int $limit = 50, bool $translateRole = false, ?string $startDate = null, ?string $endDate = null): array
    {
        // ถ้าไม่มีวันที่ ใช้ช่วง 1 ปีย้อนหลัง
        if (!$startDate || !$endDate) {
            $endDate = date('Y-m-d');
            $startDate = date('Y-m-d', strtotime('-1 year'));
        }
        
        $roleCol = $translateRole 
            ? "CASE u.role WHEN 'staff' THEN 'เจ้าหน้าที่' ELSE 'สมาชิก' END as role_name,"
            : "u.role,";
        
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
     * รายงานรายได้รายวัน (ยอดค่าปรับที่รับชำระ)
     * 
     * @param string $startDate วันเริ่มต้น (Y-m-d)
     * @param string $endDate   วันสิ้นสุด (Y-m-d)
     * @return array[] แต่ละ element: {
     *     payment_day: string,       // วันที่ (Y-m-d)
     *     transaction_count: int,    // จำนวน transactions
     *     total_amount: float        // ยอดรวม (บาท)
     * }
     */
    public function getDailyRevenueReport(string $startDate, string $endDate): array
    {
        $stmt = $this->pdo->prepare("
            SELECT DATE(created_at) as payment_day, COUNT(id) as transaction_count, SUM(amount) as total_amount
            FROM payments
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY created_at DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll();
    }

    /**
     * รายงานหนังสือเกินกำหนด (รายการที่ status=borrowing และเลยกำหนดคืน)
     * 
     * @param bool $formatDate true = format วันที่เป็น d/m/Y (สำหรับ PDF export)
     * @return array[] แต่ละ element: {
     *     name: string,          // ชื่อผู้ยืม
     *     phone: string,        // เบอร์โทร
     *     title: string,        // ชื่อหนังสือ
     *     borrow_date: string,  // วันยืม
     *     due_date: string,     // วันกำหนดคืน
     *     days_overdue: int     // จำนวนวันที่เกิน
     * }
     */
    public function getOverdueReport(bool $formatDate = false): array
    {
        $borrowDateCol = $formatDate 
            ? "DATE_FORMAT(b.borrow_date, '%d/%m/%Y') as borrow_date"
            : "b.borrow_date";
        $dueDateCol = $formatDate 
            ? "DATE_FORMAT(b.due_date, '%d/%m/%Y') as due_date"
            : "b.due_date";
        
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
     * รายงานการยืมตามช่วงวันที่ (สำหรับ PDF export)
     * 
     * @param string $dateFrom วันเริ่มต้น (Y-m-d)
     * @param string $dateTo   วันสิ้นสุด (Y-m-d)
     * @return array[] แต่ละ element: {
     *     name: string,           // ชื่อผู้ยืม
     *     title: string,         // ชื่อหนังสือ
     *     borrow_date: string,   // วันยืม (dd/mm/yyyy)
     *     due_date: string,      // กำหนดคืน (dd/mm/yyyy)
     *     status_text: string,   // 'คืนแล้ว' | 'กำลังยืม'
     *     fine: float            // ค่าปรับ (0 ถ้าไม่มี)
     * }
     */
    public function getBorrowsReport(string $dateFrom, string $dateTo): array
    {
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
     * รายงานสมาชิกค้างชำระค่าปรับ (สำหรับ PDF export และหน้ารายงาน)
     * 
     * @param string|null $startDate วันเริ่มต้น (Y-m-d) หรือ null = ไม่จำกัด
     * @param string|null $endDate   วันสิ้นสุด (Y-m-d) หรือ null = ไม่จำกัด
     * @return array[] แต่ละ element: {
     *     user_name: string,     // ชื่อสมาชิก
     *     user_phone: string,   // เบอร์โทร
     *     book_title: string,   // ชื่อหนังสือ
     *     return_date: string,  // วันคืน (dd/mm/yyyy)
     *     fine_amount: float    // ค่าปรับค้างชำระ (บาท)
     * }
     * 
     * @note กรองเฉพาะ returned + fine > 0 + ไม่มี payment record
     */
    public function getUnpaidFinesReport(?string $startDate = null, ?string $endDate = null): array
    {
        $dateFilter = '';
        $params = [];
        
        if ($startDate && $endDate) {
            $dateFilter = 'AND b.return_date BETWEEN ? AND ?';
            $params = [$startDate, $endDate];
        }
        
        $stmt = $this->pdo->prepare("
            SELECT u.name as user_name, u.phone as user_phone,
                   bk.title as book_title,
                   DATE_FORMAT(b.return_date, '%d/%m/%Y') as return_date,
                   b.fine_amount
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            WHERE b.status = 'returned' 
              AND b.fine_amount > 0
              AND b.id NOT IN (SELECT borrow_id FROM payments)
              {$dateFilter}
            ORDER BY b.fine_amount DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

