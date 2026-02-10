<?php
/**
 * MemberService - Business Logic สำหรับการจัดการสมาชิก
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - Service นี้จัดการ CRUD สมาชิก (role = 'member')
 * - ไม่จัดการ admin/staff - ใช้ UserRepository โดยตรง
 * - การสร้างสมาชิกจะ generate password อัตโนมัติถ้าไม่ระบุ
 * 
 * 📍 Entrypoints:
 * - admin/members.php      → getMembers()
 * - admin/member_form.php  → createMember(), updateMember()
 * - api/add_member.php     → createMember() (quick add)
 * - register.php           → createMember() (ผ่าน AuthService::register())
 * 
 * ⚠️ ห้ามแก้:
 * - emailExists() ใช้เป็น single source of truth สำหรับ duplicate check
 * - createMember() ต้อง hash password ก่อน save เสมอ
 * 
 * @package App\Services
 */

namespace App\Services;

require_once __DIR__ . '/../Repositories/UserRepository.php';
require_once __DIR__ . '/../Repositories/BorrowRepository.php';
require_once __DIR__ . '/../Repositories/ReservationRepository.php';

use App\Repositories\UserRepository;
use App\Repositories\BorrowRepository;
use App\Repositories\ReservationRepository;
use PDO;
use Exception;

class MemberService
{
    private PDO $pdo;
    private UserRepository $userRepo;
    private BorrowRepository $borrowRepo;
    private ReservationRepository $reservationRepo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->userRepo = new UserRepository($pdo);
        $this->borrowRepo = new BorrowRepository($pdo);
        $this->reservationRepo = new ReservationRepository($pdo);
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
     * ดึงข้อมูลสมาชิกตาม ID (เฉพาะ role='member')
     * 
     * @param int $id ID สมาชิก
     * @return array|null ข้อมูลสมาชิก (ไม่รวม password) หรือ null ถ้าไม่พบ/ไม่ใช่ member
     */
    public function getMemberById(int $id): ?array
    {
        return $this->userRepo->findMemberById($id);
    }

    /**
     * สร้างสมาชิกใหม่
     * 
     * @param array $data { name: string, email: string, phone?: string, password?: string }
     * @return array ['id' => int, 'name' => string, 'email' => string, 'password' => string]
     * @throws Exception เมื่อข้อมูลไม่ครบ, email ซ้ำ, หรือรูปแบบไม่ถูกต้อง
     */
    public function createMember(array $data): array
    {
        // Validate via shared helper (Single Source of Truth)
        $errors = validateMemberData($data);
        if (!empty($errors)) {
            throw new Exception($errors[0]);
        }

        // Check duplicate email
        if ($this->emailExists($data['email'])) {
            throw new Exception('อีเมลนี้ถูกใช้งานแล้ว');
        }

        // Use provided password or generate random
        $password = !empty($data['password']) ? $data['password'] : $this->generateRandomPassword();
        $memberId = $this->userRepo->create([
            'name' => trim($data['name']),
            'email' => trim($data['email']),
            'phone' => trim($data['phone'] ?? ''),
            'password' => hashPassword($password),
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
     * 
     * @param int $id ID สมาชิก
     * @param array $data { name: string, email: string, phone?: string }
     * @return bool true = สำเร็จ
     * @throws Exception ถ้าไม่พบสมาชิก หรือ email ซ้ำ
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
     * @param int $id ID สมาชิก
     * @return bool true = สำเร็จ
     * @throws Exception ถ้าสมาชิกมีประวัติการยืม
     * @sideeffect DELETE จาก users table
     */
    public function deleteMember(int $id): bool
    {
        // [DATA INTEGRITY] ป้องกันลบข้อมูล — CASCADE DELETE จะลบ borrows ทำให้สถิติเสียหาย
        if ($this->borrowRepo->countByUser($id) > 0) {
            throw new Exception('ไม่สามารถลบได้ สมาชิกมีประวัติการยืม');
        }

        // [DATA INTEGRITY] CASCADE DELETE จะลบ reservation แต่ไม่คืน stock — ต้องยกเลิกก่อน
        if ($this->reservationRepo->countPendingByUser($id) > 0) {
            throw new Exception('ไม่สามารถลบได้ สมาชิกมีรายการจองที่รอดำเนินการ กรุณายกเลิกการจองก่อน');
        }

        return $this->userRepo->deleteMember($id);
    }

    /**
     * อัปเดตรหัสผ่านสมาชิก (Single Source of Truth สำหรับ admin reset password)
     * 
     * @param int $id ID สมาชิก
     * @param string $plainPassword รหัสผ่านใหม่ (plaintext)
     * @throws Exception ถ้า password ไม่ผ่าน validation
     */
    public function updatePassword(int $id, string $plainPassword): void
    {
        if ($err = validatePassword($plainPassword)) {
            throw new Exception($err);
        }
        $this->userRepo->updatePassword($id, hashPassword($plainPassword));
    }

    /**
     * ตรวจสอบว่า email ซ้ำหรือไม่ (Single Source of Truth สำหรับ duplicate check)
     * 
     * @param string   $email     อีเมลที่ต้องการตรวจ
     * @param int|null $excludeId ID ที่ยกเว้น (สำหรับ edit mode)
     * @return bool true = มีอยู่แล้ว (ห้ามใช้)
     */
    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        return $this->userRepo->emailExists($email, $excludeId);
    }

    /**
     * ดึงประวัติการยืมของสมาชิก (สำหรับ admin member detail page)
     * 
     * @param int $memberId ID สมาชิก
     * @param int $limit    จำนวนรายการสูงสุด (default: 20)
     * @return array[] รายการยืม + book_title, book_author
     */
    public function getBorrowHistory(int $memberId, int $limit = 20): array
    {
        return $this->borrowRepo->findByUserId($memberId, $limit);
    }

    /**
     * ดึงสถิติสมาชิก (total_borrows, active_borrows, returned, total_fines)
     * 
     * @param int $memberId ID สมาชิก
     * @return array { total_borrows: int, active_borrows: int, returned: int, total_fines: float }
     */
    public function getMemberStatistics(int $memberId): array
    {
        return $this->borrowRepo->getStatsByUser($memberId);
    }

    /**
     * นับจำนวนสมาชิกทั้งหมด (role='member')
     * 
     * @return int
     */
    public function countMembers(): int
    {
        return $this->userRepo->countMembers();
    }

    /**
     * Import สมาชิก (Create หรือ Update ถ้า email มีอยู่แล้ว)
     * 
     * ใช้สำหรับ bulk import จาก CSV - Single Source of Truth สำหรับ import logic
     * 
     * @param array $data { name: string, email: string, phone?: string }
     * @param string $defaultPassword รหัสผ่านเริ่มต้นสำหรับสมาชิกใหม่
     * @return array ['action' => 'created'|'updated', 'id' => int]
     */
    public function importMember(array $data, string $defaultPassword = '123456'): array
    {
        $email = trim($data['email']);
        $name = trim($data['name']);
        $phone = trim($data['phone'] ?? '');
        
        // Validate via shared helper (Single Source of Truth) — ไม่ต้องส่ง password เพราะ import ใช้ default
        $errors = validateMemberData(['name' => $name, 'email' => $email, 'phone' => $phone]);
        if (!empty($errors)) {
            throw new Exception($errors[0]);
        }
        
        // Check if exists
        $existing = $this->userRepo->findByEmail($email);
        
        if ($existing) {
            // UPDATE: Name & Phone only (keep existing password)
            $this->userRepo->update($existing['id'], [
                'name' => $name,
                'email' => $email,
                'phone' => $phone
            ]);
            return ['action' => 'updated', 'id' => $existing['id']];
        } else {
            // INSERT: New member with default password
            $memberId = $this->userRepo->create([
                'name' => $name,
                'email' => $email,
                'password' => hashPassword($defaultPassword),
                'phone' => $phone,
                'role' => 'member'
            ]);
            return ['action' => 'created', 'id' => $memberId];
        }
    }

    /**
     * สร้างรหัสผ่านแบบสุ่ม (a-z, 0-9)
     * 
     * @param int $length ความยาว (default: 8)
     * @return string plaintext password
     * 
     * @note ใช้ str_shuffle — ไม่ cryptographically secure แต่เพียงพอสำหรับ temporary password
     */
    private function generateRandomPassword(int $length = 8): string
    {
        return substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, $length);
    }
}
