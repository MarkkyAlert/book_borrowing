<?php
/**
 * PasswordResetRepository - Data Access Layer สำหรับ Password Reset
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - Repository นี้จัดการ CRUD สำหรับตาราง password_resets
 * - Token lifecycle: สร้าง → validate → ใช้ (mark used) → ลบเมื่อหมดอายุ
 * - Token มีอายุ 1 ชม. ใช้ได้ครั้งเดียว (one-time-use)
 * 
 * 📍 Entrypoints:
 * - forgot_password.php → AuthService → create() (สร้าง token)
 * - reset_password.php  → AuthService → findValidToken(), markUsed()
 * - cron/cleanup_tokens.php → deleteExpired() (ลบ token หมดอายุ)
 * 
 * ⚠️ ห้ามแก้:
 * - markUsed() ต้องถูกเรียกหลัง reset สำเร็จเสมอ (one-time-use)
 * - deleteByEmail() ลบ token เก่าก่อนสร้างใหม่ (ป้องกัน token สะสม)
 * 
 * @package App\Repositories
 */

namespace App\Repositories;

use PDO;

class PasswordResetRepository
{
    private PDO $pdo;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * สร้าง reset token ใหม่
     * 
     * @param string $email     อีเมลผู้ใช้
     * @param string $token     Token (64 chars hex)
     * @param string $expiresAt วันหมดอายุ (Y-m-d H:i:s)
     * @return int ID ของ reset request
     */
    public function create(string $email, string $token, string $expiresAt): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO password_resets (email, token, expires_at) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$email, $token, $expiresAt]);
        return (int) $this->pdo->lastInsertId();
    }
    
    /**
     * ดึง valid reset request ตาม token
     * 
     * ตรวจสอบ 3 เงื่อนไข:
     * 1. token ตรงกับใน DB
     * 2. ยังไม่เคยใช้ (used=0)
     * 3. ยังไม่หมดอายุ
     * 
     * @param string $token Token ที่ได้จาก URL
     * @return array|null Reset request data พร้อม user_id ถ้าพบ
     */
    public function findValidToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT pr.*, u.id as user_id 
            FROM password_resets pr
            JOIN users u ON u.email = pr.email
            WHERE pr.token = ? 
            AND pr.used = 0 
            AND pr.expires_at > NOW()
        ");
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * นับจำนวน request ล่าสุดของ email (สำหรับ rate limiting)
     * 
     * @param string $email   อีเมล
     * @param int    $hours   จำนวนชั่วโมงย้อนหลัง (default: 1)
     * @return int จำนวน requests
     */
    public function countRecentByEmail(string $email, int $hours = 1): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM password_resets 
            WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$email, $hours]);
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * mark token ว่าใช้แล้ว
     * 
     * @param int $id ID ของ reset request
     * @return bool
     */
    public function markUsed(int $id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * ลบ expired tokens (cleanup)
     * 
     * @return int จำนวน rows ที่ลบ
     */
    public function deleteExpired(): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM password_resets WHERE expires_at < NOW()");
        $stmt->execute();
        return $stmt->rowCount();
    }
}
