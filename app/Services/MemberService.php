<?php
/**
 * MemberService - Business Logic สำหรับการจัดการสมาชิก
 * 
 * @package App\Services
 */

namespace App\Services;

require_once __DIR__ . '/../Repositories/UserRepository.php';
require_once __DIR__ . '/../Repositories/BorrowRepository.php';

use App\Repositories\UserRepository;
use App\Repositories\BorrowRepository;
use PDO;
use Exception;

class MemberService
{
    private PDO $pdo;
    private UserRepository $userRepo;
    private BorrowRepository $borrowRepo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->userRepo = new UserRepository($pdo);
        $this->borrowRepo = new BorrowRepository($pdo);
    }

    /**
     * ดึงรายการสมาชิกทั้งหมด
     * 
     * @param array $filters {
     *     search?: string,
     *     status?: string ('has_borrow', 'no_borrow'),
     *     sort?: string ('newest', 'oldest', 'az', 'za', 'most_borrows')
     * }
     */
    public function getMembers(array $filters = []): array
    {
        return $this->userRepo->findMembers($filters);
    }

    /**
     * ดึงข้อมูลสมาชิกตาม ID
     */
    public function getMemberById(int $id): ?array
    {
        return $this->userRepo->findMemberById($id);
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

        $memberId = $this->userRepo->create([
            'name' => trim($data['name']),
            'email' => trim($data['email']),
            'phone' => trim($data['phone'] ?? ''),
            'password' => $hashedPassword,
            'role' => 'member'
        ]);

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

        return $this->userRepo->update($id, [
            'name' => trim($data['name']),
            'email' => trim($data['email']),
            'phone' => trim($data['phone'] ?? '')
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
        if ($this->borrowRepo->countByUser($id) > 0) {
            throw new Exception('ไม่สามารถลบได้ สมาชิกมีประวัติการยืม');
        }

        return $this->userRepo->deleteMember($id);
    }

    /**
     * ตรวจสอบว่า email ซ้ำหรือไม่
     */
    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        return $this->userRepo->emailExists($email, $excludeId);
    }

    /**
     * ดึงประวัติการยืมของสมาชิก
     */
    public function getBorrowHistory(int $memberId, int $limit = 20): array
    {
        return $this->borrowRepo->findByUserId($memberId, $limit);
    }

    /**
     * ดึงสถิติสมาชิก
     */
    public function getMemberStatistics(int $memberId): array
    {
        return $this->borrowRepo->getStatsByUser($memberId);
    }

    /**
     * นับจำนวนสมาชิกทั้งหมด
     */
    public function countMembers(): int
    {
        return $this->userRepo->countMembers();
    }

    /**
     * สร้างรหัสผ่านแบบสุ่ม
     */
    private function generateRandomPassword(int $length = 8): string
    {
        return substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, $length);
    }
}
