<?php
/**
 * MemberService - Business Logic สำหรับการจัดการสมาชิก
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * Service นี้จัดการ CRUD ผู้ใช้ (member + staff, ไม่รวม admin):
 * - สร้าง/แก้ไข/ลบ + import CSV + เปลี่ยน role
 * - generate random password ถ้าไม่ระบุ
 * - validate + duplicate check
 *
 * 🏗️ สถาปัตยกรรม:
 * Controller → MemberService → UserRepository
 *                             → BorrowRepository (เช็คก่อนลบ)
 *                             → ReservationRepository (เช็คก่อนลบ)
 *
 * 📍 Entrypoints:
 * - admin/members.php      → getMembers()
 * - admin/member_form.php  → createMember(), updateMember(role?), updatePassword()
 * - api/add_member.php     → createMember() (quick add)
 * - register.php           → createMember() (ผ่าน AuthService)
 * - admin/import_members   → importMember()
 *
 * 🛡️ Security Design:
 * - createMember(): hash password ก่อน INSERT เสมอ
 * - emailExists(): single source of truth สำหรับ duplicate check
 * - deleteMember(): ตรวจ borrow history + pending reservation ก่อนลบ
 * - updateMember(): role whitelist เฉพาะ member/staff (ป้องกัน privilege escalation)
 *
 * ⚠️ ห้ามแก้:
 * - createMember() ต้อง hash password ก่อน save
 * - deleteMember() ห้ามลบถ้ามีประวัติการยืม
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
    // 🗄️ PDO + Repositories
    private PDO $pdo;
    private UserRepository $userRepo;
    private BorrowRepository $borrowRepo;
    private ReservationRepository $reservationRepo;

    // 🏗️ Constructor: สร้าง repo ทั้งหมด — ใช้ PDO เดียวกัน
    //    BorrowRepo + ReservationRepo ใช้สำหรับ guard ก่อนลบ
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->userRepo = new UserRepository($pdo);
        $this->borrowRepo = new BorrowRepository($pdo);
        $this->reservationRepo = new ReservationRepository($pdo);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการสมาชิก + filters (pass-through)
     * ==========================================================================
     *
     * 📥 Input: @param array $filters {search?, status?, role?, sort?}
     * 📤 Output: @return array รายการผู้ใช้ (member + staff)
     * ✅ Use case: admin/members.php
     */
    public function getMembers(array $filters = []): array
    {
        // 📝 Pass-through → findMembers (member + staff + search/status/role/sort)
        return $this->userRepo->findMembers($this->withQuotaRule($filters));
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: เติมเพดานโควตาให้ filter ก่อนส่งลง Repository
     * ==========================================================================
     * 🧠 [F-48] กฎธุรกิจ ("ยืมได้สูงสุดกี่เล่ม") อยู่ที่ชั้น Service
     *    Repository แค่รับตัวเลขมาใส่ SQL — ไม่ต้องรู้จัก MAX_BORROW_BOOKS
     * 🔴 ต้องเรียกทั้งใน getMembers() และ countFilteredMembers()
     *    ถ้าเรียกที่เดียว ยอดรวมกับรายการจะใช้เพดานคนละค่าแล้วนับไม่ตรงกัน
     */
    private function withQuotaRule(array $filters): array
    {
        // 👔 [โควตาตาม role] ส่งเพดานของทั้งสองกลุ่มลงไป ให้ SQL เลือกใช้ตาม role ของแต่ละแถว
        //    🔴 ส่งค่าเดียวไม่ได้แล้ว — ตารางสมาชิกมีทั้ง member และ staff ปนกันในหน้าเดียว
        //       ถ้าใช้เพดานเดียวกันหมด เจ้าหน้าที่ที่เพดาน 10 จะขึ้นว่า "เต็มโควตา" ตั้งแต่เล่มที่ 3
        $filters['quota_limit']       = MAX_BORROW_BOOKS;
        $filters['quota_limit_staff'] = MAX_BORROW_BOOKS_STAFF;
        return $filters;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับจำนวนสมาชิกที่ตรงเงื่อนไข (สำหรับแบ่งหน้า)
     * ==========================================================================
     * 📝 Pass-through → countFilteredMembers()
     * ✅ Use case: admin/members.php ต้องรู้ยอดรวมเพื่อคำนวณว่ามีกี่หน้า
     * ⚠️ ต้องส่ง $filters ชุดเดียวกับที่ส่งให้ getMembers() ไม่งั้นยอดกับรายการจะไม่ตรงกัน
     * ⚠️ ห้ามสับสนกับ countMembers() ด้านล่างที่นับสมาชิกทั้งระบบ (ไม่สนใจ filter)
     */
    public function countFilteredMembers(array $filters = []): int
    {
        return $this->userRepo->countFilteredMembers($this->withQuotaRule($filters));
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงผู้ใช้ตาม ID (เฉพาะ member/staff)
     * ==========================================================================
     *
     * 📥 Input: @param int $id
     * 📤 Output: @return array|null (ไม่รวม password) หรือ null
     * ✅ Use case: admin/member_form.php (edit mode)
     */
    public function getMemberById(int $id): ?array
    {
        // 📝 Pass-through → findMemberById (เฉพาะ member/staff, ไม่รวม admin)
        return $this->userRepo->findMemberById($id);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สร้างสมาชิกใหม่ (Single Source of Truth)
     * ==========================================================================
     *
     * 🔄 Flow: validate → check email → generate password (optional) → hash → INSERT
     *
     * 📥 Input: @param array $data {name, email, phone?, password?}
     * 📤 Output: @return array {id, name, email, password (คืน plain ครั้งเดียว)}
     * @throws Exception ถ้าข้อมูลไม่ครบ / email ซ้ำ
     *
     * 🧠 เหตุผล: คืน plain password ครั้งเดียวสำหรับแสดงให้ admin เห็น
     * ✅ Use case: admin/member_form.php POST, register.php, api/add_member.php
     */
    public function createMember(array $data, bool $mustChangePassword = false): array
    {
        // 📝 Step 1: Validate ผ่าน shared helper (Single Source of Truth)
        //    validateMemberData() อยู่ใน functions.php — ใช้ร่วมกับ import
        $errors = validateMemberData($data);
        if (!empty($errors)) {
            throw new Exception($errors[0]);
        }

        // 📝 Step 2: ตรวจ email ซ้ำ
        if ($this->emailExists($data['email'])) {
            throw new Exception('อีเมลนี้ถูกใช้งานแล้ว');
        }

        // 📝 Step 3: ถ้าไม่ระบุ password → generate random 8 ตัว
        $password = !empty($data['password']) ? $data['password'] : $this->generateRandomPassword();
        // 📝 Step 4: hash password แล้ว INSERT
        //    🔴 ต้อง hash ก่อน INSERT เสมอ! ห้ามเก็บ plaintext
        $memberId = $this->userRepo->create([
            'name' => trim($data['name']),
            'email' => trim($data['email']),
            'phone' => trim($data['phone'] ?? ''),
            'password' => hashPassword($password),
            'role' => 'member',
            // 🔑 [F-53] default false = คนที่สมัครเอง (register.php) ตั้งรหัสเองอยู่แล้ว ไม่ต้องบังคับ
            //    ผู้เรียกที่ admin เป็นคนรู้รหัส (member_form.php, api/add_member.php) ส่ง true มา
            'must_change_password' => $mustChangePassword ? 1 : 0
        ]);

        // 📤 คืน plain password ครั้งเดียวสำหรับแสดงให้ admin เห็น
        //    ⚠️ หลังจากนี้ไม่มีทางดึง plaintext กลับมาได้
        return [
            'id' => $memberId,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $password
        ];
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: อัปเดตข้อมูลสมาชิก (name, email, phone)
     * ==========================================================================
     *
     * 🔄 Flow: findMemberById → check email duplicate (exclude self) → update
     *
     * 📥 Input: @param int $id, @param array $data {name, email, phone?, role?}
     * 📤 Output: @return bool true = สำเร็จ
     * @throws Exception ถ้าไม่พบ / email ซ้ำ
     * ✅ Use case: admin/member_form.php POST (edit mode)
     */
    public function updateMember(int $id, array $data): bool
    {
        // 📝 Step 1: ตรวจว่า member มีอยู่
        $member = $this->getMemberById($id);
        if (!$member) {
            throw new Exception('ไม่พบสมาชิก');
        }

        // 📝 Step 2: ตรวจ email ซ้ำ (ยกเว้นตัวเอง)
        //    เช็คเฉพาะเมื่อ email เปลี่ยน — ป้องกันบอกว่า "ซ้ำ" กับตัวเอง
        if (!empty($data['email']) && $data['email'] !== $member['email']) {
            if ($this->emailExists($data['email'])) {
                throw new Exception('อีเมลนี้ถูกใช้งานแล้ว');
            }
        }

        // 📝 Step 3: UPDATE (ไม่รวม password — แยกเป็น updatePassword())
        $updateData = [
            'name' => trim($data['name']),
            'email' => trim($data['email']),
            'phone' => trim($data['phone'] ?? '')
        ];

        // 🏷️ role update (optional — เฉพาะเมื่อ admin ส่งมา, whitelist ป้องกัน privilege escalation)
        if (isset($data['role']) && in_array($data['role'], ['member', 'staff'])) {
            $updateData['role'] = $data['role'];
        }

        return $this->userRepo->update($id, $updateData);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ลบสมาชิก + ตรวจ 2 เงื่อนไขก่อนลบ
     * ==========================================================================
     *
     * 🔄 Flow: BEGIN TX → check borrow history → check pending reservation → DELETE → COMMIT
     *
     * 📥 Input: @param int $id
     * 📤 Output: @return bool true = สำเร็จ
     * @throws Exception ถ้ามีประวัติ/pending reservation
     *
     * 🧠 เหตุผล:
     * - CASCADE DELETE จะลบ borrows ทำให้สถิติเสียหาย
     * - CASCADE DELETE จะลบ reservation แต่ไม่คืน stock
     * - TX ป้องกัน race condition ระหว่าง guard check กับ DELETE
     *
     * ✅ Use case: admin/members.php (action=delete)
     */
    public function deleteMember(int $id): bool
    {
        // � [ATOMIC] ใช้ Transaction ครอบ guard + DELETE
        //    ป้องกัน race condition: guard ผ่าน → ระหว่างนั้นมี borrow ใหม่ → DELETE ทำให้ stock หาย
        //    ถ้า guard ผ่านแต่ DELETE ล้มเหลว → rollback ทั้งหมด (ไม่มีผลข้างเคียง)
        $this->pdo->beginTransaction();

        try {
            // ��️ Guard #1: มีประวัติการยืมหรือไม่
            //    CASCADE DELETE จะลบ borrows → สถิติเสียหาย
            if ($this->borrowRepo->countByUser($id) > 0) {
                throw new Exception('ไม่สามารถลบได้ สมาชิกมีประวัติการยืม');
            }

            // 🛡️ Guard #2: มี pending reservation หรือไม่
            //    CASCADE DELETE จะลบ reservation แต่ stock ไม่ถูกคืน!
            // 🧠 ต้องนับคิวรอด้วย — ลบสมาชิกที่ยังต่อคิวค้างอยู่ไม่ได้
            if ($this->reservationRepo->countActiveByUser($id) > 0) {
                // 🧠 [F-46] ด่านนี้ใช้ countActiveByUser() ซึ่งนับทั้งการจองที่รอมารับ **และคิวรอ**
                //    ข้อความเดิมพูดถึงแค่ "การจอง" ทำให้คนอ่านหาไม่เจอว่าอะไรบล็อกอยู่
                throw new Exception('ไม่สามารถลบได้ สมาชิกยังมีการจองที่รอมารับ หรือคิวรออยู่ กรุณายกเลิกก่อน');
            }

            // 📝 ผ่าน guard แล้ว → ลบ (role IN member/staff ป้องกันลบ admin)
            $result = $this->userRepo->deleteMember($id);

            $this->pdo->commit();
            return $result;

        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            error_log("[MemberService::deleteMember] memberId={$id} PDOException: " . $e->getMessage());
            // 🛡️ FK RESTRICT safety net: ถ้า guard ไม่ทัน (race condition)
            //    DB จะป้องกันลบให้ → แปลง error เป็นภาษาคนอ่านออก
            throw new Exception('ไม่สามารถลบได้ สมาชิกมีข้อมูลที่เกี่ยวข้องในระบบ');
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("[MemberService::deleteMember] memberId={$id} error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: บอกเหตุผลว่าทำไมลบสมาชิกคนนี้ไม่ได้ (สำหรับแสดงบน UI)
     * ==========================================================================
     *
     * 📥 Input: @param array $member แถวจาก UserRepository::findMembers()
     *           (ต้องมี total_borrows, active_borrows, pending_reservations)
     * 📤 Output: @return string|null ข้อความเหตุผล หรือ null = ลบได้
     *
     * 🧠 เหตุผล: เงื่อนไขต้องตรงกับ guard ใน deleteMember() เป๊ะ ๆ
     *   ก่อนหน้านี้หน้า list เช็คแค่ active_borrows ทำให้ปุ่มลบเปิดใช้งาน
     *   ทั้งที่สมาชิกมีประวัติการยืม → กดแล้วเจอ error ทุกครั้ง
     *   ⚠️ แก้ guard ใน deleteMember() เมื่อไหร่ ต้องแก้ที่นี่ด้วย
     *
     * ✅ Use case: admin/members.php (disable ปุ่มลบ + tooltip บอกเหตุผล)
     */
    public function getDeleteBlockReason(array $member): ?string
    {
        if (($member['active_borrows'] ?? 0) > 0) {
            return 'ไม่สามารถลบได้ เนื่องจากกำลังยืมหนังสืออยู่';
        }
        if (($member['total_borrows'] ?? 0) > 0) {
            return 'ไม่สามารถลบได้ เนื่องจากมีประวัติการยืม';
        }
        if (($member['pending_reservations'] ?? 0) > 0) {
            return 'ไม่สามารถลบได้ เนื่องจากยังมีการจองที่รอมารับ หรือคิวรออยู่';
        }
        return null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: อัปเดตรหัสผ่านสมาชิก (admin reset)
     * ==========================================================================
     *
     * 🔄 Flow: validate password → hash → update
     *
     * 📥 Input: @param int $id, @param string $plainPassword
     * @throws Exception ถ้า password ไม่ผ่าน validation
     * ✅ Use case: admin/member_form.php → reset password
     */
    public function updatePassword(int $id, string $plainPassword): void
    {
        // 📝 Step 1: validate password (ความยาว ฯลฯ)
        if ($err = validatePassword($plainPassword)) {
            throw new Exception($err);
        }
        // 📝 Step 2: hash แล้ว update
        //    🔴 ห้ามส่ง plaintext ไป repo!
        //
        // 🔑 [F-53 ต่อ] true = **ผู้ดูแลเป็นคนตั้งรหัสให้คนอื่น**
        //    เมธอดนี้มีจุดเรียกเดียวคือ admin/member_form.php — คือเส้นทาง
        //    "สมาชิกลืมรหัส เดินมาที่เคาน์เตอร์ ผู้ดูแลตั้งรหัสใหม่ให้"
        //    ซึ่งเป็นทางแก้เดียวที่มี เพราะระบบไม่ส่งอีเมล
        //
        //    🔴 ผู้ดูแล **รู้รหัสนั้น** ถ้าไม่บังคับให้เปลี่ยน สมาชิกจะใช้รหัสที่คนอื่นรู้
        //       ไปตลอด = รูเดียวกับที่ F-53 ตั้งใจปิดตอนสร้างบัญชีใหม่ แต่เปิดค้างอยู่
        //       บนเส้นทางรีเซ็ต (เดิม repo เขียน must_change_password = 0 ตายตัว
        //       จึงไม่ใช่แค่ "ไม่ได้ตั้งธง" แต่ **ล้างธงทิ้ง**)
        $this->userRepo->updatePassword($id, hashPassword($plainPassword), true);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ตรวจ email ซ้ำ (Single Source of Truth)
     * ==========================================================================
     *
     * 📥 Input: @param string $email, @param int|null $excludeId (ยกเว้น edit)
     * 📤 Output: @return bool true = มีอยู่แล้ว (ห้ามใช้)
     * ✅ Use case: createMember(), updateMember(), api/check_email.php
     */
    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        // 📝 Pass-through → Single Source of Truth สำหรับ duplicate check
        return $this->userRepo->emailExists($email, $excludeId);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงประวัติการยืมของสมาชิก (pass-through)
     * ==========================================================================
     *
     * 📥 Input: @param int $memberId, @param int $limit
     * 📤 Output: @return array[] borrow rows + book info
     * ✅ Use case: api/member_history.php, ReportService
     */
    public function getBorrowHistory(int $memberId, int $limit = 20): array
    {
        // 📝 Pass-through → ประวัติการยืม + book info
        return $this->borrowRepo->findByUserId($memberId, $limit);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สถิติสมาชิกรายคน (pass-through)
     * ==========================================================================
     *
     * 📥 Input: @param int $memberId
     * 📤 Output: @return array {total_borrows, active_borrows, returned, total_fines}
     * ✅ Use case: ReportService (สถิติรายคน)
     */
    public function getMemberStatistics(int $memberId): array
    {
        // 📝 Pass-through → {total_borrows, active_borrows, returned, total_fines}
        return $this->borrowRepo->getStatsByUser($memberId);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับสมาชิกทั้งหมด (pass-through)
     * ==========================================================================
     *
     * 📤 Output: @return int
     * ✅ Use case: DashboardService, HomeService
     */
    public function countMembers(): int
    {
        // 📝 Pass-through → COUNT(*) WHERE role='member'
        return $this->userRepo->countMembers();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: Import สมาชิก (Create หรือ Update ถ้า email ซ้ำ)
     * ==========================================================================
     *
     * 🔄 Flow: validate → findByEmail → มีอยู่? update : create
     *
     * 📥 Input: @param array $data {name, email, phone?}, @param string $defaultPassword
     * 📤 Output: @return array {action: 'created'|'updated', id: int}
     *
     * 🧠 เหตุผล: update เฉพาะ name + phone (ไม่เปลี่ยน password เดิม)
     * ✅ Use case: admin/import_members.php
     */
    public function importMember(array $data, ?string $defaultPassword = null): array
    {
        // 🔑 [F-53] รหัสเริ่มต้นมาจาก config ไม่ฝังในโค้ดแล้ว — ลูกค้าตั้งผ่าน .env ได้
        $defaultPassword = $defaultPassword ?? IMPORT_DEFAULT_PASSWORD;

        // 📝 Step 1: trim ข้อมูล
        $email = trim($data['email']);
        $name = trim($data['name']);
        $phone = trim($data['phone'] ?? '');
        
        // 📝 Step 2: validate (ไม่ต้องส่ง password เพราะ import ใช้ default)
        $errors = validateMemberData(['name' => $name, 'email' => $email, 'phone' => $phone]);
        if (!empty($errors)) {
            throw new Exception($errors[0]);
        }
        
        // 📝 Step 3: ตรวจว่ามีอยู่แล้วหรือไม่ (ตาม email)
        $existing = $this->userRepo->findByEmail($email);
        
        if ($existing) {
            // 🔄 มีอยู่แล้ว → UPDATE เฉพาะ name + phone (ไม่เปลี่ยน password เดิม)
            $this->userRepo->update($existing['id'], [
                'name' => $name,
                'email' => $email,
                'phone' => $phone
            ]);
            return ['action' => 'updated', 'id' => $existing['id']];
        } else {
            // ✨ ยังไม่มี → INSERT ด้วย default password
            //    🔑 [F-53] ทุกคนได้รหัสเดียวกัน จึง **บังคับเปลี่ยนตอนล็อกอินครั้งแรก**
            //       ไม่งั้นรหัสนี้กลายเป็นกุญแจร่วมที่ใช้ได้ตลอดกาล
            //       และอีเมลของห้องสมุดโรงเรียนมักเดาได้เป็นชุด (std0001@, std0002@, ...)
            //    ⚠️ ตั้งเฉพาะแถวที่ **สร้างใหม่** — แถวที่ upsert ไม่แตะรหัสเดิมอยู่แล้ว
            //       จึงต้องไม่ไปบังคับคนที่ตั้งรหัสของตัวเองไปนานแล้ว
            $memberId = $this->userRepo->create([
                'name' => $name,
                'email' => $email,
                'password' => hashPassword($defaultPassword),
                'phone' => $phone,
                'role' => 'member',
                'must_change_password' => 1
            ]);
            return ['action' => 'created', 'id' => $memberId];
        }
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สร้าง random password (internal helper)
     * ==========================================================================
     *
     * 📥 Input: @param int $length (default: 8)
     * 📤 Output: @return string plaintext password
     * ⚠️ ใช้ str_shuffle — ไม่ cryptographically secure แต่พอสำหรับ temp password
     * ✅ Use case: createMember() ถ้าไม่ระบุ password
     */
    private function generateRandomPassword(int $length = 8): string
    {
        // 📝 สร้าง random password 8 ตัว (a-z + 0-9)
        //    ⚠️ str_shuffle ไม่ใช่ cryptographically secure
        //    แต่เพียงพอสำหรับ temp password (ผู้ใช้ควรเปลี่ยนเอง)
        return substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, $length);
    }
}
