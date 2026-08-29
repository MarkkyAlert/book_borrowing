<?php
/**
 * UserRepository - Data Access Layer สำหรับผู้ใช้งาน
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * Repository นี้จัดการ CRUD สำหรับตาราง users (ผู้ใช้ทั้งหมดในระบบ)
 * รองรับ 3 roles: admin, staff, member
 *
 * 📚 โครงสร้างตาราง users:
 * +------------+--------------+------------------------------------------+
 * | Column     | Type         | อธิบาย                                     |
 * +------------+--------------+------------------------------------------+
 * | id         | INT AUTO PK  | Primary Key                              |
 * | name       | VARCHAR      | ชื่อ-นามสกุล                             |
 * | email      | VARCHAR UNQ  | อีเมล (unique, ใช้ login)               |
 * | phone      | VARCHAR NULL | เบอร์โทร (ไม่บังคับ)                    |
 * | password   | VARCHAR      | bcrypt hash (ห้ามเก็บ plaintext)      |
 * | role       | ENUM         | 'admin', 'staff', 'member'               |
 * | created_at | DATETIME     | วันสมัคร                                   |
 * +------------+--------------+------------------------------------------+
 *
 * 📍 Entrypoints:
 * - AuthService      → findByEmail() (login), create() (register), updatePassword() (reset)
 * - MemberService    → findMemberById(), create(), update(), deleteMember(), emailExists()
 * - admin/members.php → findMembers() (แสดงรายการผู้ใช้ member+staff)
 * - DashboardService → countMembers(), countNewThisMonth()
 * - BorrowService    → lockById() (row lock ก่อนยืม)
 *
 * 🛡️ Security Design:
 * - password เก็บเป็น bcrypt hash เสมอ (ห้ามเก็บ plaintext)
 * - findByEmail() คืน password hash สำหรับ login เท่านั้น
 * - findById()/findMemberById() ไม่คืน password กลับ (SELECT เฉพาะ column)
 * - lockById() ใช้ FOR UPDATE ป้องกัน race condition
 *
 * ⚠️ ห้ามแก้:
 * - findByEmail() return password hash — ใช้สำหรับ login เท่านั้น
 * - create() รับ hashed password — ห้ามส่ง plaintext
 * - lockById() ต้องเรียกใน transaction เท่านั้น
 *
 * @package App\Repositories
 */

namespace App\Repositories;

use PDO;

class UserRepository
{
    // 🗄️ PDO connection — inject ผ่าน constructor ใช้ร่วมกันทุกเมธอด
    private PDO $pdo;

    // 🏗️ Constructor: รับ PDO จากภายนอก (Dependency Injection)
    // → ใช้ connection เดียวกับ AuthService / MemberService / BorrowService
    // → ทำให้ transaction + FOR UPDATE lock ทำงานถูกต้อง
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงผู้ใช้ทั้งหมดตาม filters (ไม่คืน password)
     * ==========================================================================
     *
     * 📥 Input:
     * @param array $filters {role?: string, search?: string}
     *              - role: กรองตาม role เช่น 'member'
     *              - search: ค้นใน name, email, phone (LIKE)
     *
     * 📤 Output:
     * @return array [{id, name, email, phone, role, created_at}, ...]
     *
     * 🛡️ Security: SELECT เฉพาะ column (ไม่รวม password) + prepared statement
     * ✅ Use case: ดึง users ตาม role/search filter ทั่วไป
     */
    public function findAll(array $filters = []): array
    {
        // 📦 สร้างเงื่อนไข WHERE จาก filters
        $where = [];
        $params = [];

        // 🏷️ Filter: role (เช่น 'member', 'staff', 'admin')
        if (!empty($filters['role'])) {
            $where[] = "role = ?";
            $params[] = $filters['role'];
        }

        // 🔍 Filter: ค้นหาใน name, email, phone (LIKE)
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $where[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ?)";
            // 📌 array_merge เพราะต้องต่อท้าย params จาก role filter (ถ้ามี)
            $params = array_merge($params, ["%{$search}%", "%{$search}%", "%{$search}%"]);
        }

        // 🔗 ประกอบ WHERE clause
        $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // 📝 SQL: ดึง users (ไม่รวม password) + filters
        // 🛡️ SELECT เฉพาะ column — ไม่รวม password hash
        $stmt = $this->pdo->prepare("
            SELECT id, name, email, phone, role, created_at 
            FROM users 
            {$whereSQL}
            ORDER BY name, id
        ");
        $stmt->execute($params);
        // 📤 คืน array users (ไม่มี password)
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงผู้ใช้ทั้งหมด (member + staff, ไม่รวม admin)
     * ==========================================================================
     *
     * 📤 Output: @return array รายการผู้ใช้ (ไม่รวม password)
     * ✅ Use case: admin/borrow_form.php → dropdown เลือกผู้ยืม
     */
    public function findAllMembers(): array
    {
        // � SQL: ดึง member + staff (ไม่รวม admin) เรียงตามชื่อ
        // 🛡️ SELECT เฉพาะ column — ไม่รวม password hash
        $stmt = $this->pdo->query("
            SELECT id, name, email, phone, role, created_at
            FROM users
            WHERE role IN ('member', 'staff')
            ORDER BY name, id
        ");
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงผู้ใช้ตาม ID (ไม่คืน password)
     * ==========================================================================
     *
     * 📥 Input: @param int $id User ID
     * 📤 Output: @return array|null {id, name, email, phone, role, created_at} หรือ null
     *
     * 🛡️ Security: SELECT เฉพาะ column — ไม่รวม password hash
     * ✅ Use case: getCurrentUser() ใน functions.php, profile.php
     */
    public function findById(int $id): ?array
    {
        // 📝 SQL: ดึง user ตาม ID (ไม่รวม password)
        // 🛡️ SELECT เฉพาะ column — ไม่รวม password hash (ปลอดภัย)
        $stmt = $this->pdo->prepare("
            SELECT id, name, email, phone, role, must_change_password, created_at 
            FROM users WHERE id = ?
        ");
        $stmt->execute([$id]);
        // 📤 คืน user data (ไม่มี password) หรือ null
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงผู้ใช้ตาม email (รวม password hash สำหรับ login)
     * ==========================================================================
     * เมธอดนี้เป็นเมธอดเดียวที่ SELECT * (รวม password)
     * เพราะ AuthService ต้องใช้ password_verify() เทียบกับ hash
     *
     * 📥 Input: @param string $email อีเมลที่ user กรอกตอน login
     * 📤 Output: @return array|null ข้อมูลทั้งหมด (รวม password hash) หรือ null
     *
     * 🛡️ Security:
     * - คืน password hash เฉพาะ AuthService ใช้ — ห้ามส่งกลับไป client
     * - ไม่แยกว่า "email ไม่พบ" หรือ "password ผิด" — ป้องกัน user enumeration
     *
     * ⚠️ Edge case: email ไม่มีในระบบ → null → AuthService แสดง "อีเมลหรือรหัสผ่านไม่ถูกต้อง"
     * ✅ Use case: login.php → AuthService::login() → findByEmail()
     */
    public function findByEmail(string $email): ?array
    {
        // 📝 SQL: SELECT * รวม password hash (เมธอดเดียวที่คืน password)
        // 🔴 เมธอดนี้ใช้สำหรับ login เท่านั้น!
        //    AuthService ใช้ password_verify() เทียบกับ hash
        //    ห้ามส่ง password hash กลับไป client!
        $stmt = $this->pdo->prepare("
            SELECT * FROM users WHERE email = ?
        ");
        $stmt->execute([$email]);
        // 📤 คืน user data (รวม password hash) หรือ null
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงผู้ใช้ตาม ID (เฉพาะ member/staff ไม่คืน password)
     * ==========================================================================
     *
     * 📥 Input: @param int $id User ID
     * 📤 Output: @return array|null {id, name, email, phone, role, created_at} หรือ null
     *
     * 🧠 เหตุผล:
     * - ทำไมแยกจาก findById()? เพราะกรอง role ป้องกันแก้ไข admin ผ่านเมธอดนี้
     * - รวม staff เพราะ admin อาจ promote member → staff แล้วต้องแก้ไข/demote กลับได้
     *
     * ✅ Use case: admin/member_form.php, admin/member_card.php
     */
    public function findMemberById(int $id): ?array
    {
        // 📝 SQL: ดึงผู้ใช้ตาม ID (member + staff)
        // 🛡️ role IN (...) ป้องกันดึง/แก้ไข admin ผ่านเมธอดนี้
        // 🛡️ SELECT เฉพาะ column — ไม่รวม password hash
        $stmt = $this->pdo->prepare("
            SELECT id, name, email, phone, role, created_at 
            FROM users WHERE id = ? AND role IN ('member', 'staff')
        ");
        $stmt->execute([$id]);
        // 📤 คืน user data หรือ null (ถ้า id เป็น admin → null)
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สร้างผู้ใช้ใหม่ (INSERT users)
     * ==========================================================================
     *
     * 📥 Input:
     * @param array $data {name, email, password(ต้อง hash แล้ว), phone?, role?}
     *              - มาจาก: MemberService::createMember(), AuthService::register()
     *
     * 📤 Output: @return int ID ของ user ที่สร้าง
     *
     * 🛡️ Security:
     * - password ต้องเป็น hash แล้ว (ห้ามส่ง plaintext) — Service layer hash ก่อนเรียก
     * - prepared statement ป้องกัน SQL Injection
     *
     * ⚠️ Edge case: email ซ้ำ → UNIQUE violation → PDOException
     *    Service ต้องเช็ค emailExists() ก่อน
     *
     * ✅ Use case:
     * 1) register.php → AuthService → MemberService::createMember() → create()
     * 2) admin/member_form.php → MemberService::createMember() → create()
     */
    public function create(array $data): int
    {
        // 📝 SQL: INSERT user ใหม่
        // 🔴 $data['password'] ต้องเป็น hash แล้ว! (ห้ามส่ง plaintext)
        //    Service layer ต้อง password_hash() ก่อนเรียก
        $stmt = $this->pdo->prepare("
            INSERT INTO users (name, email, phone, password, role, must_change_password)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        // 🚀 bind ค่าทั้งหมด
        $stmt->execute([
            $data['name'],              // ชื่อ (บังคับ)
            $data['email'],             // อีเมล (บังคับ, UNIQUE)
            $data['phone'] ?? null,     // เบอร์โทร (ไม่บังคับ)
            $data['password'],          // password hash (บังคับ, ต้องเป็น hash!)
            $data['role'] ?? 'member',  // role (default: member)
            // 🔑 [F-53] default 0 = ไม่บังคับ — คนที่ตั้งรหัสเอง (register.php) ต้องไม่โดน
            //    ผู้เรียกที่ "รู้รหัสของผู้ใช้" (import, admin สร้างให้) ต้องส่ง 1 มาเอง
            !empty($data['must_change_password']) ? 1 : 0
        ]);

        // 📤 คืน user ID ที่สร้าง (AUTO_INCREMENT)
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: อัปเดตข้อมูลผู้ใช้ (ไม่รวม password)
     * ==========================================================================
     *
     * 📥 Input:
     * @param int   $id   User ID
     * @param array $data {name, email, phone?, role?} - มาจาก MemberService, AuthService
     *
     * 📤 Output: @return bool true = สำเร็จ
     *
     * 🧠 เหตุผล: แยก update password ออกเป็น updatePassword() ต่างหาก
     *    เพื่อป้องกันการ update profile แล้วทับ password โดยไม่ตั้งใจ
     *
     * ✅ Use case: MemberService::updateMember(), AuthService::updateProfile()
     */
    public function update(int $id, array $data): bool
    {
        // 📝 SQL: UPDATE ข้อมูล user (ไม่รวม password)
        // 🧠 แยก update password ออกเป็น updatePassword() ต่างหาก
        //    ป้องกัน update profile แล้วทับ password โดยไม่ตั้งใจ
        $sets = ['name = ?', 'email = ?', 'phone = ?'];
        $params = [
            $data['name'],              // 1. ชื่อ
            $data['email'],             // 2. อีเมล
            $data['phone'] ?? null,     // 3. เบอร์โทร (null ถ้าไม่ระบุ)
        ];

        // 🏷️ role update (optional — เฉพาะเมื่อ admin ส่งมา)
        if (isset($data['role'])) {
            $sets[] = 'role = ?';
            $params[] = $data['role'];
        }

        $params[] = $id; // WHERE id = ?
        $stmt = $this->pdo->prepare("
            UPDATE users SET " . implode(', ', $sets) . "
            WHERE id = ?
        ");

        return $stmt->execute($params);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: อัปเดตรหัสผ่าน (เฉพาะ password)
     * ==========================================================================
     *
     * 📥 Input:
     * @param int    $id             User ID
     * @param string $hashedPassword password ที่ hash แล้ว (ห้ามส่ง plaintext!)
     *                               - มาจาก: AuthService::resetPassword(), changePassword()
     *
     * 📤 Output: @return bool true = สำเร็จ
     *
     * 🛡️ Security: รับเฉพาะ hash — Service layer ต้อง hashPassword() ก่อนเรียก
     * ✅ Use case:
     * 1) reset_password.php → AuthService::resetPassword() → updatePassword()
     * 2) profile.php → AuthService::changePassword() → updatePassword()
     * 3) admin/member_form.php → MemberService → updatePassword()
     */
    public function updatePassword(int $id, string $hashedPassword): bool
    {
        // 📝 SQL: เปลี่ยนเฉพาะ password (ไม่แตะข้อมูลอื่น)
        // 🔴 $hashedPassword ต้องเป็น hash แล้ว! (ห้ามส่ง plaintext)
        //    Service layer ต้อง password_hash() ก่อนเรียก
        // 🔑 [F-53] เคลียร์ธง "ต้องเปลี่ยนรหัส" พร้อมกันในคำสั่งเดียว
        //    🧠 ทำที่นี่จุดเดียว เพราะเมธอดนี้เป็นทางผ่าน **เดียว** ของการเปลี่ยนรหัสทุกทาง:
        //       AuthService::changePassword() (หน้าโปรไฟล์ + หน้าบังคับเปลี่ยน)
        //       AuthService::resetPassword()  (ลิงก์ลืมรหัสผ่าน)
        //    ถ้าไปเคลียร์ที่ชั้น Service จะต้องจำให้ครบทุกทาง — ลืมทางใดทางหนึ่งแล้ว
        //    ผู้ใช้จะติดอยู่ในหน้าบังคับเปลี่ยนรหัสวนไม่จบ ทั้งที่เปลี่ยนสำเร็จแล้ว
        $stmt = $this->pdo->prepare("
            UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?
        ");
        return $stmt->execute([$hashedPassword, $id]);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ลบผู้ใช้ (ทุก role)
     * ==========================================================================
     *
     * 📥 Input: @param int $id User ID
     * 📤 Output: @return bool true = สำเร็จ
     *
     * ⚠️ Edge case: ถ้ามี borrow/reservation อยู่ → FK constraint → PDOException
     *    ควรใช้ deleteMember() แทน (กรอง role='member')
     *    หรือเรียกผ่าน MemberService::deleteMember() ที่ตรวจเงื่อนไขก่อน
     */
    public function delete(int $id): bool
    {
        // 📝 SQL: ลบ user ทุก role (ไม่กรอง role)
        // ⚠️ ถ้ามี borrow/reservation ค้าง → FK constraint → PDOException
        //    ควรใช้ deleteMember() แทน (กรอง role='member' ปลอดภัยกว่า)
        //    หรือเรียกผ่าน MemberService::deleteMember() ที่ตรวจเงื่อนไขก่อน
        $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ตรวจว่า email ซ้ำหรือไม่ (ก่อน register/update)
     * ==========================================================================
     *
     * 📥 Input:
     * @param string   $email     email ที่ตรวจ
     * @param int|null $excludeId ID ยกเว้น (ใช้ตอน update — ไม่นับตัวเอง)
     *
     * 📤 Output: @return bool true = มีอยู่แล้ว (ห้ามใช้)
     *
     * 🧠 เหตุผล: เหมือน CategoryRepository::nameExists() — $excludeId ป้องกันนับตัวเองตอน update
     * ✅ Use case:
     * 1) register.php → MemberService → emailExists('new@mail.com')
     * 2) admin/member_form.php → MemberService → emailExists('new@mail.com', 5)
     */
    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        // 📝 SQL เริ่มต้น: นับจำนวน user ที่ email ตรงกัน
        $sql = "SELECT COUNT(*) FROM users WHERE email = ?";
        $params = [$email];

        // 🧠 $excludeId = ยกเว้น ID ตัวเอง (ใช้ตอน update)
        //    เช่น แก้ไข user ID=5 ที่มี email "a@b.com"
        //    ต้องยกเว้น ID=5 ออก ไม่งั้นจะบอกว่า "email ซ้ำ" กับตัวเอง
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        // 📤 > 0 = email ซ้ำ (true → ห้ามใช้), = 0 = ใช้ได้ (false)
        return $stmt->fetchColumn() > 0;
    }

    // NOTE: hasBorrowHistory() removed - use BorrowRepository::countByUser() > 0
    // เหตุผล: ลด duplication, BorrowRepository เป็น owner ของ borrows table

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับจำนวนสมาชิกทั้งหมด (สำหรับ dashboard)
     * ==========================================================================
     *
     * 📤 Output: @return int จำนวน member
     * ✅ Use case: admin/index.php → DashboardService → "จำนวนสมาชิก"
     */
    public function countMembers(): int
    {
        // 📝 SQL: นับจำนวนสมาชิกทั้งหมด (เฉพาะ member ไม่รวม admin/staff)
        // 🧠 ใช้ query() เพราะไม่มี user input
        return (int) $this->pdo->query("
            SELECT COUNT(*) FROM users WHERE role = 'member'
        ")->fetchColumn();
    }

    // NOTE: getMemberStatistics() removed - use BorrowRepository::getStatsByUser()
    // เหตุผล: BorrowRepository เป็น owner ของ borrows table และมี COALESCE ป้องกัน null

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: Lock user row ป้องกัน race condition (SELECT FOR UPDATE)
     * ==========================================================================
     * ใช้ล็อค row ของ user ระหว่างทำ transaction
     * ป้องกันหลาย request ทำงานพร้อมกัน เช่น ยืมหนังสือ 2 เล่มพร้อมกัน
     *
     * 📥 Input: @param int $id User ID
     * 📤 Output: @return array|null {id} (ถูก lock จน commit/rollback)
     *
     * 🛡️ Security:
     * - FOR UPDATE = row-level lock — request อื่นต้องรอ
     * - ต้องเรียกภายใน transaction เท่านั้น (ไม่งั้น lock ไม่ทำงาน)
     *
     * ✅ Use case: BorrowService::createBorrow() → lockById() ก่อนตรวจโควต้ายืม
     */
    public function lockById(int $id): ?array
    {
        // 📝 SQL: SELECT id + FOR UPDATE = ดึง + ล็อกแถว
        // 🔴 FOR UPDATE = row-level lock:
        //    request อื่นที่อยากแก้ user นี้ต้อง "wait" จน transaction นี้จบ
        //    ป้องกันยืมหนังสือ 2 เล่มพร้อมกัน (race condition)
        // ⚠️ ต้องเรียกใน transaction (beginTransaction...commit) เท่านั้น!
        // 🧠 SELECT เฉพาะ id (ไม่ต้องการข้อมูลอื่น แค่ล็อกแถว)
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        // 📤 คืน {id} (ถูกล็อก) หรือ null
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการผู้ใช้ (member+staff) พร้อม filters, sorting และสถิติการยืม
     * ==========================================================================
     * เมธอดหลักสำหรับหน้า admin/members.php
     *
     * 🔄 Flow: SELECT users + subquery(total_borrows, active_borrows)
     *        + WHERE filters + HAVING status + ORDER BY sort
     *
     * 📥 Input:
     * @param array $filters {
     *     search?: string  — ค้นใน name, email, phone (LIKE)
     *     role?: string    — 'member' | 'staff' (กรองตาม role)
     *     status?: string  — 'has_borrow' | 'no_borrow' (HAVING)
     *     sort?: string    — 'newest', 'oldest', 'az', 'za', 'most_borrows'
     * }
     *
     * 📤 Output:
     * @return array[] แต่ละ element: user row + total_borrows, active_borrows
     *
     * 🧠 เหตุผลเชิงออกแบบ:
     * - ใช้ subquery แทน JOIN เพราะไม่ต้องการ GROUP BY users
     * - HAVING ใช้กรอง status เพราะเป็นค่าจาก subquery (WHERE ไม่ได้)
     * - sort ใช้ switch-case แปลงเป็น ORDER BY clause
     *
     * 🛡️ Security: prepared statement, sort whitelist (ไม่ใช้ user input ตรงใน ORDER BY)
     * ✅ Use case: admin/members.php GET
     */
    public function findMembers(array $filters = []): array
    {
        // 🔧 สร้างเงื่อนไข + การเรียงลำดับ (ใช้ร่วมกับ countFilteredMembers ให้ผลตรงกันเสมอ)
        [$whereSQL, $havingSQL, $params, $orderBy] = $this->buildMemberQuery($filters);

        // 📄 แบ่งหน้า — ใส่ LIMIT/OFFSET เฉพาะตอนที่ผู้เรียกส่ง limit มาเท่านั้น
        // 🧠 ไม่ส่ง limit = ดึงทั้งหมดเหมือนเดิม (export/สคริปต์ยังต้องได้ครบทุกแถว)
        // 🛡️ [SECURITY] cast เป็น int + clamp → ปลอดภัยแม้ค่ามาจาก $_GET
        $limitSQL = '';
        if (isset($filters['limit'])) {
            $limitSQL = 'LIMIT ? OFFSET ?';
            $params[] = max(1, (int) $filters['limit']);
            $params[] = max(0, (int) ($filters['offset'] ?? 0));
        }

        // 📝 SQL: ดึงสมาชิก + subquery นับการยืม
        // 🧠 subquery แทน JOIN เพราะไม่ต้องการ GROUP BY users
        //    total_borrows = ยืมทั้งหมด (borrowing + returned)
        //    active_borrows = ยืมค้างอยู่ (borrowing only)
        // 🧠 `, u.id DESC` คือตัวตัดสินเมื่อค่าที่เรียงเท่ากัน — ถ้าไม่มี การเรียงจะไม่คงที่
        //    ทำให้กดหน้า 2 แล้วเจอสมาชิกซ้ำจากหน้า 1 หรือบางคนหายไปเลย
        $stmt = $this->pdo->prepare("
            SELECT u.*,
                   {$this->memberComputedColumns()}
            FROM users u
            {$whereSQL}
            {$havingSQL}
            ORDER BY {$orderBy}, u.id DESC
            {$limitSQL}
        ");
        $stmt->execute($params);
        // 📤 คืน array สมาชิก + สถิติการยืม
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับจำนวนสมาชิกที่ตรงเงื่อนไข filter (ไม่สนใจ LIMIT)
     * ==========================================================================
     * ✅ Use case: admin/members.php ต้องรู้ยอดรวมเพื่อคำนวณจำนวนหน้า
     *
     * ⚠️ ห้ามสับสนกับ countMembers() ที่นับสมาชิกทั้งระบบสำหรับ dashboard
     *    ตัวนี้นับ "ตามที่กรองอยู่" เท่านั้น
     *
     * 🧠 ทำไมต้องห่อเป็น derived table (SELECT COUNT(*) FROM ( ... ) t):
     *    filter สถานะการยืมใช้ HAVING บนค่าที่มาจาก subquery
     *    ถ้าเขียน SELECT COUNT(*) ... HAVING ตรง ๆ MySQL จะกรองทีหลังจากยุบเหลือแถวเดียว
     *    → ได้ยอดผิด (0 หรือ 1) แทนที่จะเป็นจำนวนสมาชิกจริง
     */
    public function countFilteredMembers(array $filters = []): int
    {
        [$whereSQL, $havingSQL, $params] = $this->buildMemberQuery($filters);

        // 🔴 [F-48] ต้องใช้คอลัมน์คำนวณ **ชุดเดียวกับ findMembers()** เป๊ะ
        //    ไม่งั้น HAVING ที่อ้างคอลัมน์ซึ่งมีแค่ฝั่งเดียวจะทำให้ query นี้พัง
        //    แล้วหน้าจะขาวทั้งหน้าทันทีที่มีคนกดตัวกรองนั้น
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM (
                SELECT u.id,
                       {$this->memberComputedColumns()}
                FROM users u
                {$whereSQL}
                {$havingSQL}
            ) t
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: แปลง $filters → WHERE + HAVING + params + ORDER BY
     * ==========================================================================
     * 🧠 ทำไมแยกออกมา: findMembers() กับ countFilteredMembers() ต้องกรองเหมือนกันเป๊ะ
     *    ไม่งั้นจะเจออาการ "บอกว่ามี 137 คน แต่กดหน้าสุดท้ายแล้วว่างเปล่า"
     *
     * 📤 Output: [$whereSQL, $havingSQL, $params, $orderBy]
     * 🛡️ [SECURITY] user input bind ผ่าน ? · ORDER BY มาจาก whitelist เท่านั้น
     */
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: คอลัมน์คำนวณของตารางสมาชิก — **แหล่งเดียว**
     * ==========================================================================
     * 🔴 [F-48] ต้องอยู่ในทั้ง findMembers() และ countFilteredMembers()
     *    เดิม countFilteredMembers() มีแค่ active_borrows ตัวเดียว
     *    พอเพิ่ม HAVING ที่อ้างคอลัมน์อื่น ตัวนับจะพังด้วย "Unknown column"
     *    = หน้าขาวทั้งหน้าทันทีที่มีคนกดตัวกรองนั้น
     *    ดึงมาไว้ที่เดียวเพื่อไม่ให้สองฟังก์ชันเขียนไม่ตรงกันได้อีก
     *
     * 🧠 unpaid_fine_total ใช้เงื่อนไขเดียวกับ BorrowRepository::getUnpaidDebtors()
     *    (fine_amount > 0 · ยังไม่มีใบเสร็จ · ยังไม่ถูกยกเว้น)
     *    ถ้านิยามต่างกัน ตัวเลขบนหน้าสมาชิกกับหน้าการเงินจะไม่ตรงกัน
     */
    private function memberComputedColumns(): string
    {
        return "
                   (SELECT COUNT(*) FROM borrows WHERE user_id = u.id) as total_borrows,
                   (SELECT COUNT(*) FROM borrows WHERE user_id = u.id AND status = 'borrowing') as active_borrows,
                   (SELECT COUNT(*) FROM reservations WHERE user_id = u.id AND status = 'pending') as pending_reservations,
                   -- 🔴 waiting = ต่อคิวรอ **ไม่กินโควตายืม** (ROADMAP ข้อ 5)
                   --    ต้องแยกจาก pending ให้ขาด ไม่งั้นหน้าจอจะบอกว่าเต็มโควตา
                   --    ทั้งที่คนนั้นยังยืมได้อีก — ดู ReservationRepository::countPendingByUser()
                   (SELECT COUNT(*) FROM reservations WHERE user_id = u.id AND status = 'waiting') as waiting_reservations,
                   (SELECT COALESCE(SUM(b.fine_amount), 0)
                      FROM borrows b
                      LEFT JOIN payments p ON b.id = p.borrow_id
                     WHERE b.user_id = u.id
                       AND b.fine_amount > 0
                       AND p.id IS NULL
                       AND b.fine_waived_at IS NULL) as unpaid_fine_total";
    }

    private function buildMemberQuery(array $filters): array
    {
        // 📝 แสดง member + staff (ไม่รวม admin)
        $where = ["role IN ('member', 'staff')"];
        $params = [];

        // 🏷️ Filter: กรองตาม role (member | staff)
        $roleFilter = $filters['role'] ?? '';
        if (in_array($roleFilter, ['member', 'staff'])) {
            $where[] = "role = ?";
            $params[] = $roleFilter;
        }

        // 🔍 Filter: ค้นหาใน name, email, phone (LIKE)
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $where[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        // 🔗 ประกอบ WHERE clause
        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        // 🏷️ Status filter: กรองตามสถานะการยืม
        // 🧠 ใช้ HAVING ไม่ใช้ WHERE เพราะ active_borrows มาจาก subquery
        //    WHERE กรองได้แค่ column จริง / HAVING กรองได้ค่าจาก subquery/aggregate
        $having = [];
        $status = $filters['status'] ?? '';
        if ($status === 'has_borrow') {
            $having[] = "active_borrows > 0";
        } elseif ($status === 'no_borrow') {
            $having[] = "active_borrows = 0";
        } elseif ($status === 'quota_full') {
            // 🔴 [F-48] "เต็มโควตา" = ยืมค้าง + จองที่ของพร้อมแล้ว >= เพดาน
            //    สูตรเดียวกับ BorrowService::borrowBook() ที่ตัดสินว่ายืมเพิ่มได้ไหม
            //    ⚠️ ห้ามเอา waiting_reservations มารวม — คิวรอไม่กินโควตา (F-41)
            //    ถ้ารวม คนที่ต่อคิว 3 เล่มจะขึ้นว่าเต็มโควตาทั้งที่ยังยืมได้อีก
            // 🧠 เพดานส่งมาจากชั้น Service ไม่ให้ Repository อ่าน constant เอง
            $having[] = "(active_borrows + pending_reservations) >= ?";
            $params[] = max(1, (int) ($filters['quota_limit'] ?? 1));
        } elseif ($status === 'has_unpaid_fine') {
            // 🔴 [F-48] ใช้นิยามเดียวกับหน้าการเงิน — ดู memberComputedColumns()
            $having[] = "unpaid_fine_total > 0";
        }
        $havingSQL = $having ? 'HAVING ' . implode(' AND ', $having) : '';

        // 📊 Sort mapping (whitelist ป้องกัน SQL Injection)
        // 🛡️ ไม่ใช้ user input ตรงๆ ใน ORDER BY — ใช้ switch แปลงเป็นค่าที่อนุญาต
        $orderBy = 'u.created_at DESC';
        $sort = $filters['sort'] ?? 'newest';
        switch ($sort) {
            case 'oldest': $orderBy = 'u.created_at ASC'; break;
            case 'az': $orderBy = 'u.name ASC'; break;
            case 'za': $orderBy = 'u.name DESC'; break;
            case 'most_borrows': $orderBy = 'total_borrows DESC'; break;
            default: $orderBy = 'u.created_at DESC'; break;
        }

        return [$whereSQL, $havingSQL, $params, $orderBy];
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ลบผู้ใช้ (เฉพาะ member/staff — ป้องกันลบ admin)
     * ==========================================================================
     *
     * 📥 Input: @param int $id User ID
     * 📤 Output: @return bool true = สำเร็จ
     *
     * 🧠 เหตุผล:
     * - ทำไมแยกจาก delete()? เพราะกรอง role ป้องกันลบ admin โดยไม่ตั้งใจ
     *
     * ⚠️ Edge case: ถ้ามี borrow/reservation อยู่ → FK constraint → PDOException
     *    ควรเรียกผ่าน MemberService::deleteMember() ที่ตรวจเงื่อนไขก่อน
     *
     * ✅ Use case: admin/members.php → MemberService::deleteMember() → deleteMember()
     */
    public function deleteMember(int $id): bool
    {
        // 📝 SQL: ลบเฉพาะ member/staff (ป้องกันลบ admin)
        // 🛡️ role IN (...) ป้องกันลบ admin โดยไม่ตั้งใจ
        //    แม้ ID จะตรงกับ admin → DELETE 0 rows (ไม่ลบ)
        // ⚠️ ถ้ามี borrow/reservation ค้าง → FK constraint → PDOException
        //    ควรเรียกผ่าน MemberService::deleteMember() ที่ตรวจเงื่อนไขก่อน
        $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = ? AND role IN ('member', 'staff')");
        return $stmt->execute([$id]);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับสมาชิกใหม่เดือนนี้ (สำหรับ dashboard)
     * ==========================================================================
     *
     * 📤 Output: @return int จำนวน member ที่สมัครเดือนนี้
     * ✅ Use case: admin/index.php → DashboardService → "สมาชิกใหม่เดือนนี้"
     */
    public function countNewThisMonth(): int
    {
        // 📝 SQL: นับสมาชิกใหม่เดือนนี้ (role='member' + MONTH+YEAR)
        // 🧠 กรองทั้ง MONTH + YEAR ป้องกันดึงข้ามปี (ม.ค.ปีนี้ vs ม.ค.ปีก่อน)
        return (int) $this->pdo->query("
            SELECT COUNT(*) FROM users 
            WHERE role = 'member' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())
        ")->fetchColumn();
    }
}

