<?php
/**
 * AuthService - Authentication & Authorization Business Logic
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * Service นี้จัดการ Authentication (login/register) และ
 * Account Management (profile update, password change/reset)
 * เป็นไฟล์ที่สำคัญที่สุดด้าน security — ห้ามแก้โดยไม่เข้าใจ
 *
 * 🏗️ สถาปัตยกรรม:
 * Controller (login.php) → AuthService → UserRepository / PasswordResetRepository
 *                                       → MemberService (สำหรับ register)
 *
 * 📍 Entrypoints:
 * - login.php           → login()
 * - register.php        → register()
 * - forgot_password.php → requestPasswordReset()
 * - reset_password.php  → resetPassword(), validateResetToken()
 * - profile.php         → updateProfile(), changePassword()
 *
 * 🛡️ Security Design:
 * - login(): ไม่แยกว่า "email ไม่พบ" หรือ "password ผิด" — ป้องกัน user enumeration
 * - register(): delegate ไป MemberService (single source of truth)
 * - requestPasswordReset(): return success แม้ email ไม่มีในระบบ
 * - resetPassword(): transaction — เปลี่ยน password + mark token used พร้อมกัน
 * - changePassword(): ยืนยันรหัสผ่านเดิมก่อน — ป้องกัน session hijack
 * - updateProfile(): email ไม่เปลี่ยน — ป้องกัน account takeover
 *
 * ⚠️ ห้ามแก้:
 * - login() ห้ามเปลี่ยน error message ให้แยกระหว่าง email/password
 * - requestPasswordReset() ห้ามเปลี่ยนให้ return error เมื่อ email ไม่พบ
 *
 * @package App\Services
 */

namespace App\Services;

require_once __DIR__ . '/../Repositories/UserRepository.php';
require_once __DIR__ . '/MemberService.php';

use App\Repositories\UserRepository;
use PDO;

class AuthService
{
    // 🗄️ PDO connection — ใช้สำหรับ transaction (reset password)
    private PDO $pdo;
    // 🗄️ UserRepository — ดึง/แก้ไขข้อมูล user
    private UserRepository $userRepo;
    
    // 🏗️ Constructor: สร้าง dependencies ทั้งหมด
    // → PDO ใช้ร่วมกัน = transaction ทำงานถูกต้อง
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->userRepo = new UserRepository($pdo);
    }
    
    // =========================================================================
    // LOGIN
    // =========================================================================
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดำเนินการ Login (email + password)
     * ==========================================================================
     *
     * 🔄 Flow: findByEmail() → password_verify() → return user | null
     *
     * 📥 Input:
     * @param string $email    อีเมลผู้ใช้
     * @param string $password รหัสผ่าน (plaintext)
     *
     * 📤 Output: @return array|null user data (รวม password hash) หรือ null
     *
     * 🛡️ Security:
     * - ไม่แยกว่า "email ไม่พบ" หรือ "password ผิด" — return null เหมือนกัน
     * - ป้องกัน user enumeration attack
     *
     * ✅ Use case: login.php POST
     */
    public function login(string $email, string $password): ?array
    {
        // 📝 Step 1: ค้นหา user จาก email (คืน password hash ด้วย)
        $user = $this->userRepo->findByEmail($email);
        
        // 🛡️ [SECURITY] ไม่แยกว่า "ไม่พบ email" หรือ "password ผิด" — return null เหมือนกัน
        //    ป้องกัน user enumeration attack (ผู้โจมตีไม่รู้ว่า email มีในระบบหรือไม่)
        if (!$user) {
            return null;
        }
        
        // 📝 Step 2: เทียบ plaintext กับ hash ด้วย password_verify()
        //    ⚠️ ห้ามใช้ == หรือ === เทียบ password! ต้องใช้ password_verify() เท่านั้น
        if (!password_verify($password, $user['password'])) {
            return null;
        }
        
        // 📤 คืน user data (รวม hash) → Controller จะเก็บ id + role ใน session
        return $user;
    }
    
    // =========================================================================
    // REGISTRATION
    // =========================================================================
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ลงทะเบียนผู้ใช้ใหม่ (delegate ไป MemberService)
     * ==========================================================================
     *
     * 🔄 Flow: validate → MemberService::createMember() → return result
     *
     * 📥 Input:
     * @param array $data {name, email, password, phone?}
     *
     * 📤 Output: @return array {success: bool, user_id?: int, error?: string}
     *
     * 🧠 เหตุผล: delegate ไป MemberService เพื่อ single source of truth
     *   (การสร้าง member ทั้งจาก register และ admin ใช้ logic เดียวกัน)
     *
     * ✅ Use case: register.php POST
     */
    public function register(array $data): array
    {
        try {
            // 🧠 Delegate ไปที่ MemberService (Single Source of Truth สำหรับสร้าง member)
            //    ทั้ง register.php และ admin/member_form.php ใช้ logic เดียวกัน
            //    → validate, check duplicate, hash password, INSERT
            $memberService = new MemberService($this->pdo);
            $result = $memberService->createMember([
                'name' => $data['name'] ?? '',
                'email' => $data['email'] ?? '',
                'phone' => $data['phone'] ?? '',
                'password' => $data['password'] ?? ''
            ]);
            
            // 📤 สำเร็จ → คืน user_id (Controller redirect ไป login.php — ไม่ auto-login)
            return ['success' => true, 'user_id' => $result['id']];
        } catch (\Exception $e) {
            // ❌ MemberService throw Exception → ดักแล้วคืน error message
            error_log("[AuthService::register] email={$data['email']} error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // =========================================================================
    // PROFILE UPDATE
    // =========================================================================
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: อัปเดตข้อมูลโปรไฟล์ (name + phone เท่านั้น)
     * ==========================================================================
     *
     * 📥 Input: @param int $userId, @param array $data {name, phone?}
     * 📤 Output: @return bool true = สำเร็จ
     *
     * 🛡️ Security: email ไม่เปลี่ยนผ่านหน้านี้ — ป้องกัน account takeover
     * ✅ Use case: profile.php POST
     */
    public function updateProfile(int $userId, array $data): bool
    {
        // 📝 Step 1: ดึง user ปัจจุบัน (ต้องมี email เดิม)
        $user = $this->userRepo->findById($userId);
        if (!$user) {
            return false;
        }
        
        // 🛡️ [SECURITY] email ไม่เปลี่ยนผ่านหน้านี้ — ป้องกัน user แอบเปลี่ยน email ของตัวเอง
        //    ⚠️ ใช้ $user['email'] (จาก DB) ไม่ใช่ $data['email'] (จาก POST)
        //    ถ้า user ส่ง email ใหม่มาใน form → ถูกเขียนทับด้วย email เดิมจาก DB
        return $this->userRepo->update($userId, [
            'name' => $data['name'],
            'email' => $user['email'],       // 🔒 email เดิมจาก DB เสมอ
            'phone' => $data['phone'] ?? null
        ]);
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: เปลี่ยนรหัสผ่าน (ต้องยืนยันรหัสเดิมก่อน)
     * ==========================================================================
     *
     * 🔄 Flow: findById → findByEmail(ได้ hash) → password_verify → updatePassword
     *
     * 📥 Input:
     * @param int    $userId
     * @param string $currentPassword รหัสปัจจุบัน (plaintext)
     * @param string $newPassword     รหัสใหม่ (plaintext)
     *
     * 📤 Output: @return array {success: bool, error?: string}
     *
     * 🛡️ Security:
     * - ยืนยันรหัสเดิมก่อน — ป้องกัน session hijack เปลี่ยนรหัส
     * - ห้ามใช้รหัสเดิมซ้ำ
     *
     * ✅ Use case: profile.php → แบบฟอร์มเปลี่ยนรหัสผ่าน
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        // 📝 Step 1: ดึง user (ไม่มี password) เพื่อเอา email
        $currentUser = $this->userRepo->findById($userId);
        if (!$currentUser) {
            return ['success' => false, 'error' => 'ไม่พบผู้ใช้'];
        }
        
        // 📝 Step 2: ดึง user (รวม password hash) ผ่าน findByEmail
        //    เพราะ findById ไม่คืน password (security by design)
        $user = $this->userRepo->findByEmail($currentUser['email']);
        if (!$user) {
            return ['success' => false, 'error' => 'ไม่พบผู้ใช้'];
        }
        
        // 🛡️ [SECURITY] Step 3: ยืนยันรหัสผ่านเดิมก่อน
        //    ป้องกัน session hijack → คนที่ขโมย session เปลี่ยนรหัสไม่ได้ถ้าไม่รู้รหัสเดิม
        if (!password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'error' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง'];
        }
        
        // 🛡️ Step 4: ห้ามใช้รหัสเดิมซ้ำ
        if (password_verify($newPassword, $user['password'])) {
            return ['success' => false, 'error' => 'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านปัจจุบัน'];
        }

        // 🛡️ [F-53] Step 4.5: ห้ามตั้งกลับเป็น "รหัสเริ่มต้นของระบบ"
        //    🧠 Step 4 ข้างบนกันไม่พอ — มันเทียบกับรหัส *ปัจจุบัน* เท่านั้น
        //       คนที่เปลี่ยนรหัสไปแล้วยังตั้งกลับมาเป็นรหัสเริ่มต้นได้ (Step 4 ผ่านฉลุย)
        //       ถ้าปล่อยไว้ การบังคับเปลี่ยนรหัสก็เป็นแค่พิธีกรรม — พิมพ์รหัสเดิมกลับเข้าไป
        //       แล้วบัญชีก็กลับไปเปิดให้ใครก็ได้เหมือนเดิม
        if ($newPassword === IMPORT_DEFAULT_PASSWORD) {
            return [
                'success' => false,
                'error' => 'รหัสผ่านนี้เป็นรหัสเริ่มต้นของระบบที่ทุกคนรู้ กรุณาตั้งรหัสผ่านอื่น'
            ];
        }
        
        // 📝 Step 5: hash + update
        //    hashPassword() = password_hash() wrapper จาก functions.php
        $this->userRepo->updatePassword($userId, hashPassword($newPassword));
        
        return ['success' => true];
    }
    
    // =========================================================================
    // PASSWORD RESET
    // =========================================================================
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ขอ reset password link (สร้าง token)
     * ==========================================================================
     *
     * 🔄 Flow: findByEmail → rate limit check → generate token → save
     *
     * 📥 Input: @param string $email
     * 📤 Output: @return array {success: bool, token?: string, error?: string}
     *
     * 🛡️ Security:
     * - email ไม่พบยัง return success (token=null) — ป้องกัน enumeration
     * - rate limit: max 3 requests/hour
     * - token = 64 hex chars (random_bytes(32))
     * - token หมดอายุ 1 ชั่วโมง
     *
     * ✅ Use case: forgot_password.php POST
     */
    public function requestPasswordReset(string $email): array
    {
        // 📝 Lazy require — โหลดเฉพาะเมื่อใช้งาน (ไม่โหลดทุก request)
        require_once __DIR__ . '/../Repositories/PasswordResetRepository.php';
        $resetRepo = new \App\Repositories\PasswordResetRepository($this->pdo);

        // 🧹 Lazy cleanup — ลบ token ที่หมดอายุแล้วทุกครั้งที่มีคนขอ
        //    ระบบนี้ไม่มี cron จริง (cron/cleanup_tokens.php มีไว้ให้ลูกค้าตั้งเองถ้าต้องการ)
        //    ถ้าไม่ล้างตรงนี้ ตารางจะโตไปเรื่อย ๆ และ countRecentByEmail() จะช้าลง
        //    ใช้รูปแบบเดียวกับ markExpiredReservations() ที่ระบบทำอยู่แล้วกับการจอง
        $resetRepo->deleteExpired();
        
        // 📝 Step 1: ตรวจว่า email มีอยู่หรือไม่
        $user = $this->userRepo->findByEmail($email);

        // 🛡️ Step 2: Rate limiting — max 3 requests ต่อชั่วโมงต่อ email
        //    ป้องกัน brute force + spam token
        //
        // 🔴 [SECURITY] ต้องนับ **ก่อน** แยกทางว่า email มีจริงหรือไม่ (F-40)
        //    เดิมโค้ด return ทันทีเมื่อไม่พบ email แล้วค่อยเช็ค rate limit
        //    ผลคือตัวจำกัดอัตราทำงานเฉพาะกับอีเมลที่มีจริง กลายเป็น oracle:
        //        อีเมลจริง  ยิงครั้งที่ 4 → "ขอรีเซ็ตบ่อยเกินไป"
        //        อีเมลปลอม  ยิงกี่ครั้งก็ success
        //    ยิง 4 ครั้งแล้วดูว่าต่างไหม = รู้ทันทีว่าอีเมลนั้นมีบัญชีหรือเปล่า
        //    ความพยายามกัน enumeration ทั้งหมดถูกเปิดโปงที่จุดนี้จุดเดียว
        //
        //    ตอนนี้จึงนับจาก password_resets ก่อนเสมอ และเมื่อเกินโควตา
        //    **คืนผลลัพธ์หน้าตาเดียวกับกรณีปกติ** ไม่ใช่ error คนละแบบ
        $recentRequests = $resetRepo->countRecentByEmail($email, 1);
        $overLimit = $recentRequests >= 3;

        if (!$user || $overLimit) {
            // 🛡️ [SECURITY] ไม่บอกว่า email ไม่อยู่ในระบบ หรือขอถี่เกินไป — ป้องกัน enumeration
            //    คืนผลเดียวกันทั้ง 2 กรณี → ผู้เรียกแยกไม่ออกว่าเกิดอะไรขึ้น
            //    🔴 ห้ามเปลี่ยนเป็น error คนละแบบเด็ดขาด (ดูเหตุผลด้านบน)
            //
            // ⚠️ **ไม่สร้างแถวหลอกให้อีเมลที่ไม่มีจริง**
            //    เคยลองสร้างเพื่อถ่วงเวลาให้เท่ากันทั้ง 2 ฝั่ง แต่ผลคือ
            //    ตาราง password_resets โตขึ้นตามอีเมลมั่ว ๆ ที่คนนอกยิงเข้ามา
            //    และระบบนี้**ไม่มี cron จริง** (cron/cleanup_tokens.php มีไว้ให้ลูกค้าตั้งเอง)
            //    แถวขยะจึงไม่มีวันหาย — แลกไม่คุ้มกับช่องทางเวลาที่วัดได้ยากมากผ่าน HTTP
            //
            //    สิ่งที่กัน enumeration ได้จริงคือ "คำตอบเหมือนกัน" ซึ่งทำครบแล้ว
            //    ส่วนเวลาตอบสนองยังต่างกันเล็กน้อย (ฝั่งที่ได้ token จริงมี INSERT เพิ่ม 1 ครั้ง)
            //    — บันทึกไว้ใน KNOWN_LIMITATIONS แล้ว ไม่ได้แกล้งทำเป็นไม่มี
            return ['success' => true, 'token' => null];
        }

        // 📝 Step 3: สร้าง token (64 hex chars = 32 bytes)
        //    ใช้ random_bytes() — cryptographically secure
        $token = bin2hex(random_bytes(32));
        // ⏰ หมดอายุใน 1 ชั่วโมง
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // 📝 Step 4: บันทึก token ลง DB
        $resetRepo->create($email, $token, $expiresAt);
        
        // 📤 คืน token → Controller สร้าง link reset_password.php?token=XXX
        return ['success' => true, 'token' => $token];
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: รีเซ็ตรหัสผ่านด้วย token
     * ==========================================================================
     *
     * 🔄 Flow: validate token → BEGIN TX → update password + mark used → COMMIT
     *
     * 📥 Input: @param string $token, @param string $newPassword
     * 📤 Output: @return array {success: bool, error?: string}
     *
     * 🛡️ Security:
     * - transaction: เปลี่ยน password + mark token used พร้อมกัน
     * - token ใช้ได้ครั้งเดียว (mark as used)
     *
     * ✅ Use case: reset_password.php POST
     */
    public function resetPassword(string $token, string $newPassword): array
    {
        require_once __DIR__ . '/../Repositories/PasswordResetRepository.php';
        $resetRepo = new \App\Repositories\PasswordResetRepository($this->pdo);
        
        // 📝 Step 1: ตรวจ token (3 เงื่อนไขใน query เดียว: ตรง + ไม่หมดอายุ + ยังไม่ใช้)
        $resetRequest = $resetRepo->findValidToken($token);
        if (!$resetRequest) {
            return ['success' => false, 'error' => 'ลิงก์ไม่ถูกต้องหรือหมดอายุ'];
        }
        
        try {
            // 📝 Step 2: เปิด transaction — ต้อง update password + mark token ใน atomic operation
            //    ถ้า update password สำเร็จแต่ mark token ล้มเหลว → rollback ทั้งหมด
            $this->pdo->beginTransaction();
            
            // 🔒 [WRITE] เปลี่ยนรหัสผ่าน
            $this->userRepo->updatePassword($resetRequest['user_id'], hashPassword($newPassword));
            
            // 🛡️ [SECURITY] Mark token ว่าใช้แล้ว — ป้องกันใช้ token ซ้ำ
            //    ⚠️ ต้องเรียกเสมอ! ถ้าไม่ mark → token ใช้ซ้ำได้ (security hole)
            $resetRepo->markUsed($resetRequest['id']);
            
            $this->pdo->commit();
            return ['success' => true];
        } catch (\Exception $e) {
            // ❌ rollback ทั้งหมด → password ไม่ถูกเปลี่ยน + token ยังใช้ได้
            $this->pdo->rollBack();
            error_log("[AuthService::resetPassword] error: " . $e->getMessage());
            return ['success' => false, 'error' => 'เกิดข้อผิดพลาด กรุณาลองใหม่'];
        }
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ตรวจ token ว่ายังใช้ได้ไหม (ก่อนแสดงฟอร์ม)
     * ==========================================================================
     *
     * 📥 Input: @param string $token
     * 📤 Output: @return array|null reset request data หรือ null
     * ✅ Use case: reset_password.php GET (แสดงฟอร์มถ้า token valid)
     */
    public function validateResetToken(string $token): ?array
    {
        // 📝 ตรวจ token ว่ายังใช้ได้ไหม (ก่อนแสดง form)
        //    null = token ไม่ valid → Controller แสดง error แทน form
        require_once __DIR__ . '/../Repositories/PasswordResetRepository.php';
        $resetRepo = new \App\Repositories\PasswordResetRepository($this->pdo);
        return $resetRepo->findValidToken($token);
    }
    
    // =========================================================================
    // HELPERS
    // =========================================================================
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึง UserRepository instance (สำหรับใช้งานที่ไม่ต้อง logic)
     * ==========================================================================
     *
     * 📤 Output: @return UserRepository
     * ✅ Use case: controller ที่ต้องการเข้าถึง repo โดยตรง (read-only)
     */
    public function getUserRepository(): UserRepository
    {
        // 📤 คืน repo instance — ให้ Controller เข้าถึง repo โดยตรง (read-only)
        //    เช่น profile.php ดึง user data โดยไม่ต้องเขียน service method ใหม่
        return $this->userRepo;
    }
}
