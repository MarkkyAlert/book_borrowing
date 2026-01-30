<?php
/**
 * BorrowRepository - Database Access สำหรับการยืม-คืน
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
     * ดึงรายการยืมทั้งหมด
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
     * ดึงรายการยืมตาม ID
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
     * Lock row สำหรับ transaction
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
     * สร้างรายการยืม
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
     */
    public function countActiveBorrowsForUpdate(int $userId): int
    {
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
     * นับจำนวนเกินกำหนด
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

    /**
     * ดึงสถิติรายเดือน
     */
    public function getMonthlyStatistics(int $months = 6): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                DATE_FORMAT(borrow_date, '%Y-%m') as month,
                DATE_FORMAT(borrow_date, '%b') as month_name,
                COUNT(*) as total_borrows,
                SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned,
                SUM(fine_amount) as total_fines
            FROM borrows 
            WHERE borrow_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
            GROUP BY DATE_FORMAT(borrow_date, '%Y-%m')
            ORDER BY month ASC
        ");
        $stmt->execute([$months]);
        return $stmt->fetchAll();
    }
}
