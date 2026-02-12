<?php
/**
 * PasswordResetRepository - Data Access Layer สำหรับ Password Reset
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * Repository นี้เป็น "Data Access Layer" สำหรับตาราง password_resets
 * ทำหน้าที่จัดการ token ที่ใช้สำหรับรีเซ็ตรหัสผ่าน (ลืมรหัสผ่าน)
 *
 * 📚 โครงสร้างตาราง password_resets:
 * +------------+------------------+----------------------------------------------+
 * | Column     | Type             | อธิบาย                                       |
 * +------------+------------------+----------------------------------------------+
 * | id         | INT AUTO_INC PK  | Primary Key                                  |
 * | email      | VARCHAR          | อีเมลของ user ที่ขอ reset                     |
 * | token      | VARCHAR(64)      | Hex token สุ่ม (ใช้ bin2hex(random_bytes))   |
 * | used       | TINYINT (0/1)    | 0=ยังไม่ได้ใช้, 1=ใช้แล้ว (one-time-use)    |
 * | expires_at | DATETIME         | เวลาหมดอายุ (ปกติ +1 ชั่วโมง)              |
 * | created_at | DATETIME DEFAULT | เวลาสร้าง (auto)                           |
 * +------------+------------------+----------------------------------------------+
 *
 * 🔄 Token Lifecycle (วงจรชีวิตของ token):
 * 1. User กรอก email ที่ forgot_password.php
 * 2. AuthService::requestPasswordReset() → create() —— สร้าง token เก็บลง DB
 * 3. ระบบแสดง reset link ให้ user (reset_password.php?token=XXX)
 * 4. User กด link → AuthService::validateResetToken() → findValidToken()
 * 5. User กรอก password ใหม่ → AuthService::resetPassword() → markUsed()
 * 6. cron/cleanup_tokens.php → deleteExpired() —— ลบ token หมดอายุ
 *
 * 🏗️ สถาปัตยกรรม:
 * - Repository นี้ถูกเรียกผ่าน AuthService เท่านั้น (ไม่ถูกเรียกจาก Controller โดยตรง)
 * - AuthService รับผิดชอบ business logic (สร้าง token, validate, rate limit)
 * - Repository รับผิดชอบเฉพาะ SQL queries เท่านั้น
 *
 * 🛡️ Security Design:
 * - Token ใช้ได้ครั้งเดียว (one-time-use) —— ป้องกัน token reuse attack
 * - Token มีอายุ 1 ชั่วโมง —— ลดความเสี่ยงถ้า token หลุด
 * - Rate limit 3 ครั้ง/ชม. —— ป้องกัน spam request
 * - ทุก query ใช้ prepared statements —— ป้องกัน SQL Injection
 *
 * ⚠️ ห้ามแก้:
 * - markUsed() ต้องถูกเรียกหลัง reset สำเร็จเสมอ —— ถ้าไม่เรียก token จะใช้ซ้ำได้
 * - findValidToken() ตรวจ 3 เงื่อนไขพร้อมกัน —— ห้ามลดเงื่อนไข
 *
 * 💬 สำหรับลูกค้าที่ถามว่า "ระบบลืมรหัสผ่านทำงานยังไง":
 * - ใช้ระบบ token-based reset (industry standard)
 * - Token สร้างด้วย random_bytes() + bin2hex() (คาดเดาไม่ได้)
 * - มีอายุ + ใช้ครั้งเดียว + rate limit = 3 ชั้นป้องกัน
 * - ระบบนี้ไม่ส่ง email จริง (แสดง link บนหน้าเว็บแทน เพราะเป็น template/demo)
 *
 * @package App\Repositories
 */

namespace App\Repositories;

use PDO;

class PasswordResetRepository
{
    // 🗄️ PDO connection — inject ผ่าน constructor ใช้ร่วมกันทุกเมธอด
    private PDO $pdo;
    
    // 🏗️ Constructor: รับ PDO จากภายนอก (Dependency Injection)
    // → ใช้ connection เดียวกับ AuthService ที่เรียก
    // → ทำให้ transaction (markUsed + updatePassword) ทำงานถูกต้อง
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สร้าง reset token ใหม่เก็บลงใน DB
     * ==========================================================================
     * เมธอดนี้ทำหน้าที่ INSERT ข้อมูล reset token ลงตาราง password_resets
     * เป็นขั้นตอนแรกของ Token Lifecycle
     *
     * 🔄 Flow การทำงาน:
     * Step 1 → รับค่า email, token, expiresAt จาก AuthService
     * Step 2 → INSERT ลงตาราง password_resets (used จะเป็น 0 โดย default)
     * Step 3 → คืน ID ของ row ที่สร้าง
     *
     * 📥 Input:
     * @param string $email     อีเมลของ user ที่ขอ reset
     *                          - มาจาก: AuthService::requestPasswordReset()
     *                          - รูปแบบ: string เช่น "user@example.com"
     *                          - ต้องเป็น email ที่มีจริงในตาราง users (ตรวจแล้วโดย AuthService)
     * @param string $token     Token สุ่มที่ AuthService สร้างด้วย bin2hex(random_bytes(32))
     *                          - รูปแบบ: 64 ตัวอักษร hex เช่น "a1b2c3d4..." (คาดเดาไม่ได้)
     * @param string $expiresAt เวลาหมดอายุ
     *                          - รูปแบบ: "Y-m-d H:i:s" เช่น "2025-01-15 14:30:00"
     *                          - ปกติคือ +1 ชั่วโมงจากตอนสร้าง
     *
     * 📤 Output:
     * @return int ID ของ reset request ที่สร้าง (lastInsertId)
     *              - ใช้ต่อ: AuthService เก็บไว้แต่ปัจจุบันไม่ได้ใช้ต่อ (คืนแค่ token ให้ user)
     *
     * 🧠 เหตุผลเชิงออกแบบ:
     * - ทำไมไม่เก็บ token เป็น hash? เพราะระบบนี้เป็น template/demo
     *   ในระบบ production ควรเก็บ hash ของ token แทน plaintext
     * - ทำไมไม่ลบ token เก่าก่อนสร้างใหม่? เพราะ token เก่าจะหมดอายุเอง
     *   และ cron จะลบทิ้งทีหลัง (ไม่สะสมจนเต็มตาราง)
     *
     * 🛡️ Security & Data Integrity:
     * - ใช้ prepared statement (ป้องกัน SQL Injection)
     * - token สร้างด้วย random_bytes(32) = 256-bit entropy (คาดเดาไม่ได้)
     * - ไม่มี UNIQUE constraint บน token แต่โอกาสชนแทบเป็น 0 เพราะ 256-bit random
     *
     * ⚠️ Edge cases:
     * - ถ้า email ไม่มีจริงในตาราง users? → INSERT ยังสำเร็จ (ไม่มี FK constraint)
     *   แต่ findValidToken() จะไม่เจอเพราะ JOIN users ไม่มี row → ปลอดภัย
     * - ถ้า email เดียวกันขอหลายครั้ง? → มีหลาย rows แต่ไม่เสียหาย
     *   (ควบคุมโดย rate limit ใน AuthService + cron cleanup)
     *
     * ✅ Use case จริง:
     * forgot_password.php → AuthService::requestPasswordReset($email)
     *   → สร้าง token → เรียก create($email, $token, $expiresAt)
     *   → คืน token ให้ user ผ่าน link reset_password.php?token=XXX
     */
    public function create(string $email, string $token, string $expiresAt): int
    {
        // 📝 SQL: INSERT token ใหม่ลงตาราง password_resets
        //    used จะเป็น 0 โดย default (ยังไม่ได้ใช้)
        //    created_at จะถูกเติมอัตโนมัติ (DEFAULT CURRENT_TIMESTAMP)
        $stmt = $this->pdo->prepare("
            INSERT INTO password_resets (email, token, expires_at) 
            VALUES (?, ?, ?)
        ");
        // 🚀 bind: [$email, $token, $expiresAt]
        //    $token = 64 hex chars จาก bin2hex(random_bytes(32))
        //    $expiresAt = ปกติ +1 ชั่วโมงจากตอนสร้าง
        $stmt->execute([$email, $token, $expiresAt]);
        // 📤 คืน ID ของ reset request ที่สร้าง
        return (int) $this->pdo->lastInsertId();
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ค้นหาและตรวจสอบว่า token ยังใช้งานได้หรือไม่
     * ==========================================================================
     * เมธอดนี้คือ "หัวใจ" ของการตรวจสอบ token
     * ตรวจพร้อมกัน 3 เงื่อนไขใน query เดียว:
     *   1. token ตรงกับใน DB
     *   2. ยังไม่เคยใช้ (used = 0)
     *   3. ยังไม่หมดอายุ (expires_at > NOW())
     * พร้อม JOIN กับ users เพื่อดึง user_id มาด้วย
     *
     * 🔄 Flow การทำงาน:
     * Step 1 → รับ token จาก URL parameter (ผ่าน AuthService)
     * Step 2 → SELECT + JOIN users โดยเช็ค 3 เงื่อนไขใน WHERE clause
     * Step 3 → คืน row ที่เจอ (รวม user_id) หรือ null ถ้าไม่ผ่าน
     *
     * 📥 Input:
     * @param string $token Token ที่ได้จาก URL (?token=XXX)
     *                      - มาจาก: reset_password.php → AuthService → เมธอดนี้
     *                      - รูปแบบ: 64 ตัวอักษร hex
     *
     * 📤 Output:
     * @return array|null
     *   - พบ: คืน array ที่มี id, email, token, used, expires_at, user_id
     *     → AuthService ใช้ user_id เพื่อ update password, ใช้ id เพื่อ markUsed()
     *   - ไม่พบ: คืน null → AuthService แสดง error "ลิงก์ไม่ถูกต้องหรือหมดอายุ"
     *
     * 🧠 เหตุผลเชิงออกแบบ:
     * - ทำไมตรวจ 3 เงื่อนไขใน SQL เดียว? เพื่อป้องกันการแยกตรวจ (race condition)
     *   ถ้าเช็คแยกส่วน (เช็ค used แล้ค่อยเช็ค expires) อาจมีช่องโหว่ระหว่างกลาง
     * - ทำไม JOIN users? เพื่อดึง user_id มาใช้ต่อใน updatePassword()
     *   และเพื่อตรวจว่า email ยังมีอยู่ในระบบ (ถ้า user ถูกลบไปแล้ว JOIN ได้ 0 rows)
     *
     * 🛡️ Security & Data Integrity:
     * - prepared statement ป้องกัน SQL Injection
     * - ตรวจ used=0 + expires_at > NOW() พร้อมกัน —— ป้องกัน token reuse
     * - ไม่แยก error message (ไม่บอกว่า "หมดอายุ" หรือ "ใช้แล้ว") —— ป้องกัน token enumeration
     *
     * ⚠️ Edge cases:
     * - token ไม่มีอยู่จริง → คืน null
     * - token ถูกใช้แล้ว (used=1) → คืน null (ป้องกันใช้ซ้ำ)
     * - token หมดอายุ (expires_at < NOW()) → คืน null
     * - user ถูกลบหลังขอ reset → JOIN ได้ 0 rows → คืน null (ปลอดภัย)
     *
     * ✅ Use case จริง (ถูกเรียก 2 จุด):
     * 1) reset_password.php (GET) → AuthService::validateResetToken()
     *    → findValidToken() —— เพื่อตรวจว่าควรแสดง form หรือไม่
     * 2) reset_password.php (POST) → AuthService::resetPassword()
     *    → findValidToken() —— เพื่อตรวจอีกทีก่อนเปลี่ยน password
     */
    public function findValidToken(string $token): ?array
    {
        // 📝 SQL: ตรวจ token พร้อมกัน 3 เงื่อนไขใน query เดียว:
        //    1) pr.token = ? → token ตรงกับใน DB
        //    2) pr.used = 0  → ยังไม่เคยใช้ (one-time-use)
        //    3) pr.expires_at > NOW() → ยังไม่หมดอายุ
        // 🧠 JOIN users เพื่อดึง user_id มาด้วย
        //    + ตรวจว่า email ยังมีอยู่ใน users (ถ้า user ถูกลบ → JOIN ได้ 0 rows → null)
        // 🛡️ ไม่แยก error message (ไม่บอกว่า "หมดอายุ" หรือ "ใช้แล้ว") ป้องกัน token enumeration
        $stmt = $this->pdo->prepare("
            SELECT pr.*, u.id as user_id 
            FROM password_resets pr
            JOIN users u ON u.email = pr.email
            WHERE pr.token = ? 
            AND pr.used = 0 
            AND pr.expires_at > NOW()
        ");
        $stmt->execute([$token]);
        // 📤 คืน {id, email, token, used, expires_at, user_id} หรือ null
        //    null = token ไม่ถูกต้อง/หมดอายุ/ใช้แล้ว
        return $stmt->fetch() ?: null;
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับจำนวนครั้งที่ขอ reset ล่าสุด (สำหรับ rate limiting)
     * ==========================================================================
     * เมธอดนี้ใช้สำหรับตรวจสอบว่า user ขอ reset บ่อยเกินไปหรือไม่
     * AuthService ใช้ค่านี้ตัดสินใจว่าจะสร้าง token ใหม่หรือปฏิเสธ
     *
     * 🔄 Flow การทำงาน:
     * Step 1 → รับ email + จำนวนชั่วโมงย้อนหลัง
     * Step 2 → COUNT rows ที่ created_at อยู่ในช่วงเวลาที่กำหนด
     * Step 3 → คืนจำนวน (ถ้า >= 3 AuthService จะปฏิเสธ)
     *
     * 📥 Input:
     * @param string $email อีเมลที่ต้องการตรวจ
     *                      - มาจาก: AuthService::requestPasswordReset()
     * @param int    $hours จำนวนชั่วโมงย้อนหลัง (default: 1)
     *                      - ตัวอย่าง: hours=1 → นับเฉพาะ 1 ชั่วโมงล่าสุด
     *
     * 📤 Output:
     * @return int จำนวน requests ในช่วงเวลา
     *              - AuthService เช็ค: ถ้า >= 3 → ปฏิเสธ (ไม่สร้าง token ใหม่)
     *
     * 🧠 เหตุผลเชิงออกแบบ:
     * - ทำไมต้อง rate limit? ป้องกัน attacker spam request เพื่อ:
     *   1) ทำให้กล่อง inbox เต็มไปด้วย reset email (ถ้ามีระบบส่ง email)
     *   2) ทำให้ตารางเต็มไปด้วย rows ไม่จำเป็น
     * - ทำไมนับที่ DB? เพราะ rate limit ใน function นี้เป็น "ต่อ email"
     *   (ระบบมี rate limit ระดับ global ใน forgot_password.php อีกชั้น)
     *
     * 🛡️ Security:
     * - prepared statement ป้องกัน SQL Injection
     * - ใช้ DATE_SUB + INTERVAL ของ MySQL (ไม่ใช้เวลาฝั่ง PHP)
     *
     * ⚠️ Edge cases:
     * - email ที่ไม่เคยขอ → คืน 0 (ไม่ error)
     * - hours = 0 → INTERVAL 0 HOUR = นับแค่ตอนนี้ (ไม่ค่อยมีประโยชน์)
     *
     * ✅ Use case จริง:
     * AuthService::requestPasswordReset() เรียกก่อนสร้าง token:
     *   $count = $resetRepo->countRecentByEmail($email, 1);
     *   if ($count >= 3) { return ['error' => 'ขอบ่อยเกินไป']; }
     */
    public function countRecentByEmail(string $email, int $hours = 1): int
    {
        // 📝 SQL: นับจำนวนครั้งที่ขอ reset ในช่วง $hours ชั่วโมงล่าสุด
        // 🧠 DATE_SUB(NOW(), INTERVAL ? HOUR) = เวลาปัจจุบัน - $hours ชั่วโมง
        //    เช่น hours=1 → นับเฉพาะ 1 ชั่วโมงล่าสุด
        // 🛡️ ใช้สำหรับ rate limit: ถ้า >= 3 → AuthService ปฏิเสธ
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM password_resets 
            WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$email, $hours]);
        // 📤 คืนจำนวน → AuthService เช็ค >= 3 แล้วปฏิเสธ
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: mark token ว่าใช้แล้ว (one-time-use enforcement)
     * ==========================================================================
     * เมธอดนี้คือ "ตัวล็อคความปลอดภัย" ของระบบ
     * เปลี่ยน used จาก 0 เป็น 1 —— ทำให้ token ใช้ซ้ำไม่ได้
     *
     * 🔄 Flow การทำงาน:
     * Step 1 → รับ id ของ reset request (จาก findValidToken)
     * Step 2 → UPDATE password_resets SET used = 1 WHERE id = ?
     * Step 3 → คืน true/false
     *
     * 📥 Input:
     * @param int $id ID ของ reset request (จาก findValidToken()['id'])
     *                - มาจาก: AuthService::resetPassword()
     *                - ตัวอย่าง: 42
     *
     * 📤 Output:
     * @return bool true = สำเร็จ, false = ล้มเหลว
     *              - AuthService ไม่ได้เช็คค่า return (เรียกใน transaction)
     *
     * 🧠 เหตุผลเชิงออกแบบ:
     * - ทำไมไม่ลบ token แทน mark used? เพราะเก็บไว้เป็นหลักฐานว่าระบบเคยทำงาน
     *   (สำหรับ debug, audit trail)
     * - ถ้าลบทิ้ง → findValidToken() จะไม่เจอเพราะหมดอายุ (ผลเหมือนกัน)
     *   แต่ตารางจะเต็มไปเรื่อยๆ
     *
     * 🛡️ Security & Data Integrity:
     * - เมธอดนี้ถูกเรียกใน transaction ร่วมกับ updatePassword() ใน AuthService
     *   ถ้า updatePassword สำเร็จแต่ markUsed ล้มเหลว → rollback ทั้งหมด
     * - prepared statement ป้องกัน SQL Injection
     *
     * ⚠️ Edge cases:
     * - id ไม่มีอยู่จริง → UPDATE 0 rows แต่ execute() ยังคืน true (ไม่ error)
     * - เรียก markUsed ซ้ำ → ไม่เสียหาย (แค่ SET used=1 ซ้ำ)
     *
     * ✅ Use case จริง:
     * AuthService::resetPassword() หลังเปลี่ยน password สำเร็จ:
     *   $this->userRepo->updatePassword($resetRequest['user_id'], ...);
     *   $resetRepo->markUsed($resetRequest['id']);  // ต้องเรียกเสมอ!
     *   $this->pdo->commit();
     */
    public function markUsed(int $id): bool
    {
        // 📝 SQL: mark token ว่าใช้แล้ว (used = 0 → 1)
        // 🔴 ต้องเรียกหลัง reset สำเร็จเสมอ!
        //    ถ้าไม่เรียก → token ใช้ซ้ำได้ (security hole)
        // 🧠 ทำไมไม่ลบทิ้งแต่ mark? เก็บไว้เป็นหลักฐาน (audit trail)
        //    cron/cleanup_tokens.php จะลบทีหลัง
        // 🛡️ เรียกใน transaction ร่วมกับ updatePassword()
        //    ถ้า updatePassword สำเร็จแต่ markUsed ล้มเหลว → rollback ทั้งหมด
        $stmt = $this->pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ลบ token ที่หมดอายุแล้ว (cleanup / housekeeping)
     * ==========================================================================
     * เมธอดนี้ทำความสะอาดให้ตาราง password_resets
     * ลบทุก row ที่ expires_at < NOW() (ทั้งที่ใช้แล้วและยังไม่ได้ใช้)
     *
     * 🔄 Flow การทำงาน:
     * Step 1 → DELETE ทุก row ที่ expires_at < NOW()
     * Step 2 → คืนจำนวนที่ลบ (สำหรับ log)
     *
     * 📥 Input:
     * - ไม่มี parameter
     * - เรียกจาก: cron/cleanup_tokens.php (รันตามเวลา เช่น วันละครั้งตอนตี 3)
     *
     * 📤 Output:
     * @return int จำนวน rows ที่ลบ
     *              - cron ใช้แสดงใน log: "Deleted expired tokens: 5"
     *
     * 🧠 เหตุผลเชิงออกแบบ:
     * - ทำไมต้องมี cron cleanup? ป้องกันตารางโตไปเรื่อยๆ
     *   ถ้าระบบมีคนใช้เยอะ ตารางจะโตขึ้นเรื่อยๆ แต่ไม่เสียหาย (แค่ query ช้าลง)
     * - ทำไมลบทั้ง used และ unused? เพราะทั้งคู่หมดอายุแล้ว —— ไม่มีประโยชน์เก็บไว้
     *
     * 🛡️ Security:
     * - ลบ token หมดอายุ = ลดข้อมูลที่ไม่จำเป็นใน DB
     * - prepared statement (แม้ไม่มี user input แต่ใช้เพื่อความสม่ำเสมอ)
     *
     * ⚠️ Edge cases:
     * - ไม่มี expired rows → DELETE 0 rows → คืน 0 (ไม่ error)
     * - มี 1000 expired rows → ลบทีเดียว (ไม่มี LIMIT)
     *   ปกติไม่เป็นปัญหาเพราะ rate limit 3/ชม. ต่อ user
     *
     * ✅ Use case จริง:
     * cron/cleanup_tokens.php (รันตอนตี 3 ทุกวัน):
     *   $deletedCount = $resetRepo->deleteExpired();
     *   echo "Deleted expired tokens: $deletedCount";
     */
    public function deleteExpired(): int
    {
        // 📝 SQL: ลบ token ที่หมดอายุแล้ว (ทั้งที่ใช้แล้วและยังไม่ได้ใช้)
        // 🧠 ทำความสะอาดตาราง — ป้องกันตารางโตไปเรื่อยๆ
        // 📌 เรียกจาก cron/cleanup_tokens.php (รันตามเวลา เช่น ตี 3 ทุกวัน)
        $stmt = $this->pdo->prepare("DELETE FROM password_resets WHERE expires_at < NOW()");
        $stmt->execute();
        // 📤 คืนจำนวนที่ลบ — ใช้แสดงใน log
        return $stmt->rowCount();
    }
}
