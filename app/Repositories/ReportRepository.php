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

    /**
     * สถิติหนังสือ
     */
    public function getBookStats(): array
    {
        $total = (int) $this->pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM books")->fetchColumn();
        $available = (int) $this->pdo->query("SELECT COALESCE(SUM(available), 0) FROM books")->fetchColumn();

        return [
            'total' => $total,
            'available' => $available,
            'borrowed' => $total - $available,
            'titles' => (int) $this->pdo->query("SELECT COUNT(*) FROM books")->fetchColumn(),
        ];
    }

    /**
     * สถิติสมาชิก
     */
    public function getMemberStats(): array
    {
        return [
            'total' => (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn(),
            'new_this_month' => (int) $this->pdo->query("
                SELECT COUNT(*) FROM users 
                WHERE role = 'member' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())
            ")->fetchColumn(),
        ];
    }

    /**
     * สถิติการยืม
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
     * สถิติค่าปรับ
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
     * รายงานการยืมรายเดือน
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
     * รายงานหมวดหมู่ยอดนิยม
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
     * หนังสือยอดนิยม
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
     * สมาชิกที่ยืมมากที่สุด
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
     * รายงานรายวัน
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
     */
    public function getTopBooksReport(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.title, c.name as category, COUNT(br.id) as borrow_count,
                   (b.quantity - b.available) as currently_borrowed
            FROM books b
            LEFT JOIN categories c ON b.category_id = c.id
            LEFT JOIN borrows br ON b.id = br.book_id
            GROUP BY b.id
            ORDER BY borrow_count DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * รายงานสมาชิกที่ใช้บริการบ่อย (สำหรับหน้า reports)
     */
    public function getTopMembersReport(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare("
            SELECT u.name, u.email, u.role, COUNT(br.id) as borrow_count,
                   SUM(CASE WHEN br.status = 'borrowing' THEN 1 ELSE 0 END) as active_loans
            FROM users u
            JOIN borrows br ON u.id = br.user_id
            WHERE u.role != 'admin'
            GROUP BY u.id
            ORDER BY borrow_count DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * รายงานรายได้รายวัน (สำหรับหน้า reports)
     */
    public function getDailyRevenueReport(int $limit = 30): array
    {
        $stmt = $this->pdo->prepare("
            SELECT DATE(payment_date) as payment_day, COUNT(id) as transaction_count, SUM(amount) as total_amount
            FROM payments
            GROUP BY DATE(payment_date)
            ORDER BY payment_day DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * รายงานหนังสือเกินกำหนด (สำหรับหน้า reports)
     */
    public function getOverdueReport(): array
    {
        return $this->pdo->query("
            SELECT u.name, u.phone, bk.title, b.borrow_date, b.due_date,
                   DATEDIFF(CURDATE(), b.due_date) as days_overdue
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            WHERE b.status = 'borrowing' AND b.due_date < CURDATE()
            ORDER BY b.due_date ASC
        ")->fetchAll();
    }

    /**
     * รายงานการยืมตามช่วงวันที่ (สำหรับ export_pdf)
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
     * รายงานหนังสือยอดนิยม (สำหรับ PDF - รวม role_name)
     */
    public function getTopMembersReportForPdf(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare("
            SELECT u.name, u.email, 
                   CASE u.role WHEN 'staff' THEN 'เจ้าหน้าที่' ELSE 'สมาชิก' END as role_name,
                   COUNT(br.id) as borrow_count,
                   SUM(CASE WHEN br.status = 'borrowing' THEN 1 ELSE 0 END) as active_loans
            FROM users u
            JOIN borrows br ON u.id = br.user_id
            WHERE u.role != 'admin'
            GROUP BY u.id
            ORDER BY borrow_count DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * รายงานรายได้รายวัน (สำหรับ PDF - format วันที่)
     */
    public function getDailyRevenueReportForPdf(int $limit = 30): array
    {
        $stmt = $this->pdo->prepare("
            SELECT DATE_FORMAT(payment_date, '%d/%m/%Y') as payment_day, 
                   COUNT(id) as transaction_count, 
                   SUM(amount) as total_amount
            FROM payments
            GROUP BY DATE(payment_date)
            ORDER BY payment_date DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * รายงานหนังสือเกินกำหนด (สำหรับ PDF - format วันที่)
     */
    public function getOverdueReportForPdf(): array
    {
        return $this->pdo->query("
            SELECT u.name, u.phone, bk.title, 
                   DATE_FORMAT(b.borrow_date, '%d/%m/%Y') as borrow_date,
                   DATE_FORMAT(b.due_date, '%d/%m/%Y') as due_date,
                   DATEDIFF(CURDATE(), b.due_date) as days_overdue
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            WHERE b.status = 'borrowing' AND b.due_date < CURDATE()
            ORDER BY b.due_date ASC
        ")->fetchAll();
    }
}

