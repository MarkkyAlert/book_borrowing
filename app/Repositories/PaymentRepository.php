<?php
/**
 * PaymentRepository - Database Access สำหรับการชำระเงิน
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * Repository นี้จัดการตาราง payments (การชำระค่าปรับ)
 * ค่าปรับเกิดจากการคืนหนังสือช้า — fine_amount คำนวณโดย BorrowService::returnBook()
 *
 * 📚 โครงสร้างตาราง payments:
 * +-------------+------------------+----------------------------------------------+
 * | Column      | Type             | อธิบาย                                       |
 * +-------------+------------------+----------------------------------------------+
 * | id          | INT AUTO PK      | Primary Key                                  |
 * | borrow_id   | INT FK UNIQUE    | รายการยืมที่ชำระ (ชำระได้แค่ 1 ครั้ง)     |
 * | amount      | DECIMAL          | จำนวนเงิน (บาท)                           |
 * | recorded_by | INT FK NULL      | ID เจ้าหน้าที่ที่บันทึก                      |
 * | created_at  | DATETIME DEFAULT | เวลาชำระ                                    |
 * +-------------+------------------+----------------------------------------------+
 * สำคัญ: borrow_id มี UNIQUE constraint — 1 borrow = 1 payment เท่านั้น
 *
 * 📍 Entrypoints:
 * - admin/payments.php  → findAll(), findByBorrowId() (แสดงรายการ)
 * - BorrowService::payFine() → create() (บันทึกการชำระ)
 * - DashboardService   → getTotalCollected(), getUnpaidTotal(), getThisMonthTotal()
 *
 * ⚠️ ห้ามแก้:
 * - create() ต้องเรียกภายใต้ transaction + row lock จาก BorrowService
 * - ชำระซ้ำจะ error (UNIQUE constraint บน borrow_id)
 *
 * @package App\Repositories
 */

namespace App\Repositories;

use PDO;

class PaymentRepository
{
    // 🗄️ PDO connection — inject ผ่าน constructor ใช้ร่วมกันทุกเมธอด
    private PDO $pdo;

    // 🏗️ Constructor: รับ PDO จากภายนอก (Dependency Injection)
    // → ใช้ connection เดียวกับ BorrowService ที่เรียก
    // → ทำให้ transaction (lock borrow + create payment) ทำงานถูกต้อง
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สร้างรายการชำระค่าปรับ
     * ==========================================================================
     * INSERT รายการชำระลงตาราง payments
     *
     * 🔄 Flow:
     * Step 1 → INSERT INTO payments (borrow_id, amount, recorded_by)
     * Step 2 → คืน payment ID
     *
     * 📥 Input:
     * @param int       $borrowId   ID รายการยืม (ต้องมี fine_amount > 0)
     *                              - มาจาก: BorrowService::payFine()
     * @param float     $amount     จำนวนเงิน (บาท) - คำนวณจาก fine_amount
     * @param int|null  $recordedBy ID เจ้าหน้าที่ที่บันทึก (staff_id จาก session)
     *
     * 📤 Output:
     * @return int ID ของ payment ที่สร้าง
     *
     * 🧠 เหตุผลเชิงออกแบบ:
     * - ทำไม borrow_id เป็น UNIQUE? เพราะ 1 การยืม = 1 การชำระเท่านั้น
     *   (ไม่รองรับการชำระบางส่วน หรือชำระซ้ำ)
     *
     * 🛡️ Security & Data Integrity:
     * - ต้องเรียกภายใต้ transaction + row lock จาก BorrowService
     * - UNIQUE constraint บน borrow_id ป้องกันชำระซ้ำ (DB level)
     * - prepared statement ป้องกัน SQL Injection
     *
     * ⚠️ Edge cases:
     * - ชำระซ้ำ → PDOException (UNIQUE violation) — BorrowService ดัก exception
     * - borrow_id ไม่มีจริง → FK constraint error
     *
     * ✅ Use case:
     * admin/payments.php POST action=pay_fine
     *   → BorrowService::payFine($borrowId, $staffId)
     *   → lock borrow row → ตรวจ fine > 0 → create($borrowId, $amount, $staffId)
     */
    public function create(int $borrowId, float $amount, ?int $recordedBy = null): int
    {
        // 📝 SQL: INSERT รายการชำระค่าปรับ
        // 🔴 borrow_id มี UNIQUE constraint — 1 borrow = 1 payment เท่านั้น
        //    INSERT ซ้ำ → PDOException (UNIQUE violation) → BorrowService ดัก
        // 🛡️ ต้องเรียกใน transaction + row lock จาก BorrowService
        $stmt = $this->pdo->prepare("
            INSERT INTO payments (borrow_id, amount, recorded_by) 
            VALUES (?, ?, ?)
        ");
        // 🚀 bind: [$borrowId, $amount, $recordedBy]
        //    $recordedBy = staff ID ที่บันทึก (อาจเป็น null ถ้าไม่ระบุ)
        $stmt->execute([$borrowId, $amount, $recordedBy]);
        // 📤 คืน payment ID ที่สร้าง
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงยอดค่าปรับที่รับชำระแล้วทั้งหมดตลอดกาล (สำหรับ dashboard)
     * ==========================================================================
     *
     * 🔄 Flow: SUM(amount) FROM payments
     *
     * 📤 Output:
     * @return float ยอดรวม (บาท) หรือ 0 ถ้ายังไม่มีรายการ
     *         - ใช้ใน: DashboardService แสดงสถิติ "ยอดค่าปรับที่รับแล้ว"
     *
     * 🧠 เหตุผล: COALESCE(..., 0) ป้องกัน NULL กรณีไม่มี rows
     * ✅ Use case: admin/index.php → DashboardService
     */
    public function getTotalCollected(): float
    {
        // 📝 SQL: รวมยอดชำระค่าปรับทั้งหมดตลอดกาล
        // 🧠 COALESCE(..., 0) = ถ้าไม่มีรายการเลย SUM = NULL → เปลี่ยนเป็น 0
        return (float) $this->pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments")->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ตรวจว่ายืมรายการนี้ชำระค่าปรับแล้วหรือยัง
     * ==========================================================================
     *
     * 📥 Input:
     * @param int $borrowId ID รายการยืม
     *                      - มาจาก: BorrowService::payFine() (ตรวจก่อนชำระ)
     *
     * 📤 Output:
     * @return array|null payment record หรือ null
     *         - คืนได้สูงสุด 1 record (UNIQUE constraint บน borrow_id)
     *
     * 🧠 เหตุผล: ใช้เป็น "guard" ตรวจก่อนชำระว่ายังไม่ได้จ่าย
     * ✅ Use case: BorrowService::payFine() เช็คก่อนว่าชำระแล้วหรือยัง
     */
    public function findByBorrowId(int $borrowId): ?array
    {
        // 📝 SQL: ดึงรายการชำระตาม borrow_id
        // 🧠 ใช้เป็น "guard" ตรวจก่อนชำระว่าจ่ายแล้วหรือยัง
        //    UNIQUE constraint รับประกันว่ามีได้สูงสุด 1 record ต่อ borrow
        $stmt = $this->pdo->prepare("SELECT * FROM payments WHERE borrow_id = ?");
        $stmt->execute([$borrowId]);
        // 📤 คืน payment record หรือ null (ยังไม่ชำระ)
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ยอดค่าปรับค้างชำระทั้งหมด (สำหรับ dashboard + payments page)
     * ==========================================================================
     * คำนวณยอด fine_amount จาก borrows ที่มีค่าปรับแต่ยังไม่มีรายการชำระ
     *
     * 🔄 Flow: SUM(fine_amount) FROM borrows LEFT JOIN payments WHERE fine > 0 AND payment = NULL
     *
     * 📤 Output:
     * @return float ยอดรวม (บาท) หรือ 0
     *         - ใช้ใน: DashboardService + admin/payments.php
     *
     * 🧠 เหตุผล:
     * - LEFT JOIN + p.id IS NULL = หา borrows ที่ยังไม่มี payment
     * - fine_amount > 0 กรองเฉพาะที่มีค่าปรับ (ไม่ได้นับ borrows ที่ไม่มีค่าปรับ)
     *
     * ✅ Use case: admin/index.php → DashboardService → "ค่าปรับค้างชำระ"
     */
    public function getUnpaidTotal(): float
    {
        // 📝 SQL: รวมยอดค่าปรับค้างชำระ (มีค่าปรับแต่ยังไม่มี payment)
        // 🧠 LEFT JOIN payments + p.id IS NULL = หา borrows ที่ยังไม่มี payment
        //    fine_amount > 0 = เฉพาะที่มีค่าปรับ
        //    COALESCE(..., 0) = ป้องกัน NULL กรณีไม่มี rows
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
     * 🎯 จุดประสงค์: ยอดค่าปรับที่รับชำระเดือนนี้ (สำหรับ dashboard)
     * ==========================================================================
     *
     * 🔄 Flow: SUM(amount) FROM payments WHERE MONTH = เดือนปัจจุบัน
     *
     * 📤 Output:
     * @return float ยอดรวม (บาท) หรือ 0
     *         - ใช้ใน: DashboardService แสดง "รายรับเดือนนี้"
     *
     * ✅ Use case: admin/index.php → DashboardService
     */
    public function getThisMonthTotal(): float
    {
        // 📝 SQL: รวมยอดชำระเดือนนี้
        // 🧠 MONTH(created_at) = MONTH(CURDATE()) + YEAR = YEAR
        //    กรองทั้งเดือน+ปี ป้องกันดึงข้ามปี (มกราคมปีนี้ vs มกราคมปีก่อน)
        return (float) $this->pdo->query("
            SELECT COALESCE(SUM(amount), 0) FROM payments 
            WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())
        ")->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการชำระทั้งหมด พร้อมข้อมูลสมาชิก/หนังสือ/เจ้าหน้าที่
     * ==========================================================================
     *
     * 🔄 Flow: payments JOIN borrows JOIN users JOIN books LEFT JOIN users(staff)
     *        + optional search filter + ORDER BY created_at DESC
     *
     * 📥 Input:
     * @param array $filters {search?: string}
     *              - search ค้นใน member_name, book_title, staff_name (LIKE %...%)
     *              - มาจาก: admin/payments.php GET params
     *
     * 📤 Output:
     * @return array[] แต่ละ element: {id, borrow_id, amount, recorded_by, payment_date,
     *                  borrow_date, return_date, member_name, book_title, staff_name}
     *         - ใช้ใน: admin/payments.php แสดงตารางประวัติการชำระ
     *
     * 🧠 เหตุผลเชิงออกแบบ:
     * - LEFT JOIN users staff เพราะ recorded_by อาจเป็น NULL (ไม่ระบุเจ้าหน้าที่)
     * - search ค้นใน 3 คอลัมน์พร้อมกัน (OR)
     *
     * 🛡️ Security: prepared statement + LIKE ใช้ parameterized
     * ✅ Use case: admin/payments.php GET
     */
    public function findAll(array $filters = []): array
    {
        // 🔧 สร้าง SQL ส่วน FROM/JOIN/WHERE (ใช้ร่วมกับ countAll ให้ผลตรงกันเสมอ)
        [$fromWhere, $params] = $this->buildListQuery($filters);

        // 📄 แบ่งหน้า — ใส่ LIMIT/OFFSET เฉพาะตอนที่ผู้เรียกส่ง limit มาเท่านั้น
        // 🧠 ไม่ส่ง limit = ดึงทั้งหมดเหมือนเดิม (ปุ่ม "พิมพ์รายงาน" ต้องได้ครบทุกแถว)
        // 🛡️ [SECURITY] cast เป็น int + clamp → ปลอดภัยแม้ค่ามาจาก $_GET
        $limitSQL = '';
        if (isset($filters['limit'])) {
            $limitSQL = 'LIMIT ? OFFSET ?';
            $params[] = max(1, (int) $filters['limit']);
            $params[] = max(0, (int) ($filters['offset'] ?? 0));
        }

        // 🧠 `, p.id DESC` คือตัวตัดสินเมื่อ created_at เท่ากัน (ชำระหลายรายการในวินาทีเดียว)
        //    ถ้าไม่มี MySQL เรียงไม่คงที่ → กดหน้า 2 เจอรายการซ้ำหรือตกหล่น
        $stmt = $this->pdo->prepare("
            SELECT p.*, p.created_at as payment_date, b.borrow_date, b.return_date,
                   u.name as member_name,
                   bk.title as book_title,
                   staff.name as staff_name
            {$fromWhere}
            ORDER BY p.created_at DESC, p.id DESC
            {$limitSQL}
        ");
        $stmt->execute($params);
        // 📤 คืน array ของรายการชำระในหน้านั้น (หรือทั้งหมดถ้าไม่ได้ส่ง limit)
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับจำนวนรายการชำระที่ตรงเงื่อนไข (ไม่สนใจ LIMIT)
     * ==========================================================================
     * ✅ Use case: admin/payments.php ต้องรู้ยอดรวมเพื่อคำนวณจำนวนหน้า
     *
     * ⚠️ ห้ามสับสนกับ getTotalCollected() ที่รวม "จำนวนเงิน" — ตัวนี้นับ "จำนวนแถว"
     *
     * 🧠 ใช้ buildListQuery() ตัวเดียวกับ findAll() — ยอดนับกับรายการที่แสดง
     *    จึงมาจากเงื่อนไขชุดเดียวกันเสมอ
     */
    public function countAll(array $filters = []): int
    {
        [$fromWhere, $params] = $this->buildListQuery($filters);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) {$fromWhere}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สร้างส่วน FROM/JOIN/WHERE + params ที่ findAll กับ countAll ใช้ร่วมกัน
     * ==========================================================================
     * 🧠 ทำไมคืนทั้งก้อน FROM ไม่ใช่แค่ WHERE: เงื่อนไข search อ้างถึงคอลัมน์จากตารางที่ JOIN มา
     *    (u.name, bk.title, staff.name) — ถ้าแยก WHERE ออกมาเดี่ยว ๆ query นับจะต้อง JOIN ซ้ำเอง
     *    แล้วมีโอกาสหลุดไม่ตรงกัน
     *
     * 📤 Output: [$fromWhere, $params]
     * 🛡️ [SECURITY] search bind ผ่าน ? ทั้งหมด
     */
    private function buildListQuery(array $filters): array
    {
        // 🧠 JOIN 3 ชั้น: payments → borrows → users + books
        //    LEFT JOIN users staff เพราะ recorded_by อาจเป็น NULL (ไม่ระบุเจ้าหน้าที่)
        $sql = "
            FROM payments p
            JOIN borrows b ON p.borrow_id = b.id
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            LEFT JOIN users staff ON p.recorded_by = staff.id
        ";

        // 🔍 Filter: ค้นหาจากชื่อสมาชิก, ชื่อหนังสือ, ชื่อเจ้าหน้าที่ (LIKE)
        $params = [];
        if (!empty($filters['search'])) {
            $sql .= " WHERE (u.name LIKE ? OR bk.title LIKE ? OR staff.name LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            // 📌 ใส่ 3 ค่าเพราะมี ? 3 ตัว (member_name, book_title, staff_name)
            $params = [$search, $search, $search];
        }

        return [$sql, $params];
    }
}

