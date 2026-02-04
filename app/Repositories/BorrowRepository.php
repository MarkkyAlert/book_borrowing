<?php
/**
 * BorrowRepository - Database Access สำหรับการยืม-คืน
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - ไฟล์นี้จัดการ SQL queries สำหรับตาราง borrows
 * - ห้ามเรียกจากหน้าเว็บโดยตรง ให้เรียกผ่าน BorrowService
 * - ทุก method ใช้ prepared statements (ป้องกัน SQL Injection)
 * 
 * 📌 Methods สำคัญ:
 * - create()            → INSERT borrow record
 * - markAsReturned()    → UPDATE status='returned'
 * - findByIdForUpdate() → SELECT ... FOR UPDATE (ป้องกัน race condition)
 * 
 * ⚠️ ห้ามแก้:
 * - findByIdForUpdate() - มี FOR UPDATE lock ที่สำคัญ
 * - countActiveBorrowsForUpdate() - ป้องกันยืมเกินโควต้า
 * 
 * @package App\Repositories
 */

namespace App\Repositories;

use PDO;

class BorrowRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ดึงรายการยืมทั้งหมด พร้อม user_name, book_title
     * 
     * @param array $filters { search?: string, status?: string, user_id?: int, overdue?: bool, due_today?: bool }
     * @return array รายการยืมพร้อมข้อมูล user และ book
     */
    public function findAll(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $where[] = "(u.name LIKE ? OR u.email LIKE ? OR bk.title LIKE ?)";
            $params = array_merge($params, ["%{$search}%", "%{$search}%", "%{$search}%"]);
        }

        if (!empty($filters['status'])) {
            $where[] = "b.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['user_id'])) {
            $where[] = "b.user_id = ?";
            $params[] = $filters['user_id'];
        }

        if (isset($filters['overdue']) && $filters['overdue']) {
            $where[] = "b.status = 'borrowing' AND b.due_date < CURDATE()";
        }
        
        if (isset($filters['due_today']) && $filters['due_today']) {
            $where[] = "b.status = 'borrowing' AND b.due_date = CURDATE()";
        }

        $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->pdo->prepare("
            SELECT b.*, u.name as user_name, u.email as user_email, u.phone as user_phone,
                   bk.title as book_title, bk.author as book_author
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            {$whereSQL}
            ORDER BY b.created_at DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * ดึงรายการยืมตาม ID พร้อม user_name, book_title
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.*, u.name as user_name, bk.title as book_title
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            WHERE b.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Lock row สำหรับ transaction (เฉพาะ status='borrowing')
     * 
     * [CONCURRENCY] FOR UPDATE ป้องกัน:
     * - คืนหนังสือซ้ำ (กดปุ่มคืน 2 ครั้ง)
     * - Race condition ระหว่าง staff 2 คน
     * 
     * @note ต้องเรียกภายใน transaction เท่านั้น
     * @note ใช้สำหรับ returnBook() - filter เฉพาะ borrowing
     */
    public function findByIdForUpdate(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM borrows WHERE id = ? AND status = 'borrowing' FOR UPDATE
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Lock row สำหรับ transaction (ทุก status)
     * 
     * [CONCURRENCY] FOR UPDATE ป้องกัน race condition
     * 
     * @note ต้องเรียกภายใน transaction เท่านั้น
     * @note ใช้สำหรับ payFine() - ต้องการหา borrow ที่ returned แล้ว
     */
    public function findByIdForUpdateAnyStatus(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM borrows WHERE id = ? FOR UPDATE
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * สร้างรายการยืม
     * 
     * @param array $data { user_id: int, book_id: int, borrow_date: string, due_date: string }
     * @return int ID ของรายการที่สร้าง
     * @sideeffect INSERT ลง borrows table (status = 'borrowing')
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status)
            VALUES (?, ?, ?, ?, 'borrowing')
        ");

        $stmt->execute([
            $data['user_id'],
            $data['book_id'],
            $data['borrow_date'],
            $data['due_date']
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * อัปเดตเป็นคืนแล้ว
     * 
     * @sideeffect UPDATE borrows: status='returned', return_date, fine_amount
     */
    public function markAsReturned(int $id, float $fineAmount): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE borrows 
            SET status = 'returned', return_date = CURDATE(), fine_amount = ? 
            WHERE id = ?
        ");
        return $stmt->execute([$fineAmount, $id]);
    }

    /**
     * นับจำนวนการยืมที่ active ของ user
     */
    public function countActiveBorrows(int $userId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM borrows 
            WHERE user_id = ? AND status = 'borrowing'
        ");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * นับจำนวนการยืมที่ active ของ user (with lock)
     * 
     * @security FOR UPDATE ล็อคแถวที่ match ป้องกัน race condition:
     *           2 requests พร้อมกันอาจทำให้ยืมเกินโควต้า (MAX_BORROW_BOOKS)
     *           ต้องเรียกภายใน transaction เท่านั้น
     */
    public function countActiveBorrowsForUpdate(int $userId): int
    {
        // [LOCK] ล็อคแถว borrows ของ user นี้ - ป้องกันยืมเกินโควต้า
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM borrows 
            WHERE user_id = ? AND status = 'borrowing' FOR UPDATE
        ");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ตรวจสอบว่า user ยืมหนังสือเล่มนี้อยู่หรือไม่
     */
    public function isAlreadyBorrowing(int $userId, int $bookId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM borrows 
            WHERE user_id = ? AND book_id = ? AND status = 'borrowing'
        ");
        $stmt->execute([$userId, $bookId]);
        return $stmt->fetch() !== false;
    }

    /**
     * ดึงรายการเกินกำหนด
     */
    public function findOverdue(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.*, u.name as user_name, u.phone, bk.title as book_title
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            WHERE b.status = 'borrowing' AND b.due_date < CURDATE()
            ORDER BY b.due_date ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * ดึงรายการยืมล่าสุด
     */
    public function findRecent(int $limit = 5): array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.*, u.name as user_name, bk.title as book_title
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            ORDER BY b.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * ดึงประวัติการยืมของ user
     */
    public function findByUserId(int $userId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.*, bk.title as book_title, bk.author as book_author
            FROM borrows b
            JOIN books bk ON b.book_id = bk.id
            WHERE b.user_id = ?
            ORDER BY b.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * ดึงประวัติการยืมของ user พร้อม pagination และ filter ตาม status
     * 
     * @param int $userId ID ของ user
     * @param string|null $status สถานะ (borrowing/returned) หรือ null = ทั้งหมด
     * @param int $page หน้าที่ต้องการ (1-indexed)
     * @param int $perPage จำนวนต่อหน้า
     * @return array ['data' => array, 'total' => int, 'page' => int, 'per_page' => int, 'total_pages' => int]
     */
    public function findByUserIdPaginated(int $userId, ?string $status = null, int $page = 1, int $perPage = 10): array
    {
        $where = "b.user_id = ?";
        $params = [$userId];
        
        if ($status !== null) {
            $where .= " AND b.status = ?";
            $params[] = $status;
        }
        
        // Count total
        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM borrows b WHERE $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        
        // Calculate offset
        $offset = ($page - 1) * $perPage;
        $params[] = $perPage;
        $params[] = $offset;
        
        $stmt = $this->pdo->prepare("
            SELECT b.*, bk.title as book_title, bk.author as book_author
            FROM borrows b
            JOIN books bk ON b.book_id = bk.id
            WHERE $where
            ORDER BY b.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        
        return [
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ];
    }
    
    /**
     * ดึงรายการยืมปัจจุบันของหนังสือ (ยังไม่คืน)
     * 
     * @param int $bookId ID หนังสือ
     * @return array รายการ borrow พร้อมชื่อ borrower
     */
    public function findCurrentByBook(int $bookId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.*, u.name as borrower_name
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            WHERE b.book_id = ? AND b.status = 'borrowing'
            ORDER BY b.created_at DESC
        ");
        $stmt->execute([$bookId]);
        return $stmt->fetchAll();
    }
    
    /**
     * ดึงประวัติการยืมของหนังสือ
     * 
     * @param int $bookId ID หนังสือ
     * @param int $limit  จำนวนรายการ
     * @return array รายการ borrow พร้อมชื่อ borrower
     */
    public function findHistoryByBook(int $bookId, int $limit = 5): array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.*, u.name as borrower_name
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            WHERE b.book_id = ?
            ORDER BY b.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$bookId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * นับจำนวนรายการที่เกินกำหนดคืน (status='borrowing' AND due_date < today)
     */
    public function countOverdue(): int
    {
        return (int) $this->pdo->query("
            SELECT COUNT(*) FROM borrows 
            WHERE status = 'borrowing' AND due_date < CURDATE()
        ")->fetchColumn();
    }

    /**
     * นับจำนวนการยืมที่ active ทั้งหมด
     */
    public function countActive(): int
    {
        return (int) $this->pdo->query("
            SELECT COUNT(*) FROM borrows WHERE status = 'borrowing'
        ")->fetchColumn();
    }

    // NOTE: getMonthlyStatistics() removed - use ReportRepository::getMonthlyReport()
    // เหตุผล: ReportRepository เป็น owner ของ report logic

    /**
     * นับจำนวนการยืมของหนังสือ
     */
    public function countByBook(int $bookId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM borrows WHERE book_id = ?");
        $stmt->execute([$bookId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * นับจำนวนการยืมทั้งหมดของ user
     */
    public function countByUser(int $userId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM borrows WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ดึงสถิติการยืมของ user
     */
    public function getStatsByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_borrows,
                SUM(CASE WHEN status = 'borrowing' THEN 1 ELSE 0 END) as active_borrows,
                SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned,
                COALESCE(SUM(fine_amount), 0) as total_fines
            FROM borrows
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }

    /**
     * นับการยืมที่ active ของหนังสือ
     */
    public function countActiveByBook(int $bookId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM borrows WHERE book_id = ? AND status = 'borrowing'");
        $stmt->execute([$bookId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ดึงรายการยืมที่มีค่าปรับค้างชำระ (fine_amount > 0 และยังไม่มี payment)
     * 
     * @param int $limit จำนวนรายการที่ต้องการ
     * @return array รายการยืมพร้อมข้อมูล user และ book
     */
    public function getUnpaidFinesList(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.id, b.fine_amount, b.borrow_date, b.due_date, b.return_date,
                   u.id as user_id, u.name as user_name, u.email as user_email, u.phone as user_phone,
                   bk.id as book_id, bk.title as book_title
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            LEFT JOIN payments p ON b.id = p.borrow_id
            WHERE b.fine_amount > 0 AND p.id IS NULL
            ORDER BY b.return_date DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * นับยอดค่าปรับค้างชำระทั้งหมด
     */
    public function getTotalUnpaidFines(): float
    {
        return (float) $this->pdo->query("
            SELECT COALESCE(SUM(b.fine_amount), 0) 
            FROM borrows b
            LEFT JOIN payments p ON b.id = p.borrow_id
            WHERE b.fine_amount > 0 AND p.id IS NULL
        ")->fetchColumn();
    }

    /**
     * ดึงรายการค่าปรับค้างชำระของ user
     */
    public function getUnpaidFinesByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.id, b.fine_amount, b.borrow_date, b.due_date, b.return_date,
                   bk.title as book_title
            FROM borrows b
            JOIN books bk ON b.book_id = bk.id
            LEFT JOIN payments p ON b.id = p.borrow_id
            WHERE b.user_id = ? AND b.fine_amount > 0 AND p.id IS NULL
            ORDER BY b.return_date DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
}

