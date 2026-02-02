<?php
/**
 * PaymentRepository - Database Access สำหรับการชำระเงิน
 * 
 * @package App\Repositories
 */

namespace App\Repositories;

use PDO;

class PaymentRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * สร้างรายการชำระเงินใหม่
     */
    public function create(int $borrowId, float $amount, ?int $recordedBy = null): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO payments (borrow_id, amount, recorded_by) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$borrowId, $amount, $recordedBy]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * ดึงยอดค่าปรับที่รับชำระแล้วทั้งหมด
     */
    public function getTotalCollected(): float
    {
        return (float) $this->pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments")->fetchColumn();
    }

    /**
     * ดึงรายการ payment ตาม borrow_id
     */
    public function findByBorrowId(int $borrowId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM payments WHERE borrow_id = ?");
        $stmt->execute([$borrowId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * นับค่าปรับค้างชำระ
     */
    public function getUnpaidTotal(): float
    {
        return (float) $this->pdo->query("
            SELECT COALESCE(SUM(b.fine_amount), 0) 
            FROM borrows b
            LEFT JOIN payments p ON b.id = p.borrow_id
            WHERE b.fine_amount > 0 AND p.id IS NULL
        ")->fetchColumn();
    }

    /**
     * ยอดค่าปรับที่รับชำระเดือนนี้
     */
    public function getThisMonthTotal(): float
    {
        return (float) $this->pdo->query("
            SELECT COALESCE(SUM(amount), 0) FROM payments 
            WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())
        ")->fetchColumn();
    }

    /**
     * ดึงรายการ payment ทั้งหมด พร้อม join ข้อมูลที่เกี่ยวข้อง
     */
    public function findAll(array $filters = []): array
    {
        $sql = "
            SELECT p.*, p.created_at as payment_date, b.borrow_date, b.return_date, 
                   u.name as member_name, 
                   bk.title as book_title,
                   staff.name as staff_name
            FROM payments p
            JOIN borrows b ON p.borrow_id = b.id
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            LEFT JOIN users staff ON p.recorded_by = staff.id
        ";

        $params = [];
        if (!empty($filters['search'])) {
            $sql .= " WHERE (u.name LIKE ? OR bk.title LIKE ? OR staff.name LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params = [$search, $search, $search];
        }

        $sql .= " ORDER BY p.created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

