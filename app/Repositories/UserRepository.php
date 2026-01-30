<?php
/**
 * UserRepository - Database Access สำหรับผู้ใช้งาน
 * 
 * @package App\Repositories
 */

namespace App\Repositories;

use PDO;

class UserRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ดึงผู้ใช้ทั้งหมด
     */
    public function findAll(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['role'])) {
            $where[] = "role = ?";
            $params[] = $filters['role'];
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $where[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ?)";
            $params = array_merge($params, ["%{$search}%", "%{$search}%", "%{$search}%"]);
        }

        $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->pdo->prepare("
            SELECT id, name, email, phone, role, created_at 
            FROM users 
            {$whereSQL}
            ORDER BY name
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * ดึงสมาชิกทั้งหมด
     */
    public function findAllMembers(): array
    {
        return $this->findAll(['role' => 'member']);
    }

    /**
     * ดึงผู้ใช้ตาม ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, name, email, phone, role, created_at 
            FROM users WHERE id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * ดึงผู้ใช้ตาม email
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM users WHERE email = ?
        ");
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    /**
     * ดึงสมาชิกตาม ID
     */
    public function findMemberById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, name, email, phone, role, created_at 
            FROM users WHERE id = ? AND role = 'member'
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * สร้างผู้ใช้ใหม่
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO users (name, email, phone, password, role)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['name'],
            $data['email'],
            $data['phone'] ?? null,
            $data['password'],
            $data['role'] ?? 'member'
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * อัปเดตผู้ใช้
     */
    public function update(int $id, array $data): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE users SET name = ?, email = ?, phone = ?
            WHERE id = ?
        ");

        return $stmt->execute([
            $data['name'],
            $data['email'],
            $data['phone'] ?? null,
            $id
        ]);
    }

    /**
     * อัปเดตรหัสผ่าน
     */
    public function updatePassword(int $id, string $hashedPassword): bool
    {
        $stmt = $this->pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        return $stmt->execute([$hashedPassword, $id]);
    }

    /**
     * ลบผู้ใช้
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * ตรวจสอบว่า email ซ้ำหรือไม่
     */
    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM users WHERE email = ?";
        $params = [$email];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * ตรวจสอบว่ามีประวัติการยืมหรือไม่
     */
    public function hasBorrowHistory(int $userId): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM borrows WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * นับจำนวนสมาชิก
     */
    public function countMembers(): int
    {
        return (int) $this->pdo->query("
            SELECT COUNT(*) FROM users WHERE role = 'member'
        ")->fetchColumn();
    }

    /**
     * ดึงสถิติของสมาชิก
     */
    public function getMemberStatistics(int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_borrows,
                SUM(CASE WHEN status = 'borrowing' THEN 1 ELSE 0 END) as active_borrows,
                SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned,
                SUM(fine_amount) as total_fines
            FROM borrows
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }
}
