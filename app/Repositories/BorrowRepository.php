<?php
/**
 * BorrowRepository - Database Access สำหรับการยืม-คืน
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * Repository นี้จัดการ SQL queries สำหรับตาราง borrows (การยืม-คืนหนังสือ)
 * เป็นตารางหลักของระบบ — เก็บประวัติการยืม-คืนทั้งหมด
 *
 * 📚 โครงสร้างตาราง borrows:
 * +-------------+--------------+----------------------------------------------+
 * | Column      | Type         | อธิบาย                                       |
 * +-------------+--------------+----------------------------------------------+
 * | id          | INT AUTO PK  | Primary Key                                  |
 * | user_id     | INT FK       | ผู้ยืม → users.id                          |
 * | book_id     | INT FK       | หนังสือ → books.id                         |
 * | borrow_date | DATE         | วันยืม                                       |
 * | due_date    | DATE         | กำหนดคืน                                    |
 * | return_date | DATE NULL    | วันคืนจริง (NULL = ยังไม่คืน)              |
 * | status      | ENUM         | 'borrowing', 'returned'                      |
 * | fine_amount | DECIMAL NULL | ค่าปรับ (คำนวณตอนคืน)                    |
 * | created_at  | DATETIME     | เวลาสร้าง record                           |
 * +-------------+--------------+----------------------------------------------+
 *
 * � Entrypoints:
 * - BorrowService      → create(), markAsReturned(), findByIdForUpdate(),
 *                        countActiveBorrowsForUpdate(), isAlreadyBorrowing()
 * - admin/borrows.php  → findAll() (แสดงรายการ)
 * - my_borrows.php     → findByUserIdPaginated() (ประวัติสมาชิก)
 * - DashboardService   → findOverdue(), findRecent(), countOverdue(), countActive()
 * - BookService        → countByBook(), countActiveByBook()
 * - MemberService      → countByUser()
 * - profile.php        → getStatsByUser(), getUnpaidFinesByUser()
 *
 * 🛡️ Security Design:
 * - findByIdForUpdate() / findByIdForUpdateAnyStatus() = row lock ป้องกัน race
 * - countActiveBorrowsForUpdate() = lock + count ป้องกันยืมเกินโควต้า
 * - ทุก method ใช้ prepared statements
 *
 * ⚠️ ห้ามแก้:
 * - findByIdForUpdate() ต้องเรียกใน transaction
 * - countActiveBorrowsForUpdate() ต้องเรียกใน transaction
 *
 * @package App\Repositories
 *
 * ==========================================================================
 * 🗺️ Quick Map — เมธอดไหนใช้กับ flow ไหน
 * ==========================================================================
 *
 * 📖 ยืมหนังสือ (BorrowService::createBorrow):
 *   create()                       → INSERT รายการยืมใหม่
 *   countActiveBorrowsForUpdate()  → นับ + lock เช็คโควต้าก่อนยืม
 *   isAlreadyBorrowing()           → เช็คยืมซ้ำ
 *
 * 🔄 คืนหนังสือ (BorrowService::returnBook):
 *   findByIdForUpdate()            → lock + ดึง borrow ที่ยังยืมอยู่
 *   markAsReturned()               → UPDATE status='returned'
 *
 * 💰 จ่ายค่าปรับ (BorrowService::payFine):
 *   findByIdForUpdateAnyStatus()   → lock borrow (ทุก status)
 *
 * 📊 Dashboard (DashboardService):
 *   findOverdue()                  → รายการเกินกำหนด
 *   findRecent()                   → ยืมล่าสุด
 *   countOverdue()                 → badge สีแดง
 *   countActive()                  → การ์ดสถิติ
 *
 * 📝 หน้าแสดงรายการ (admin/borrows.php):
 *   findAll()                      → ดึงทั้งหมด + filter
 *   findById()                     → ดึงเฉพาะ ID
 *
 * 👤 สมาชิก (my_borrows.php / profile.php):
 *   findByUserIdPaginated()        → ประวัติยืม + pagination
 *   getStatsByUser()               → สถิติของสมาชิก
 *   getUnpaidFinesByUser()         → ค่าปรับค้างชำระ
 *
 * 📕 หน้าหนังสือ (book.php):
 *   findCurrentByBook()            → ใครยืมอยู่
 *   findHistoryByBook()            → ประวัติการยืม
 *
 * 🚨 ตรวจก่อนลบ (BookService / MemberService):
 *   countByBook()                  → เช็คก่อนลบหนังสือ
 *   countActiveByBook()            → เช็คมีคนยืมอยู่ไหม
 *   countByUser()                  → เช็คก่อนลบสมาชิก
 *
 * 💵 ค่าปรับ (admin/payments.php):
 *   getUnpaidFinesList()           → รายการค้างชำระ
 *   getTotalUnpaidFines()          → ยอดรวมค้างชำระ
 *
 * ==========================================================================
 * ⚠️ Danger Zones — จุดเสี่ยงในไฟล์นี้
 * ==========================================================================
 *
 * 1. 🔴 findByIdForUpdate() / findByIdForUpdateAnyStatus() / countActiveBorrowsForUpdate()
 *    - ใช้ SELECT ... FOR UPDATE → ต้องเรียกใน transaction เท่านั้น
 *    - ถ้าเรียกนอก transaction → lock จะไม่ทำงาน → race condition เกิดได้
 *    - ห้ามลบ FOR UPDATE ออก!
 *
 * 2. 🔴 findAll() / findByUserIdPaginated() ต่อ SQL string ด้วย $where
 *    - $where มาจาก code ภายใน (ไม่ใช่ user input ตรงๆ) → ปลอดภัย
 *    - แต่ user input ถูก bind ผ่าน ? placeholder → ปลอดภัย
 *    - ห้ามต่อ user input เข้า $where โดยตรง!
 *
 * 3. 🟡 getUnpaidFinesList() / getTotalUnpaidFines() / getUnpaidFinesByUser()
 *    - ใช้ LEFT JOIN payments + IS NULL ตรวจว่ายังไม่จ่าย
 *    - ถ้าเพิ่มระบบจ่ายบางส่วน → logic พัง (เหมือน ReportRepository)
 *
 * 4. 🟡 countOverdue() / countActive() ใช้ query() ไม่ใช่ prepare()
 *    - ไม่มี user input → ปลอดภัย
 *    - ถ้าเพิ่ม parameter → ต้องเปลี่ยนเป็น prepare()
 *
 * 5. 🟢 markAsReturned() ใช้ CURDATE() ของ MySQL
 *    - return_date = CURDATE() → ไม่ส่งจาก PHP
 *    - ถ้า timezone MySQL ≠ PHP → วันที่อาจคลาดเคลื่อน
 */

namespace App\Repositories;

use PDO;

class BorrowRepository
{
    // 🗄️ PDO connection — ใช้ร่วมกันทุกเมธอด (inject ผ่าน constructor)
    private PDO $pdo;

    // 🏗️ Constructor: รับ PDO จากภายนอก (Dependency Injection)
    // → ใช้ connection เดียวกับ BorrowService ที่เรียก
    // → สำคัญมาก: ทำให้ transaction + FOR UPDATE lock ทำงานได้ถูกต้อง
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการยืมทั้งหมด พร้อม user_name, book_title
     * ==========================================================================
     * เมธอดหลักสำหรับหน้า admin/borrows.php
     *
     * 🔄 Flow: borrows JOIN users JOIN books + WHERE filters + ORDER BY created_at DESC
     *
     * 📥 Input:
     * @param array $filters {
     *     search?: string  — ค้นใน user_name, email, book_title (LIKE)
     *     status?: string  — 'borrowing' | 'returned'
     *     user_id?: int    — กรองเฉพาะ user
     *     overdue?: bool   — เฉพาะเกินกำหนด
     *     due_today?: bool — เฉพาะครบกำหนดวันนี้
     * }
     *
     * 📤 Output:
     * @return array [{borrow row + user_name, user_email, user_phone, book_title, book_author}, ...]
     *
     * 🛡️ Security: prepared statement + LIKE parameterized
     * ✅ Use case: admin/borrows.php GET
     */
    public function findAll(array $filters = []): array
    {
        // 🔄 Flow: admin/borrows.php (GET) → findAll(filters)
        // 🎯 ดึงรายการยืม + JOIN user/book + filter ตามเงื่อนไข (+ แบ่งหน้าถ้าส่ง limit มา)
        [$whereSQL, $params] = $this->buildListConditions($filters);

        // 📄 แบ่งหน้า — ใส่ LIMIT/OFFSET เฉพาะตอนที่ผู้เรียกส่ง limit มาเท่านั้น
        // 🧠 ไม่ส่ง limit = ดึงทั้งหมดเหมือนเดิม (รายงาน/สคริปต์ยังต้องได้ครบทุกแถว)
        // 🛡️ [SECURITY] cast เป็น int + clamp → ปลอดภัยแม้ค่ามาจาก $_GET
        $limitSQL = '';
        if (isset($filters['limit'])) {
            $limitSQL = 'LIMIT ? OFFSET ?';
            $params[] = max(1, (int) $filters['limit']);
            $params[] = max(0, (int) ($filters['offset'] ?? 0));
        }

        // 📝 SQL อธิบาย:
        //    - SELECT b.* → ดึงทุกคอลัมน์จาก borrows
        //    - JOIN users + books → เอาชื่อสมาชิก + ชื่อหนังสือมาแสดง
        //    - {$whereSQL} → เงื่อนไขที่สร้างจาก filters
        //    - ORDER BY created_at DESC → รายการล่าสุดขึ้นก่อน
        //      🧠 `, b.id DESC` คือตัวตัดสินเมื่อ created_at เท่ากัน — ถ้าไม่มี
        //         การเรียงจะไม่คงที่ ทำให้กดหน้า 2 แล้วเจอรายการซ้ำหรือตกหล่น
        //
        // 📤 Output: [{borrow fields + user_name, user_email, user_phone, book_title, book_author}, ...]
        // ⚠️ user_email + user_phone อยู่ในผลลัพธ์ → ต้อง escape ก่อนแสดงใน HTML
        // 💰 bk.price as book_price → ใช้เติมค่าตั้งต้นในกล่องแจ้งหาย/ชำรุด
        // ⚠️ หน้าไหนต้องอ่านคอลัมน์ใหม่ของ books ต้องมาเพิ่มใน SELECT ตรงนี้ด้วย
        //    (เคยพลาดกับ is_reference มาแล้ว — ด่านมีอยู่แต่ไม่ทำงานเพราะไม่ได้ดึงคอลัมน์มา)
        $stmt = $this->pdo->prepare("
            SELECT b.*, u.name as user_name, u.email as user_email, u.phone as user_phone,
                   bk.title as book_title, bk.author as book_author,
                   bk.price as book_price
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            {$whereSQL}
            ORDER BY b.created_at DESC, b.id DESC
            {$limitSQL}
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับจำนวนรายการยืมทั้งหมดที่ตรงเงื่อนไข (ไม่สนใจ LIMIT)
     * ==========================================================================
     * ✅ Use case: admin/borrows.php ต้องรู้ยอดรวมเพื่อคำนวณจำนวนหน้า
     * 🧠 ใช้ buildListConditions() ตัวเดียวกับ findAll() — ยอดนับกับรายการที่แสดง
     *    จึงมาจากเงื่อนไขชุดเดียวกันเสมอ
     */
    public function countAll(array $filters = []): int
    {
        [$whereSQL, $params] = $this->buildListConditions($filters);

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            {$whereSQL}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: แปลง $filters → WHERE clause + params
     * ==========================================================================
     * 🧠 ทำไมแยกออกมา: findAll() กับ countAll() ต้องกรองเหมือนกันเป๊ะ
     *    ไม่งั้นจะเจออาการ "บอกว่ามี 137 รายการ แต่หน้าสุดท้ายว่างเปล่า"
     *
     * ⚠️ Danger Zone #2: $where ถูกต่อเข้า SQL แต่ค่ามาจาก code ภายใน
     *    user input ทั้งหมด bind ผ่าน ? → ปลอดภัยจาก SQL Injection
     */
    private function buildListConditions(array $filters): array
    {
        $where = [];
        $params = [];

        // 🔍 filter: search → ค้นหาในชื่อสมาชิก, email, ชื่อหนังสือ
        // SQL: LIKE ? → bind "%keyword%" (3 ค่า เพราะค้น 3 คอลัมน์)
        // 🛡️ search ถูก bind ผ่าน ? → ปลอดภัยจาก SQL Injection
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $where[] = "(u.name LIKE ? OR u.email LIKE ? OR bk.title LIKE ?)";
            $params = array_merge($params, ["%{$search}%", "%{$search}%", "%{$search}%"]);
        }

        // 🔍 filter: status → กรองตามสถานะ ('borrowing' หรือ 'returned')
        if (!empty($filters['status'])) {
            $where[] = "b.status = ?";
            $params[] = $filters['status'];
        }

        // 🔍 filter: user_id → กรองเฉพาะ user
        if (!empty($filters['user_id'])) {
            $where[] = "b.user_id = ?";
            $params[] = $filters['user_id'];
        }

        // 🔍 filter: overdue → เฉพาะเกินกำหนด (borrowing + due_date < วันนี้)
        if (isset($filters['overdue']) && $filters['overdue']) {
            $where[] = "b.status = 'borrowing' AND b.due_date < CURDATE()";
        }

        // 🔍 filter: due_today → เฉพาะครบกำหนดวันนี้
        if (isset($filters['due_today']) && $filters['due_today']) {
            $where[] = "b.status = 'borrowing' AND b.due_date = CURDATE()";
        }

        // 📝 รวม WHERE clauses ด้วย AND → ถ้าไม่มี filter → $whereSQL = '' (ดึงทั้งหมด)
        $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        return [$whereSQL, $params];
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการยืมตาม ID (พร้อม user_name, book_title)
     * ==========================================================================
     *
     * 📥 Input: @param int $id Borrow ID
     * 📤 Output: @return array|null borrow row + user_name + book_title หรือ null
     * ✅ Use case: admin/borrows.php?view=ID, BorrowService::returnBook()
     */
    public function findById(int $id): ?array
    {
        // 🔄 Flow: admin/borrows.php?view=ID, BorrowService::returnBook()
        // 🎯 ดึง borrow เดียวตาม ID พร้อมชื่อสมาชิก + ชื่อหนังสือ
        //
        // 📝 SQL: JOIN users + books → เอาชื่อมาแสดง / WHERE b.id = ?
        // 📤 Output: borrow row + user_name + book_title หรือ null (ไม่พบ)
        // 📝 fetch() ?: null → ถ้าไม่พบจะ return null (ไม่ใช่ false)
        $stmt = $this->pdo->prepare("
            SELECT b.*, u.name as user_name, bk.title as book_title
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            WHERE b.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: Lock borrow row (เฉพาะ status='borrowing') + SELECT FOR UPDATE
     * ==========================================================================
     * ใช้สำหรับ returnBook() — lock + กรองเฉพาะ borrowing
     *
     * 📥 Input: @param int $id Borrow ID
     * 📤 Output: @return array|null borrow row (ถูก lock) หรือ null
     *
     * 🛡️ Security:
     * - FOR UPDATE = row lock ป้องกันคืนซ้ำ / staff 2 คนกดพร้อมกัน
     * - AND status='borrowing' กรองเฉพาะที่ยังยืมอยู่
     * - ต้องเรียกใน transaction
     *
     * ✅ Use case: BorrowService::returnBook()
     */
    public function findByIdForUpdate(int $id): ?array
    {
        // 🔄 Flow: BorrowService::returnBook() → findByIdForUpdate()
        // 🎯 lock + ดึง borrow ที่ยังยืมอยู่ เพื่อดำเนินการคืน
        //
        // 📝 SQL: SELECT * FROM borrows WHERE id = ? AND status = 'borrowing' FOR UPDATE
        //    - AND status = 'borrowing' → กรองเฉพาะที่ยังยืมอยู่ (ถ้าคืนแล้ว → return null)
        //    - FOR UPDATE → 🔒 lock แถวนี้ไว้ จนกว่า transaction จบ
        //      ป้องกัน: staff 2 คนกดคืนพร้อมกัน → คนที่ 2 ต้องรอ
        //
        // ⚠️ Danger Zone #1: ต้องเรียกใน transaction เท่านั้น!
        //    ถ้าเรียกนอก transaction → lock ไม่ทำงาน → race condition
        //    🔴 ห้ามลบ FOR UPDATE ออก!
        $stmt = $this->pdo->prepare("
            SELECT * FROM borrows WHERE id = ? AND status = 'borrowing' FOR UPDATE
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: Lock borrow row (ทุก status) + SELECT FOR UPDATE
     * ==========================================================================
     * ใช้สำหรับ payFine() — ต้องการหา borrow ที่ returned แล้วด้วย
     *
     * 📥 Input: @param int $id Borrow ID
     * 📤 Output: @return array|null borrow row (ถูก lock) หรือ null
     *
     * 🧠 เหตุผล:
     * - ทำไมแยกจาก findByIdForUpdate()? เพราะไม่กรอง status
     *   payFine() ต้องการ borrow ที่ returned แล้ว (ไม่ใช่ borrowing)
     *
     * ✅ Use case: BorrowService::payFine()
     */
    public function findByIdForUpdateAnyStatus(int $id): ?array
    {
        // 🔄 Flow: BorrowService::payFine() → findByIdForUpdateAnyStatus()
        // 🎯 lock borrow row โดยไม่กรอง status — ใช้กับการจ่ายค่าปรับ
        //
        // 🧠 ทำไมแยกจาก findByIdForUpdate()?
        //    เพราะ payFine() ต้องการ borrow ที่ returned แล้ว (ไม่ใช่ borrowing)
        //    findByIdForUpdate() กรอง status='borrowing' → จะไม่เจอ borrow ที่คืนแล้ว
        //
        // 📝 SQL: SELECT * FROM borrows WHERE id = ? FOR UPDATE
        //    - ไม่มี AND status = ... → ดึงได้ทุก status
        //    - FOR UPDATE → lock ป้องกันจ่ายซ้ำ
        //
        // ⚠️ ต้องเรียกใน transaction เหมือน findByIdForUpdate()
        $stmt = $this->pdo->prepare("
            SELECT * FROM borrows WHERE id = ? FOR UPDATE
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ต่ออายุการยืม — เลื่อน due_date + นับจำนวนครั้ง
     * ==========================================================================
     *
     * 📥 Input:
     * @param int    $borrowId รายการยืม
     * @param string $newDue   กำหนดคืนใหม่ (Y-m-d) — Service คำนวณมาให้แล้ว
     *
     * 📤 Output: @return bool true = อัปเดตสำเร็จ
     *
     * 🛡️ เงื่อนไขใน WHERE เป็นด่านสุดท้าย กันต่ออายุซ้ำแม้ยิงพร้อมกัน 2 ครั้ง:
     *    - status = 'borrowing' → คืนไปแล้วต่อไม่ได้
     *    - renew_count < MAX     → เกินโควตาต่อไม่ได้
     *    (คู่กับ FOR UPDATE lock ที่ Service)
     *
     * ⚠️ ไม่แตะ available — ต่ออายุไม่เปลี่ยนสต็อก หนังสือยังอยู่กับคนเดิม
     *
     * ✅ Use case: BorrowService::renewBorrow()
     */
    public function renewBorrow(int $borrowId, string $newDue, int $maxRenew): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE borrows
            SET due_date = ?, renew_count = renew_count + 1
            WHERE id = ? AND status = 'borrowing' AND renew_count < ?
        ");
        $stmt->execute([$newDue, $borrowId, $maxRenew]);

        return $stmt->rowCount() > 0;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ปิดรายการยืมเป็น "หาย" หรือ "ชำรุด" + บันทึกค่าชดใช้
     * ==========================================================================
     *
     * 📥 Input:
     * @param int    $borrowId     รายการยืม
     * @param string $status       'lost' | 'damaged' (Service ตรวจมาแล้ว)
     * @param float  $charge       ค่าชดใช้ที่คิดจริง — **snapshot ไว้ ณ ตอนแจ้ง**
     * @param int|null $reportedBy ผู้แจ้ง (users.id)
     * @param string $note         รายละเอียด/เหตุผล (บังคับกรอกที่ชั้น Service)
     *
     * 📤 Output: @return bool true = อัปเดตสำเร็จ
     *
     * 🧠 ทำไมเก็บค่าชดใช้ลง fine_amount ไม่สร้างคอลัมน์ใหม่:
     *    payments มี UNIQUE(borrow_id) → 1 รายการยืมมีได้แค่ 1 การชำระอยู่แล้ว
     *    และกติกาคือ "ค่าชดใช้แทนที่ค่าปรับเกินกำหนด ไม่คิดซ้ำ"
     *    ทำแบบนี้หน้ารับชำระ/ยกเว้น/รายงานรายได้ทำงานต่อได้ทันที
     *    โดยไม่ต้องสร้างระบบเงินคู่ขนาน — ดูว่าเป็นเงินประเภทไหนได้จาก status
     *
     * 🔴 ห้ามอ่านราคาสดจาก books.price ตอนแสดงยอดค้าง — ต้องใช้ค่าที่ snapshot ไว้นี้
     *    ไม่งั้นบรรณารักษ์แก้ราคาหนังสือทีหลัง หนี้ที่ค้างอยู่จะเปลี่ยนตามเงียบ ๆ
     *
     * ⚠️ **ไม่แตะ return_date** — รายงาน "คืนวันนี้/คืนเดือนนี้" นับจาก return_date
     *    โดยไม่กรอง status (ดู ReportRepository::getDailySummary / getRevenueReport)
     *    ถ้าใส่ return_date ให้เล่มที่หาย ตัวเลขคืนจะพองด้วยเล่มที่ไม่เคยถูกคืน
     *
     * 🛡️ WHERE status = 'borrowing' → กันแจ้งซ้ำแม้ยิงพร้อมกัน 2 ครั้ง
     *    (คู่กับ FOR UPDATE lock ที่ Service — สำคัญมาก เพราะ Service จะลด quantity ตาม)
     *
     * ✅ Use case: BorrowService::markAsLost()
     */
    public function markAsLost(int $borrowId, string $status, float $charge, ?int $reportedBy, string $note): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE borrows
            SET status = ?, fine_amount = ?,
                lost_reported_at = NOW(), lost_reported_by = ?, lost_note = ?
            WHERE id = ? AND status = 'borrowing'
        ");
        $stmt->execute([$status, $charge, $reportedBy, $note, $borrowId]);

        // 📌 rowCount() = 0 แปลว่ามีคนแจ้งไปแล้ว หรือคืนไปแล้ว → ไม่ถือว่าสำเร็จ
        //    ต้องเป็นแบบนี้ ไม่งั้น Service จะลด quantity ซ้ำ
        return $stmt->rowCount() > 0;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ย้อนการแจ้งหาย/ชำรุด (หาหนังสือเจอทีหลัง)
     * ==========================================================================
     *
     * 📥 Input:
     * @param int    $borrowId  รายการที่แจ้งหายไว้
     * @param float  $newFine   ค่าปรับที่เหลือหลังย้อน (Service คำนวณมา)
     * @param string $note      ต่อท้าย lost_note เดิม — เก็บร่องรอยไว้ ไม่ลบทิ้ง
     *
     * 📤 Output: @return bool true = ย้อนสำเร็จ
     *
     * 🧠 ทำไมไม่ล้าง lost_note ทิ้ง:
     *    ต้องตรวจย้อนหลังได้ว่าเคยแจ้งหายแล้วย้อน — ใครแจ้ง ใครย้อน เพราะอะไร
     *    (กติกาที่ตกลงไว้: ย้อนได้ แต่ต้องมีร่องรอย)
     *
     * 🛡️ WHERE status IN ('lost','damaged') → ย้อนได้เฉพาะรายการที่แจ้งไว้จริง
     *    และกันย้อนซ้ำ ซึ่งจะทำให้ quantity เพิ่มเกิน
     *
     * ✅ Use case: BorrowService::undoLost()
     */
    public function undoLost(int $borrowId, float $newFine, string $note): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE borrows
            SET status = 'returned', return_date = CURDATE(), fine_amount = ?,
                lost_note = CONCAT(COALESCE(lost_note, ''), ' | ', ?)
            WHERE id = ? AND status IN ('lost', 'damaged')
        ");
        $stmt->execute([$newFine, $note, $borrowId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ล็อคแถวที่แจ้งหาย/ชำรุดไว้ (สำหรับย้อน)
     * ==========================================================================
     * 🧠 ต่างจาก findByIdForUpdate() ที่กรองเฉพาะ status='borrowing'
     * ✅ Use case: BorrowService::undoLost()
     */
    public function findLostByIdForUpdate(int $borrowId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM borrows WHERE id = ? AND status IN ('lost', 'damaged') FOR UPDATE
        ");
        $stmt->execute([$borrowId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: บันทึกการยกเว้นค่าปรับ (ไม่แตะ fine_amount เดิม)
     * ==========================================================================
     *
     * 📥 Input:
     * @param int    $borrowId  รายการยืมที่จะยกเว้น
     * @param int    $waivedBy  ผู้ยกเว้น (users.id)
     * @param string $note      เหตุผล (บังคับกรอกที่ชั้น Service)
     *
     * 📤 Output: @return bool true = อัปเดตสำเร็จ
     *
     * 🧠 ทำไมไม่ตั้ง fine_amount = 0:
     *    ต้องเก็บไว้ว่า "เดิมค่าปรับเท่าไร แล้วยกเว้นให้" ไม่งั้นตรวจย้อนหลังไม่ได้
     *    ว่ายกเว้นไปเท่าไรรวมกัน — รายการที่ยกเว้นแล้วถูกกรองออกด้วย fine_waived_at
     *
     * 🛡️ WHERE fine_waived_at IS NULL → กันยกเว้นซ้ำแม้จะยิงพร้อมกัน 2 ครั้ง
     *    (คู่กับ FOR UPDATE lock ที่ Service)
     *
     * ✅ Use case: BorrowService::waiveFine()
     */
    public function waiveFine(int $borrowId, int $waivedBy, string $note): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE borrows
            SET fine_waived_at = NOW(), fine_waived_by = ?, fine_waived_note = ?
            WHERE id = ? AND fine_waived_at IS NULL
        ");
        $stmt->execute([$waivedBy, $note, $borrowId]);

        // 📌 rowCount() = 0 แปลว่ามีคนยกเว้นไปแล้วก่อนหน้า → ไม่ถือว่าสำเร็จ
        return $stmt->rowCount() > 0;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงประวัติการยกเว้นค่าปรับ (ให้ผู้ดูแลตรวจย้อนหลัง)
     * ==========================================================================
     *
     * 🧠 ทำไมต้องมี: ระบบนี้ไม่มี audit trail กลาง (KNOWN_LIMITATIONS §4)
     *    การให้เจ้าหน้าที่ยกเว้นเงินได้เองต้องแลกกับการที่ผู้ดูแลตรวจย้อนหลังได้
     *
     * 📤 @return array[] [{id, fine_amount, fine_waived_at, fine_waived_note,
     *                      user_name, book_title, waived_by_name}, ...]
     * ✅ Use case: admin/payments.php ส่วน "ประวัติการยกเว้นค่าปรับ"
     */
    public function findWaivedFines(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.id, b.fine_amount, b.fine_waived_at, b.fine_waived_note,
                   b.return_date,
                   u.name AS user_name,
                   bk.title AS book_title,
                   w.name AS waived_by_name
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            LEFT JOIN users w ON b.fine_waived_by = w.id
            WHERE b.fine_waived_at IS NOT NULL
            ORDER BY b.fine_waived_at DESC, b.id DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ยอดรวมค่าปรับที่ยกเว้นไปทั้งหมด
     * ==========================================================================
     * ✅ Use case: admin/payments.php การ์ดสรุป
     */
    public function sumWaivedFines(): float
    {
        return (float) $this->pdo->query("
            SELECT COALESCE(SUM(fine_amount), 0) FROM borrows WHERE fine_waived_at IS NOT NULL
        ")->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สร้างรายการยืม (status='borrowing' อัตโนมัติ)
     * ==========================================================================
     *
     * 📥 Input:
     * @param array $data {user_id, book_id, borrow_date, due_date}
     *              - มาจาก: BorrowService::createBorrow()
     *
     * 📤 Output: @return int Borrow ID ที่สร้าง
     *
     * 🧠 เหตุผล: status='borrowing' hardcode ใน SQL (ไม่รับจาก input)
     * 🛡️ Security: เรียกภายใต้ transaction จาก BorrowService
     * ✅ Use case: BorrowService::createBorrow()
     */
    public function create(array $data): int
    {
        // 🔄 Flow: BorrowService::createBorrow() → borrowSingleBook() → create()
        // 🎯 INSERT รายการยืมใหม่ลงตาราง borrows
        //
        // 📝 SQL: INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status)
        //    - status = 'borrowing' → hardcode ใน SQL (ไม่รับจาก input)
        //      🛡️ ป้องกันการสร้าง borrow ที่เป็น 'returned' ตั้งแต่แรก
        //    - ? placeholder 4 ตัว: [user_id, book_id, borrow_date, due_date]
        //
        // 📤 Output: Borrow ID ที่สร้าง (lastInsertId)
        // 🛡️ เรียกภายใต้ transaction จาก BorrowService
        $stmt = $this->pdo->prepare("
            INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status)
            VALUES (?, ?, ?, ?, 'borrowing')
        ");

        $stmt->execute([
            $data['user_id'],
            $data['book_id'],
            $data['borrow_date'],
            $data['due_date']
        ]);

        // 📝 lastInsertId() → ได้ AUTO_INCREMENT ID ที่เพิ่งสร้าง
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: อัปเดตเป็นคืนแล้ว (status='returned' + return_date + fine)
     * ==========================================================================
     *
     * 📥 Input:
     * @param int   $id         Borrow ID
     * @param float $fineAmount ค่าปรับ (0 = คืนตรงเวลา, > 0 = คืนช้า)
     *
     * 📤 Output: @return bool true = สำเร็จ
     *
     * 🧠 เหตุผล: return_date = CURDATE() — คำนวณโดย DB (ไม่ต้องส่งจาก PHP)
     * ✅ Use case: BorrowService::returnBook() (inside transaction)
     */
    public function markAsReturned(int $id, float $fineAmount): bool
    {
        // 🔄 Flow: BorrowService::returnBook() → markAsReturned()
        // 🎯 UPDATE สถานะเป็น 'returned' + บันทึกวันคืน + ค่าปรับ
        //
        // 📝 SQL: UPDATE borrows SET status='returned', return_date=CURDATE(), fine_amount=?
        //    - return_date = CURDATE() → ใช้วันที่ของ MySQL (ไม่ส่งจาก PHP)
        //      ⚠️ Danger Zone #5: ถ้า timezone MySQL ≠ PHP → วันที่อาจคลาดเคลื่อน
        //    - fine_amount = ? → $fineAmount (0 = คืนตรงเวลา, > 0 = คืนช้า)
        //    - WHERE id = ? → $id
        //    - ? ตำแหน่ง: [fineAmount, id]
        //
        // 🛡️ เรียกภายใต้ transaction จาก BorrowService (หลัง lock แล้ว)
        $stmt = $this->pdo->prepare("
            UPDATE borrows 
            SET status = 'returned', return_date = CURDATE(), fine_amount = ? 
            WHERE id = ?
        ");
        return $stmt->execute([$fineAmount, $id]);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับการยืม active ของ user (ไม่มี lock — read-only)
     * ==========================================================================
     *
     * 📥 Input: @param int $userId
     * 📤 Output: @return int จำนวนที่กำลังยืมอยู่
     * ✅ Use case: แสดงใน UI, profile page (ไม่ต้องการ lock)
     */
    /**
     * ==========================================================================
     * 🔔 จุดประสงค์: ตัวเลขแจ้งเตือนของ **สมาชิกคนเดียว** สำหรับกระดิ่งฝั่งผู้ใช้
     * ==========================================================================
     *
     * 🔴 [SECURITY] `$userId` ต้องมาจาก **session เท่านั้น** ห้ามรับจาก URL
     *    ถ้ารับจากพารามิเตอร์ภายนอก สมาชิกจะสอดส่องตัวเลขของคนอื่นได้ (IDOR)
     *    ผู้เรียกเดียวคือ `includes/header.php` ซึ่งอ่าน `$_SESSION['user_id']`
     *
     * 🔴 [PERFORMANCE] เรียกจาก header ที่ **ทุกหน้าฝั่งผู้ใช้ include**
     *    จึงรวมทุกอย่างไว้ใน round-trip เดียว เหมือน getAlertCounts() ฝั่งแอดมิน
     *
     * 🧠 นับเฉพาะเรื่องที่ **สมาชิกต้องลงมือทำ** ไม่ใช่สถิติ
     *    "ยืมไปแล้วทั้งหมด 14 เล่ม" ไม่ใช่การแจ้งเตือน — ใส่แล้วกระดิ่งจะแดงตลอด
     *
     * 📤 @return array{overdue:int, due_soon:int, ready_pickup:int, unpaid:int, total:int}
     */
    public function getMemberAlertCounts(int $userId, int $dueSoonDays): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM borrows
                  WHERE user_id = :uid AND status = 'borrowing'
                    AND due_date < CURDATE())                                    AS overdue,
                (SELECT COUNT(*) FROM borrows
                  WHERE user_id = :uid2 AND status = 'borrowing'
                    AND due_date >= CURDATE()
                    AND due_date <= DATE_ADD(CURDATE(), INTERVAL :days DAY))     AS due_soon,
                (SELECT COUNT(*) FROM reservations
                  WHERE user_id = :uid3 AND status = 'pending')                  AS ready_pickup,
                (SELECT COUNT(*) FROM borrows b
                   LEFT JOIN payments p ON p.borrow_id = b.id
                  WHERE b.user_id = :uid4 AND b.fine_amount > 0
                    AND p.id IS NULL AND b.fine_waived_at IS NULL)               AS unpaid
        ");
        // 📌 MariaDB ไม่ยอมให้ named parameter ซ้ำใน statement เดียว จึงตั้งชื่อแยก
        $stmt->bindValue(':uid',  $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid2', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid3', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid4', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':days', $dueSoonDays, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $counts = [
            'overdue'      => (int) ($row['overdue'] ?? 0),
            'due_soon'     => (int) ($row['due_soon'] ?? 0),
            'ready_pickup' => (int) ($row['ready_pickup'] ?? 0),
            'unpaid'       => (int) ($row['unpaid'] ?? 0),
        ];
        $counts['total'] = array_sum($counts);
        return $counts;
    }

    public function countActiveBorrows(int $userId): int
    {
        // 🔄 Flow: แสดงใน UI / profile page (ไม่ต้องการ lock)
        // 🎯 นับจำนวนหนังสือที่ user กำลังยืมอยู่ (read-only ไม่มี lock)
        //
        // 🧠 ต่างจาก countActiveBorrowsForUpdate():
        //    - เมธอดนี้ ไม่มี FOR UPDATE → แค่อ่าน ไม่ได้ lock
        //    - ใช้สำหรับแสดงผลเท่านั้น ไม่ใช่สำหรับตัดสินใจยืม
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM borrows 
            WHERE user_id = ? AND status = 'borrowing'
        ");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับการยืม active + lock rows (ป้องกันยืมเกินโควต้า)
     * ==========================================================================
     * count + FOR UPDATE พร้อมกัน ป้องกัน 2 request ยืมพร้อมกันแล้วเกิน MAX_BORROW_BOOKS
     *
     * 📥 Input: @param int $userId
     * 📤 Output: @return int จำนวน active borrows (ถูก lock)
     *
     * 🛡️ Security:
     * - FOR UPDATE ล็อคแถวที่ match — request อื่นต้องรอ
     * - ต้องเรียกใน transaction
     *
     * ⚠️ ห้ามแก้ FOR UPDATE — critical guard
     * ✅ Use case: BorrowService::createBorrow() เช็คโควต้าก่อนยืม
     */
    public function countActiveBorrowsForUpdate(int $userId): int
    {
        // 🔄 Flow: BorrowService::createBorrow() → countActiveBorrowsForUpdate()
        // 🎯 นับจำนวนยืม active + lock rows ป้องกันยืมเกิน MAX_BORROW_BOOKS
        //
        // 🧠 ทำไมต้อง lock? สมมติว่า:
        //    - user ยืมได้ 3 เล่ม (max = 3)
        //    - 2 request เข้ามาพร้อมกัน → ทั้งคู่เห็น count = 3 → ทั้งคู่ยืมได้
        //    - ผล: user ยืม 5 เล่ม (เกิน max!)
        //    - FOR UPDATE แก้ไข: request ที่ 2 ต้องรอ lock → ปลอดภัย
        //
        // ⚠️ Danger Zone #1: ต้องเรียกใน transaction!
        //    🔴 ห้ามลบ FOR UPDATE ออก!
        // [LOCK] ล็อคแถว borrows ของ user นี้ - ป้องกันยืมเกินโควต้า
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM borrows 
            WHERE user_id = ? AND status = 'borrowing' FOR UPDATE
        ");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ตรวจว่า user ยืมหนังสือเล่มนี้อยู่หรือไม่ (ป้องกันยืมซ้ำ)
     * ==========================================================================
     *
     * 📥 Input: @param int $userId, @param int $bookId
     * 📤 Output: @return bool true = กำลังยืมอยู่ (ห้ามยืมซ้ำ)
     * ✅ Use case: BorrowService::createBorrow() เช็คก่อนยืม
     */
    public function isAlreadyBorrowing(int $userId, int $bookId): bool
    {
        // 🔄 Flow: BorrowService::createBorrow() → borrowSingleBook() → isAlreadyBorrowing()
        // 🎯 ตรวจว่า user กำลังยืมหนังสือเล่มนี้อยู่หรือไม่ (ป้องกันยืมซ้ำ)
        //
        // 📝 SQL: SELECT id FROM borrows WHERE user_id=? AND book_id=? AND status='borrowing'
        //    - ถ้าเจอ (fetch ได้) → return true (ห้ามยืมซ้ำ)
        //    - ถ้าไม่เจอ (fetch = false) → return false (ยืมได้)
        $stmt = $this->pdo->prepare("
            SELECT id FROM borrows 
            WHERE user_id = ? AND book_id = ? AND status = 'borrowing'
        ");
        $stmt->execute([$userId, $bookId]);
        return $stmt->fetch() !== false;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการเกินกำหนดคืน (สำหรับ dashboard + notification)
     * ==========================================================================
     *
     * 📥 Input: @param int $limit (default: 10)
     * 📤 Output: @return array[] [{borrow row + user_name, phone, book_title}, ...]
     *         เรียง: due_date ASC (เกินนานสุดขึ้นก่อน)
     * ✅ Use case: admin/index.php → DashboardService → "รายการเกินกำหนด"
     */
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: รายการที่ **กำลังจะครบกำหนด** ในอีก N วัน (ยังไม่เกิน)
     * ==========================================================================
     *
     * 🧠 ทำไมต้องมี ทั้งที่มี findOverdue() อยู่แล้ว
     *    findOverdue() ตอบว่า "ใครสายแล้ว" = **สายไปแล้ว** ทำได้แค่ตามทวง
     *    ตัวนี้ตอบว่า "ใครกำลังจะสาย" = ยังโทรเตือนให้มาคืนทันได้
     *    ระบบนี้ไม่ส่งอีเมล บรรณารักษ์จึงต้องโทร/LINE เอง — ต้องมีรายชื่อให้ก่อน
     *
     * 📥 @param int $days  มองไปข้างหน้ากี่วัน (มาจากกฎ DUE_SOON_DAYS ที่ตั้งได้ในหน้าตั้งค่า)
     * @param int $limit จำกัดจำนวนแถว (สำหรับการ์ดบน dashboard)
     *
     * 🔴 ช่วงเป็น [วันนี้, วันนี้+N] — **รวมวันนี้** เพราะเล่มที่ครบกำหนดวันนี้
     *    ยังไม่สาย (ค่าปรับนับจากวันที่เลยกำหนด ดู calculateFine())
     *    และ **ไม่ทับกับ findOverdue()** ซึ่งเอา due_date < CURDATE() เท่านั้น
     *    ถ้าเผลอใช้ >= CURDATE() - 1 จะมีรายการโผล่ทั้งสองการ์ด แล้วยอดรวมจะเพี้ยน
     *
     * 📤 @return array [{...borrows, user_name, phone, book_title, days_left}, ...]
     * ⚠️ phone อยู่ในผลลัพธ์ → ต้อง escape ก่อนแสดงผล
     */
    public function findDueSoon(int $days, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.*, u.name as user_name, u.phone, bk.title as book_title,
                   DATEDIFF(b.due_date, CURDATE()) as days_left
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            WHERE b.status = 'borrowing'
              AND b.due_date >= CURDATE()
              AND b.due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
            ORDER BY b.due_date ASC, b.id ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $days, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * 🎯 นับจำนวนที่ใกล้ครบกำหนด — ใช้กับตัวเลขบนการ์ด
     * ⚠️ เงื่อนไขต้องตรงกับ findDueSoon() เป๊ะ ไม่งั้นตัวเลขบนการ์ดกับรายการข้างล่างจะไม่ตรงกัน
     */
    public function countDueSoon(int $days): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM borrows
            WHERE status = 'borrowing'
              AND due_date >= CURDATE()
              AND due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
        ");
        $stmt->bindValue(1, $days, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    public function findOverdue(int $limit = 10): array
    {
        // 🔄 Flow: admin/index.php → DashboardService → findOverdue()
        // 🎯 ดึงรายการเกินกำหนด Top N สำหรับแสดงบน dashboard
        //
        // 📝 SQL: borrowing + due_date < CURDATE() → เกินกำหนด
        //    ORDER BY due_date ASC → เกินนานสุดขึ้นก่อน (ด่วนสุดอยู่บน)
        // ⚠️ phone อยู่ในผลลัพธ์ → ต้อง escape ก่อนแสดงผล
        $stmt = $this->pdo->prepare("
            SELECT b.*, u.name as user_name, u.phone, bk.title as book_title
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            WHERE b.status = 'borrowing' AND b.due_date < CURDATE()
            ORDER BY b.due_date ASC, b.id ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการยืมล่าสุด (สำหรับ dashboard)
     * ==========================================================================
     *
     * 📥 Input: @param int $limit (default: 5)
     * 📤 Output: @return array[] [{borrow row + user_name, book_title}, ...]
     * ✅ Use case: admin/index.php → DashboardService → "ยืมล่าสุด"
     */
    public function findRecent(int $limit = 5): array
    {
        // 🔄 Flow: admin/index.php → DashboardService → findRecent()
        // 🎯 ดึงรายการยืมล่าสุด N รายการ สำหรับแสดงบน dashboard
        //
        // 📝 SQL: ORDER BY created_at DESC LIMIT ? → ล่าสุดขึ้นก่อน
        $stmt = $this->pdo->prepare("
            SELECT b.*, u.name as user_name, bk.title as book_title
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            ORDER BY b.created_at DESC, b.id DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงประวัติการยืมของ user (สำหรับ profile / member history)
     * ==========================================================================
     *
     * 📥 Input: @param int $userId, @param int $limit (default: 20)
     * 📤 Output: @return array[] [{borrow row + book_title, book_author}, ...]
     * ✅ Use case: admin/member_detail.php, profile.php
     */
    public function findByUserId(int $userId, int $limit = 20): array
    {
        // 🔄 Flow: admin/member_detail.php, profile.php
        // 🎯 ดึงประวัติการยืมของ user (ไม่มี pagination แบบง่ายๆ)
        //
        // 📝 SQL: WHERE user_id = ? ORDER BY created_at DESC LIMIT ?
        //    - ไม่กรอง status → ดึงทั้ง borrowing + returned
        // 🧠 ต่างจาก findByUserIdPaginated(): เมธอดนี้ง่ายกว่า ไม่มี pagination
        $stmt = $this->pdo->prepare("
            SELECT b.*, bk.title as book_title, bk.author as book_author
            FROM borrows b
            JOIN books bk ON b.book_id = bk.id
            WHERE b.user_id = ?
            ORDER BY b.created_at DESC, b.id DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ประวัติการยืมของ user + pagination + status filter
     * ==========================================================================
     * เมธอดหลักสำหรับหน้า my_borrows.php
     *
     * 📥 Input:
     * @param int         $userId  ID user
     * @param string|null $status  'borrowing' | 'returned' | null (ทั้งหมด)
     * @param int         $page    หน้า (1-indexed)
     * @param int         $perPage จำนวนต่อหน้า
     *
     * 📤 Output:
     * @return array {data: array[], total, page, per_page, total_pages}
     *
     * 🧠 เหตุผล: 2 queries (COUNT + SELECT LIMIT OFFSET)
     * ✅ Use case: my_borrows.php (ประวัติการยืมของสมาชิก)
     */
    public function findByUserIdPaginated(int $userId, ?string $status = null, int $page = 1, int $perPage = 10): array
    {
        // 🔄 Flow: my_borrows.php → findByUserIdPaginated()
        // 🎯 ดึงประวัติยืมของสมาชิก + pagination + กรอง status
        //
        // 📝 สร้าง WHERE clause แบบ dynamic (user_id + optional status)
        // ⚠️ Danger Zone #2: $where ถูกต่อเข้า SQL string
        //    แต่ค่ามาจาก code ภายใน + user input bind ผ่าน ? → ปลอดภัย
        $where = "b.user_id = ?";
        $params = [$userId];
        
        // 🔍 ถ้ามี status filter → เพิ่มเงื่อนไข
        if ($status !== null) {
            $where .= " AND b.status = ?";
            $params[] = $status;
        }
        
        // 📊 Query 1: นับจำนวนทั้งหมด (สำหรับคำนวณ pagination)
        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM borrows b WHERE $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        
        // 📝 คำนวณ offset: (page - 1) * perPage
        //    เช่น page=2, perPage=10 → offset=10 (ข้าม 10 รายการแรก)
        $offset = ($page - 1) * $perPage;
        $params[] = $perPage;
        $params[] = $offset;
        
        // 📊 Query 2: ดึงข้อมูลจริง (LIMIT + OFFSET)
        $stmt = $this->pdo->prepare("
            SELECT b.*, bk.title as book_title, bk.author as book_author
            FROM borrows b
            JOIN books bk ON b.book_id = bk.id
            WHERE $where
            ORDER BY b.created_at DESC, b.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        
        // 📤 Output: {data, total, page, per_page, total_pages}
        //    total_pages = ceil(total / perPage) → ปัดเศษขึ้น
        return [
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ];
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการยืมปัจจุบันของหนังสือ (ยังไม่คืน)
     * ==========================================================================
     *
     * 📥 Input: @param int $bookId
     * 📤 Output: @return array [{borrow row + borrower_name}, ...]
     * ✅ Use case: book.php → แสดงว่าใครยืมอยู่
     */
    public function findCurrentByBook(int $bookId): array
    {
        // 🔄 Flow: book.php → findCurrentByBook()
        // 🎯 ดึงรายการยืมปัจจุบันของหนังสือเล่มนี้ (ใครยืมอยู่)
        //
        // 📝 SQL: WHERE book_id = ? AND status = 'borrowing'
        //    → เฉพาะที่ยังไม่คืน + JOIN users เอาชื่อผู้ยืม
        $stmt = $this->pdo->prepare("
            SELECT b.*, u.name as borrower_name
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            WHERE b.book_id = ? AND b.status = 'borrowing'
            ORDER BY b.created_at DESC, b.id DESC
        ");
        $stmt->execute([$bookId]);
        return $stmt->fetchAll();
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงประวัติการยืมของหนังสือ (ทุก status)
     * ==========================================================================
     *
     * 📥 Input: @param int $bookId, @param int $limit (default: 5)
     * 📤 Output: @return array [{borrow row + borrower_name}, ...]
     * ✅ Use case: book.php → แสดงประวัติการยืมของหนังสือ
     */
    public function findHistoryByBook(int $bookId, int $limit = 5): array
    {
        // 🔄 Flow: book.php → findHistoryByBook()
        // 🎯 ดึงประวัติการยืมของหนังสือ (ทุก status) ล่าสุด N รายการ
        //
        // 📝 SQL: WHERE book_id = ? ORDER BY created_at DESC LIMIT ?
        //    → ไม่กรอง status → แสดงทั้งยืมอยู่ + คืนแล้ว
        $stmt = $this->pdo->prepare("
            SELECT b.*, u.name as borrower_name
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            WHERE b.book_id = ?
            ORDER BY b.created_at DESC, b.id DESC
            LIMIT ?
        ");
        $stmt->execute([$bookId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับจำนวนเกินกำหนด (สำหรับ badge notification)
     * ==========================================================================
     *
     * 📤 Output: @return int จำนวน overdue
     * ✅ Use case: admin/header.php → badge สีแดง, DashboardService
     */
    public function countOverdue(): int
    {
        // 🔄 Flow: admin/header.php → badge สีแดง, DashboardService
        // 🎯 นับจำนวนเกินกำหนดทั้งระบบ
        //
        // 📝 ใช้ query() ไม่ใช่ prepare() เพราะไม่มี user input
        // ⚠️ Danger Zone #4: ถ้าเพิ่ม parameter → ต้องเปลี่ยนเป็น prepare()
        return (int) $this->pdo->query("
            SELECT COUNT(*) FROM borrows 
            WHERE status = 'borrowing' AND due_date < CURDATE()
        ")->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับจำนวนการยืม active ทั้งระบบ (สำหรับ dashboard)
     * ==========================================================================
     *
     * 📤 Output: @return int จำนวน status='borrowing'
     * ✅ Use case: admin/index.php → DashboardService
     */
    public function countActive(): int
    {
        // 🔄 Flow: admin/index.php → DashboardService
        // 🎯 นับการยืม active ทั้งระบบ สำหรับการ์ดสถิติบน dashboard
        //
        // 📝 ใช้ query() ไม่ใช่ prepare() เพราะไม่มี user input
        return (int) $this->pdo->query("
            SELECT COUNT(*) FROM borrows WHERE status = 'borrowing'
        ")->fetchColumn();
    }

    // NOTE: getMonthlyStatistics() removed - use ReportRepository::getMonthlyReport()
    // เหตุผล: ReportRepository เป็น owner ของ report logic

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับการยืมทั้งหมดของหนังสือ (ตรวจก่อนลบ)
     * ==========================================================================
     *
     * 📥 Input: @param int $bookId
     * 📤 Output: @return int จำนวนครั้งที่ถูกยืม (ทุก status)
     * ✅ Use case: BookService::deleteBook() เช็คก่อนลบ
     */
    public function countByBook(int $bookId): int
    {
        // 🔄 Flow: BookService::deleteBook() → hasBorrowHistory() → countByBook()
        // 🎯 นับการยืมทั้งหมดของหนังสือ (ตรวจก่อนลบ)
        //    ถ้า > 0 → หนังสือเคยถูกยืม → ป้องกันการลบ (data integrity)
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM borrows WHERE book_id = ?");
        $stmt->execute([$bookId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับการยืมทั้งหมดของ user (ตรวจก่อนลบสมาชิก)
     * ==========================================================================
     *
     * 📥 Input: @param int $userId
     * 📤 Output: @return int จำนวนครั้ง (ทุก status)
     * ✅ Use case: MemberService::deleteMember() เช็คก่อนลบ
     */
    public function countByUser(int $userId): int
    {
        // 🔄 Flow: MemberService::deleteMember() → countByUser()
        // 🎯 นับการยืมทั้งหมดของ user (ตรวจก่อนลบสมาชิก)
        //    ถ้า > 0 → สมาชิกเคยยืม → ป้องกันการลบ (data integrity)
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM borrows WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สถิติการยืมของ user (สำหรับ profile page)
     * ==========================================================================
     *
     * 📥 Input: @param int $userId
     * 📤 Output:
     * @return array {total_borrows, active_borrows, returned, total_fines}
     *
     * 🧠 เหตุผล: SUM(CASE) ใน query เดียว — ไม่ต้อง query หลายครั้ง
     * ✅ Use case: profile.php, admin/member_detail.php
     */
    public function getStatsByUser(int $userId): array
    {
        // 🔄 Flow: profile.php, admin/member_detail.php
        // 🎯 ดึงสถิติการยืมของ user ใน query เดียว (ไม่ต้อง query หลายครั้ง)
        //
        // 📝 SQL อธิบาย:
        //    - COUNT(*) → ยอดยืมทั้งหมด
        //    - SUM(CASE WHEN status='borrowing') → กำลังยืมอยู่
        //    - SUM(CASE WHEN status='returned') → คืนแล้ว
        //    - COALESCE(SUM(fine_amount), 0) → ค่าปรับรวม (0 ถ้าไม่มี)
        //
        // 📤 Output: {total_borrows, active_borrows, returned, total_fines}
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_borrows,
                SUM(CASE WHEN status = 'borrowing' THEN 1 ELSE 0 END) as active_borrows,
                SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned,
                COALESCE(SUM(fine_amount), 0) as total_fines
            FROM borrows
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับการยืม active ของหนังสือ (ตรวจก่อนลบ isBeingBorrowed)
     * ==========================================================================
     *
     * 📥 Input: @param int $bookId
     * 📤 Output: @return int จำนวนคนที่กำลังยืมอยู่
     * ✅ Use case: BookService::deleteBook() เช็คว่ามีคนยืมอยู่หรือไม่
     */
    public function countActiveByBook(int $bookId): int
    {
        // 🔄 Flow: BookService::deleteBook() → isBeingBorrowed() → countActiveByBook()
        // 🎯 นับจำนวนคนที่กำลังยืมหนังสือเล่มนี้อยู่
        //    ถ้า > 0 → มีคนยืมอยู่ → ห้ามลบหนังสือ!
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM borrows WHERE book_id = ? AND status = 'borrowing'");
        $stmt->execute([$bookId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับ "จำนวนคน" ที่ค้างชำระ (สำหรับแบ่งหน้า + ป้ายสรุป)
     * ==========================================================================
     *
     * 📥 Input: @param string $search คำค้น (ชื่อ/อีเมล/เบอร์/ชื่อหนังสือ) — ว่าง = ทั้งหมด
     * 📤 Output: @return array {people: int, rows: int, total: float}
     *
     * 🔴 **ห้ามนับจากรายการที่ดึงมาแสดง** — ต้องเป็น query แยกที่ไม่มี LIMIT
     *    บั๊ก F-35 เกิดจากการเอา count() ของรายการที่ตัด 50 แถวแล้วมาขึ้นป้ายว่า
     *    "46 คน" ทั้งที่จริง 169 คน · ยอดเงินถูกเพราะมาจากอีก query ที่ไม่มี LIMIT
     *    ป้ายกับยอดเงินจึงขัดกันเองบนหน้าจอเดียว
     *
     * 🧠 นิยาม "ค้างชำระ" ต้องตรงกับอีก 5 แหล่งเป๊ะ:
     *    fine_amount > 0 AND ยังไม่มี payment AND ยังไม่ถูกยกเว้น
     *
     * ✅ Use case: admin/payments.php — ป้ายสรุป + คำนวณจำนวนหน้า
     */
    public function countUnpaidDebtors(string $search = ''): array
    {
        [$where, $params] = $this->buildUnpaidSearch($search);

        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT b.user_id) AS people,
                   COUNT(*)                  AS rows_count,
                   COALESCE(SUM(b.fine_amount), 0) AS total
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            LEFT JOIN payments p ON b.id = p.borrow_id
            WHERE b.fine_amount > 0 AND p.id IS NULL AND b.fine_waived_at IS NULL
            {$where}
        ");
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [
            'people' => (int) ($row['people'] ?? 0),
            'rows'   => (int) ($row['rows_count'] ?? 0),
            'total'  => (float) ($row['total'] ?? 0),
        ];
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายชื่อคนค้างชำระแบบแบ่งหน้า — **แบ่งเป็น "คน" ไม่ใช่ "รายการ"**
     * ==========================================================================
     *
     * 📥 Input:
     * @param int    $limit  จำนวน**คน**ต่อหน้า
     * @param int    $offset ข้ามกี่**คน**
     * @param string $search คำค้น
     *
     * 📤 Output: @return array [{user_id, user_name, user_phone, user_email, item_count, total_fine}, ...]
     *            เรียงจากคนที่ค้างมากสุดก่อน
     *
     * 🔴 **ทำไมต้องแบ่งหน้าที่ระดับคน ไม่ใช่ระดับแถว**
     *    หน้านี้จัดกลุ่มหนี้ตามสมาชิก (คนหนึ่งค้างได้ 7–8 ใบ)
     *    ถ้าแบ่งหน้าตามแถวยืม หนี้ของคนเดียวกันจะถูกหั่นข้ามหน้า
     *    บรรณารักษ์เห็นยอดไม่ครบของคนที่กำลังยืนอยู่ตรงหน้า — แย่กว่าการตัด 50 แถวเสียอีก
     *
     * 🛡️ ORDER BY มีตัวตัดสินลำดับครบ (total_fine → user_id)
     *    ไม่งั้นคนที่ค้างเท่ากันจะสลับที่ทุกครั้งที่โหลดหน้า (F-39)
     *
     * ✅ Use case: admin/payments.php ส่วน "รายการค้างชำระ"
     */
    public function getUnpaidDebtors(int $limit, int $offset = 0, string $search = ''): array
    {
        [$where, $params] = $this->buildUnpaidSearch($search);

        $sql = "
            SELECT u.id AS user_id, u.name AS user_name, u.email AS user_email, u.phone AS user_phone,
                   COUNT(*) AS item_count,
                   SUM(b.fine_amount) AS total_fine
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            LEFT JOIN payments p ON b.id = p.borrow_id
            WHERE b.fine_amount > 0 AND p.id IS NULL AND b.fine_waived_at IS NULL
            {$where}
            GROUP BY u.id, u.name, u.email, u.phone
            ORDER BY total_fine DESC, u.id ASC
            LIMIT " . (int) $limit . " OFFSET " . (int) $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงใบค้างชำระของสมาชิกกลุ่มที่กำลังแสดงอยู่ (query เดียว)
     * ==========================================================================
     *
     * 📥 Input: @param int[] $userIds รหัสสมาชิกในหน้านี้
     * 📤 Output: @return array แถวค้างชำระทั้งหมดของคนเหล่านั้น (จัดกลุ่มที่ชั้น Page)
     *
     * 🧠 ดึงทีเดียวแทนที่จะวนถามทีละคน — 20 คนต่อหน้า = 20 query ถ้าทำแบบวน
     *
     * 🛡️ ORDER BY ใช้ COALESCE(return_date, lost_reported_at)
     *    เพราะหนังสือที่แจ้งหาย/ชำรุดไม่มี return_date (เป็น NULL ทุกแถว)
     *    ถ้าเรียงด้วย return_date เฉย ๆ ค่าชดใช้จะกองรวมกันแล้วสลับที่มั่ว (F-39)
     */
    public function getUnpaidItemsByUsers(array $userIds): array
    {
        if (!$userIds) {
            return [];
        }
        // 🛡️ cast เป็น int ทุกตัวก่อนต่อเป็น IN (...) — ค่ามาจาก query ก่อนหน้า ไม่ใช่ user input
        //    แต่ cast ไว้เป็นด่านสุดท้ายอยู่ดี
        $in = implode(',', array_map('intval', $userIds));

        $stmt = $this->pdo->prepare("
            SELECT b.id, b.fine_amount, b.borrow_date, b.due_date, b.return_date, b.status,
                   b.lost_reported_at,
                   u.id AS user_id, u.name AS user_name, u.email AS user_email, u.phone AS user_phone,
                   bk.id AS book_id, bk.title AS book_title
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            LEFT JOIN payments p ON b.id = p.borrow_id
            WHERE b.fine_amount > 0 AND p.id IS NULL AND b.fine_waived_at IS NULL
              AND b.user_id IN ({$in})
            ORDER BY COALESCE(b.return_date, b.lost_reported_at) DESC, b.id DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * 🔍 สร้างเงื่อนไขค้นหาของหน้าค้างชำระ — ที่เดียว ใช้ร่วมกันทั้งตัวนับและตัวดึง
     *    🔴 ต้องใช้ร่วมกัน ไม่งั้นตัวนับกับรายการที่แสดงจะไม่ตรงกัน ซึ่งคือหัวใจของบั๊ก F-35
     *
     * @return array{0: string, 1: array} [SQL ต่อท้าย WHERE, params]
     */
    private function buildUnpaidSearch(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return ['', []];
        }

        // 🛡️ bind ผ่าน ? ทุกตัว — ห้ามต่อสตริง
        $like = '%' . $search . '%';
        return [
            ' AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR bk.title LIKE ?)',
            [$like, $like, $like, $like],
        ];
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการค่าปรับค้างชำระ (สำหรับ admin/payments.php)
     * ==========================================================================
     * ดึง borrows ที่มี fine > 0 แต่ยังไม่มี payment record
     *
     * 📥 Input: @param int $limit (default: 50)
     * 📤 Output: @return array [{borrow fields + user info + book_title}, ...]
     *
     * 🧠 เหตุผล: LEFT JOIN payments + p.id IS NULL = ยังไม่จ่าย
     * ✅ Use case: admin/payments.php → แสดงรายการค้างชำระ
     */
    public function getUnpaidFinesList(int $limit = 50): array
    {
        // 🔄 Flow: admin/payments.php → getUnpaidFinesList()
        // 🎯 ดึงรายการค่าปรับค้างชำระ พร้อมข้อมูล user + book
        //
        // 📝 SQL อธิบาย:
        //    - JOIN users + books → เอาชื่อมาแสดง
        //    - LEFT JOIN payments → ตรวจว่ายังไม่จ่าย
        //    - WHERE fine_amount > 0 AND p.id IS NULL → มีค่าปรับ + ยังไม่จ่าย
        //
        // ⚠️ Danger Zone #3: ถ้าเพิ่มระบบจ่ายบางส่วน → logic พัง
        // ⚠️ user_email + user_phone อยู่ในผลลัพธ์ → ต้อง escape ก่อนแสดงผล
        $stmt = $this->pdo->prepare("
            SELECT b.id, b.fine_amount, b.borrow_date, b.due_date, b.return_date,
                   u.id as user_id, u.name as user_name, u.email as user_email, u.phone as user_phone,
                   bk.id as book_id, bk.title as book_title
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            LEFT JOIN payments p ON b.id = p.borrow_id
            WHERE b.fine_amount > 0 AND p.id IS NULL
              AND b.fine_waived_at IS NULL   -- 💸 ยกเว้นแล้วไม่นับเป็นค้างชำระอีก (ROADMAP ข้อ 2)
            ORDER BY COALESCE(b.return_date, b.lost_reported_at) DESC, b.id DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ยอดค่าปรับค้างชำระทั้งระบบ (สำหรับ dashboard/payments)
     * ==========================================================================
     *
     * 📤 Output: @return float ยอดรวม (บาท) หรือ 0
     * ✅ Use case: admin/payments.php, DashboardService
     */
    public function getTotalUnpaidFines(): float
    {
        // 🔄 Flow: admin/payments.php, DashboardService
        // 🎯 ยอดรวมค่าปรับค้างชำระทั้งระบบ (บาท)
        //
        // 📝 SQL: COALESCE(SUM(fine_amount), 0) + LEFT JOIN payments + IS NULL
        //    → เหมือน getFineStats().unpaid ใน ReportRepository
        //    แต่เมธอดนี้ใช้ใน payments page โดยเฉพาะ
        //
        // 📝 ใช้ query() เพราะไม่มี user input
        // ⚠️ Danger Zone #3: ถ้าเพิ่มระบบจ่ายบางส่วน → logic พัง
        return (float) $this->pdo->query("
            SELECT COALESCE(SUM(b.fine_amount), 0) 
            FROM borrows b
            LEFT JOIN payments p ON b.id = p.borrow_id
            WHERE b.fine_amount > 0 AND p.id IS NULL
              AND b.fine_waived_at IS NULL   -- 💸 ยกเว้นแล้วไม่นับเป็นค้างชำระอีก (ROADMAP ข้อ 2)
        ")->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ค่าปรับค้างชำระของ user (สำหรับ profile page)
     * ==========================================================================
     *
     * 📥 Input: @param int $userId
     * 📤 Output:
     * @return array[] [{id, fine_amount, borrow_date, due_date, return_date, book_title}, ...]
     *
     * 🧠 เหตุผล: LEFT JOIN payments + p.id IS NULL = หาเฉพาะที่ยังไม่จ่าย
     * ✅ Use case: profile.php → แสดงค่าปรับค้างชำระของสมาชิก
     */
    public function getUnpaidFinesByUser(int $userId): array
    {
        // 🔄 Flow: profile.php → getUnpaidFinesByUser()
        // 🎯 ดึงค่าปรับค้างชำระของสมาชิกคนนี้
        //
        // 📝 SQL: WHERE user_id = ? AND fine_amount > 0
        //    + LEFT JOIN payments + p.id IS NULL → เฉพาะยังไม่จ่าย
        //    + JOIN books → เอาชื่อหนังสือมาแสดง
        //
        // ⚠️ Danger Zone #3: ถ้าเพิ่มระบบจ่ายบางส่วน → logic พัง
        $stmt = $this->pdo->prepare("
            SELECT b.id, b.fine_amount, b.borrow_date, b.due_date, b.return_date,
                   bk.title as book_title
            FROM borrows b
            JOIN books bk ON b.book_id = bk.id
            LEFT JOIN payments p ON b.id = p.borrow_id
            WHERE b.user_id = ? AND b.fine_amount > 0 AND p.id IS NULL
              AND b.fine_waived_at IS NULL   -- 💸 ยกเว้นแล้วไม่นับเป็นค้างชำระอีก (ROADMAP ข้อ 2)
            ORDER BY COALESCE(b.return_date, b.lost_reported_at) DESC, b.id DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
}

