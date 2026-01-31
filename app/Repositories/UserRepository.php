<?php
/**
 * UserRepository - Data Access Layer สำหรับผู้ใช้งาน
 * 
 * Repository นี้จัดการ CRUD operations สำหรับตาราง users
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
     * ดึงผู้ใช้ทั้งหมดตาม filters
     * 
     * @param array $filters {
     *     role?: string,    // กรองตาม role ('admin', 'staff', 'member')
     *     search?: string   // ค้นหาใน name, email, phone
     * }
     * @return array รายการผู้ใช้ (ไม่รวม password)
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
     * ดึงผู้ใช้ตาม email (รวม password สำหรับ login)
     * 
     * @param string $email อีเมล
     * @return array|null ข้อมูลผู้ใช้ทั้งหมด (รวม password hash)
     * 
     * @security ใช้สำหรับ login - ไม่ควรส่ง password กลับไป client
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
     * 
     * @param array $data {
     *     name: string,       // ชื่อ (required)
     *     email: string,      // อีเมล (required, unique)
     *     password: string,   // password hash (required, ต้อง hash ก่อนส่งมา)
     *     phone?: string,     // เบอร์โทร
     *     role?: string       // role (default: 'member')
     * }
     * @return int ID ของผู้ใช้ที่สร้าง
     * 
     * @sideeffect INSERT ลง users table
     * @security password ต้อง hash ก่อนส่งมา (ใช้ password_hash)
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
     * 
     * @param int    $id             ID ผู้ใช้
     * @param string $hashedPassword password ที่ hash แล้ว (ต้อง hash ก่อนส่งมา)
     * @return bool true = สำเร็จ
     * 
     * @security password ต้อง hash ก่อนส่งมา (ใช้ password_hash)
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
     * 
     * @param string   $email     อีเมลที่ต้องการตรวจสอบ
     * @param int|null $excludeId ID ที่ต้องการยกเว้น (ใช้ตอน update)
     * @return bool true = มีอยู่แล้ว (ห้ามใช้)
     * 
     * @usecase ใช้ตอน register หรือ update profile
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
     * ดึงสถิติการยืมของสมาชิก (สำหรับหน้า profile)
     * 
     * @param int $userId ID ผู้ใช้
     * @return array {
     *     total_borrows: int,   // จำนวนการยืมทั้งหมด
     *     active_borrows: int,  // จำนวนที่ยังไม่คืน
     *     returned: int,        // จำนวนที่คืนแล้ว
     *     total_fines: float    // ค่าปรับรวม (บาท)
     * }
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
