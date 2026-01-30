<?php
/**
 * MemberService - Business Logic สำหรับการจัดการสมาชิก
 * 
 * @package App\Services
 */

namespace App\Services;

use PDO;
use Exception;

class MemberService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ดึงรายการสมาชิกทั้งหมด
     */
    public function getMembers(array $filters = []): array
    {
        $where = ["role = 'member'"];
        $params = [];

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $where[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $stmt = $this->pdo->prepare("
            SELECT id, name, email, phone, created_at 
            FROM users 
            {$whereSQL}
            ORDER BY name
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * ดึงข้อมูลสมาชิกตาม ID
     */
    public function getMemberById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, name, email, phone, role, created_at 
            FROM users WHERE id = ? AND role = 'member'
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * สร้างสมาชิกใหม่
     * 
     * @return array ['id' => int, 'name' => string, 'email' => string]
     * @throws Exception
     */
    public function createMember(array $data): array
    {
        // Validate
        if (empty($data['name'])) {
            throw new Exception('กรุณากรอกชื่อ');
        }

        if (empty($data['email'])) {
            throw new Exception('กรุณากรอกอีเมล');
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('รูปแบบอีเมลไม่ถูกต้อง');
        }

        // Check duplicate email
        if ($this->emailExists($data['email'])) {
            throw new Exception('อีเมลนี้ถูกใช้งานแล้ว');
        }

        // Generate random password
        $password = $this->generateRandomPassword();
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $this->pdo->prepare("
            INSERT INTO users (name, email, phone, password, role)
            VALUES (?, ?, ?, ?, 'member')
        ");

        $stmt->execute([
            trim($data['name']),
            trim($data['email']),
            trim($data['phone'] ?? ''),
            $hashedPassword
        ]);

        $memberId = (int) $this->pdo->lastInsertId();

        return [
            'id' => $memberId,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $password // Return plain password for display once
        ];
    }

    /**
     * อัปเดตข้อมูลสมาชิก
     */
    public function updateMember(int $id, array $data): bool
    {
        $member = $this->getMemberById($id);
        if (!$member) {
            throw new Exception('ไม่พบสมาชิก');
        }

        // Check email duplicate (exclude current member)
        if (!empty($data['email']) && $data['email'] !== $member['email']) {
            if ($this->emailExists($data['email'])) {
                throw new Exception('อีเมลนี้ถูกใช้งานแล้ว');
            }
        }

        $stmt = $this->pdo->prepare("
            UPDATE users SET name = ?, email = ?, phone = ?
            WHERE id = ? AND role = 'member'
        ");

        return $stmt->execute([
            trim($data['name']),
            trim($data['email']),
            trim($data['phone'] ?? ''),
            $id
        ]);
    }

    /**
     * ลบสมาชิก
     * 
     * @throws Exception ถ้าสมาชิกมีประวัติการยืม
     */
    public function deleteMember(int $id): bool
    {
        // Check if has borrow history
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM borrows WHERE user_id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('ไม่สามารถลบได้ สมาชิกมีประวัติการยืม');
        }

        $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'member'");
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
     * ดึงประวัติการยืมของสมาชิก
     */
    public function getBorrowHistory(int $memberId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.*, bk.title as book_title, bk.author as book_author
            FROM borrows b
            JOIN books bk ON b.book_id = bk.id
            WHERE b.user_id = ?
            ORDER BY b.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$memberId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * ดึงสถิติสมาชิก
     */
    public function getMemberStatistics(int $memberId): array
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
        $stmt->execute([$memberId]);
        return $stmt->fetch();
    }

    /**
     * นับจำนวนสมาชิกทั้งหมด
     */
    public function countMembers(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn();
    }

    /**
     * สร้างรหัสผ่านแบบสุ่ม
     */
    private function generateRandomPassword(int $length = 8): string
    {
        return substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, $length);
    }
}
