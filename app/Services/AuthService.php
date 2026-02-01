<?php
/**
 * AuthService - Authentication & Authorization Business Logic
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - ไฟล์นี้จัดการ Login/Register/Password Reset
 * - ห้ามแก้ไขโดยไม่เข้าใจ - กระทบความปลอดภัยทั้งระบบ
 * 
 * 📍 Entrypoints:
 * - login.php          → login()
 * - register.php       → register()
 * - forgot_password.php→ requestPasswordReset()
 * - reset_password.php → resetPassword()
 * - profile.php        → updateProfile(), changePassword()
 * 
 * ⚠️ ห้ามแก้ (Security Critical):
 * - login()     - ตรวจ password, ป้องกัน user enumeration
 * - register()  - สร้าง user, hash password
 * - requestPasswordReset() - สร้าง token, ป้องกัน enumeration
 * 
 * @package App\Services
 */

namespace App\Services;

require_once __DIR__ . '/../Repositories/UserRepository.php';

use App\Repositories\UserRepository;
use PDO;

class AuthService
{
    private PDO $pdo;
    private UserRepository $userRepo;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->userRepo = new UserRepository($pdo);
    }
    
    // =========================================================================
    // LOGIN
    // =========================================================================
    
    /**
     * ดำเนินการ Login
     * 
     * @param string $email    อีเมลผู้ใช้
     * @param string $password รหัสผ่าน (plaintext)
     * @return array|null User data if success, null if failed
     * 
     * @security ไม่บอกว่า email หรือ password ผิด (ป้องกัน user enumeration)
     */
    public function login(string $email, string $password): ?array
    {
        $user = $this->userRepo->findByEmail($email);
        
        if (!$user) {
            return null;
        }
        
        if (!password_verify($password, $user['password'])) {
            return null;
        }
        
        return $user;
    }
    
    // =========================================================================
    // REGISTRATION
    // =========================================================================
    
    /**
     * ลงทะเบียนผู้ใช้ใหม่
     * 
     * @param array $data {
     *     name: string,     // ชื่อ-นามสกุล (required)
     *     email: string,    // อีเมล (required, unique)
     *     password: string, // รหัสผ่าน plaintext (required, min 6 chars)
     *     phone?: string    // เบอร์โทร (optional)
     * }
     * @return array { success: bool, user_id?: int, error?: string }
     * 
     * @sideeffect INSERT ลง users table
     */
    public function register(array $data): array
    {
        // Validate required fields
        if (empty($data['name']) || empty($data['email']) || empty($data['password'])) {
            return ['success' => false, 'error' => 'ข้อมูลไม่ครบถ้วน'];
        }
        
        // Check email duplicate
        if ($this->userRepo->emailExists($data['email'])) {
            return ['success' => false, 'error' => 'อีเมลนี้ถูกใช้งานแล้ว'];
        }
        
        try {
            $userId = $this->userRepo->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => password_hash($data['password'], PASSWORD_DEFAULT),
                'role' => 'member' // Hardcode - ห้าม user เลือกเอง
            ]);
            
            return ['success' => true, 'user_id' => $userId];
        } catch (\PDOException $e) {
            return ['success' => false, 'error' => 'เกิดข้อผิดพลาด กรุณาลองใหม่'];
        }
    }
    
    // =========================================================================
    // PROFILE UPDATE
    // =========================================================================
    
    /**
     * อัปเดตข้อมูลโปรไฟล์
     * 
     * @param int   $userId User ID
     * @param array $data   { name: string, phone?: string }
     * @return bool true = success
     */
    public function updateProfile(int $userId, array $data): bool
    {
        $user = $this->userRepo->findById($userId);
        if (!$user) {
            return false;
        }
        
        return $this->userRepo->update($userId, [
            'name' => $data['name'],
            'email' => $user['email'], // ไม่เปลี่ยน email
            'phone' => $data['phone'] ?? null
        ]);
    }
    
    /**
     * เปลี่ยนรหัสผ่าน
     * 
     * @param int    $userId          User ID
     * @param string $currentPassword รหัสผ่านปัจจุบัน (plaintext)
     * @param string $newPassword     รหัสผ่านใหม่ (plaintext)
     * @return array { success: bool, error?: string }
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        $user = $this->userRepo->findByEmail(
            $this->userRepo->findById($userId)['email'] ?? ''
        );
        
        if (!$user) {
            return ['success' => false, 'error' => 'ไม่พบผู้ใช้'];
        }
        
        if (!password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'error' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง'];
        }
        
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->userRepo->updatePassword($userId, $hashedPassword);
        
        return ['success' => true];
    }
    
    // =========================================================================
    // PASSWORD RESET
    // =========================================================================
    
    /**
     * ขอ reset password link
     * 
     * @param string $email อีเมลผู้ใช้
     * @return array { success: bool, token?: string, error?: string }
     * 
     * @security ถ้า email ไม่อยู่ในระบบ ยังคง return success (ป้องกัน enumeration)
     * @sideeffect INSERT ลง password_resets table
     */
    public function requestPasswordReset(string $email): array
    {
        require_once __DIR__ . '/../Repositories/PasswordResetRepository.php';
        $resetRepo = new \App\Repositories\PasswordResetRepository($this->pdo);
        
        // Check if user exists
        $user = $this->userRepo->findByEmail($email);
        if (!$user) {
            // ไม่บอกว่า email ไม่อยู่ - ป้องกัน enumeration
            return ['success' => true, 'token' => null];
        }
        
        // Rate limiting - max 3 requests per hour
        $recentRequests = $resetRepo->countRecentByEmail($email, 1);
        if ($recentRequests >= 3) {
            return ['success' => false, 'error' => 'คุณขอรีเซ็ตรหัสผ่านบ่อยเกินไป กรุณารอ 1 ชั่วโมง'];
        }
        
        // Generate token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Save token
        $resetRepo->create($email, $token, $expiresAt);
        
        return ['success' => true, 'token' => $token];
    }
    
    /**
     * รีเซ็ตรหัสผ่านด้วย token
     * 
     * @param string $token       Token จาก URL
     * @param string $newPassword รหัสผ่านใหม่ (plaintext)
     * @return array { success: bool, error?: string }
     * 
     * @sideeffect UPDATE users.password + mark token as used
     */
    public function resetPassword(string $token, string $newPassword): array
    {
        require_once __DIR__ . '/../Repositories/PasswordResetRepository.php';
        $resetRepo = new \App\Repositories\PasswordResetRepository($this->pdo);
        
        // Validate token
        $resetRequest = $resetRepo->findValidToken($token);
        if (!$resetRequest) {
            return ['success' => false, 'error' => 'ลิงก์ไม่ถูกต้องหรือหมดอายุ'];
        }
        
        try {
            $this->pdo->beginTransaction();
            
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $this->userRepo->updatePassword($resetRequest['user_id'], $hashedPassword);
            
            // Mark token as used
            $resetRepo->markUsed($resetRequest['id']);
            
            $this->pdo->commit();
            return ['success' => true];
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'error' => 'เกิดข้อผิดพลาด กรุณาลองใหม่'];
        }
    }
    
    /**
     * ตรวจสอบ token ว่ายังใช้ได้ไหม
     * 
     * @param string $token Token จาก URL
     * @return array|null Reset request data ถ้า valid
     */
    public function validateResetToken(string $token): ?array
    {
        require_once __DIR__ . '/../Repositories/PasswordResetRepository.php';
        $resetRepo = new \App\Repositories\PasswordResetRepository($this->pdo);
        return $resetRepo->findValidToken($token);
    }
    
    // =========================================================================
    // HELPERS
    // =========================================================================
    
    /**
     * ดึง User Repository (สำหรับ operations ที่ไม่ต้อง business logic)
     */
    public function getUserRepository(): UserRepository
    {
        return $this->userRepo;
    }
}
