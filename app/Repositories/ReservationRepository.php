<?php
/**
 * ReservationRepository - Database Access สำหรับการจอง
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * Repository นี้จัดการ SQL queries สำหรับตาราง reservations (การจองหนังสือ)
 * มีระบบ "lazy expiration" — expire ตอนที่มีคน query ไม่ใช้ cron job
 *
 * 📚 โครงสร้างตาราง reservations:
 * +--------------+--------------+----------------------------------------------+
 * | Column       | Type         | อธิบาย                                       |
 * +--------------+--------------+----------------------------------------------+
 * | id           | INT AUTO PK  | Primary Key                                  |
 * | user_id      | INT FK       | ผู้จอง → users.id                         |
 * | book_id      | INT FK       | หนังสือ → books.id                        |
 * | status       | ENUM         | 'pending','fulfilled','cancelled','expired'  |
 * | expires_at   | DATETIME     | เวลาหมดอายุ                                |
 * | borrow_id    | INT FK NULL  | ถ้า fulfilled → borrows.id                  |
 * | created_at   | DATETIME     | เวลาสร้าง                                  |
 * +--------------+--------------+----------------------------------------------+
 *
 * 📍 Entrypoints:
 * - ReservationService    → create(), updateStatus(), findPendingForUpdate(),
 *                           hasPending(), findByUserAndBook()
 * - admin/reservations.php → findAll(), findPending()
 * - my_reservations.php   → findByUser()
 * - DashboardService      → countPending()
 * - BookService           → countPendingByBook()
 * - MemberService         → countPendingByUser()
 *
 * 🛡️ Security Design:
 * - findPendingForUpdate() = row lock ป้องกัน double approve / cancel after approve
 * - updateStatus() มี state guard: เปลี่ยนได้เฉพาะจาก pending
 * - markExpiredReservations() ใช้ transaction เพื่อ atomic expire + คืน stock
 *
 * ⚠️ ห้ามแก้:
 * - markExpiredReservations() ต้องคืน stock ด้วยเสมอ
 * - findPendingForUpdate() ต้องเรียกใน transaction
 *
 * @package App\Repositories
 */

namespace App\Repositories;

use PDO;

class ReservationRepository
{
    // 🗄️ PDO connection — inject ผ่าน constructor ใช้ร่วมกันทุกเมธอด
    private PDO $pdo;

    // 🏗️ Constructor: รับ PDO จากภายนอก (Dependency Injection)
    // → ใช้ connection เดียวกับ ReservationService
    // → ทำให้ transaction + FOR UPDATE lock ทำงานถูกต้อง
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: [LAZY EXPIRE] expire reservation หมดอายุ + คืน stock
     * ==========================================================================
     * ถูกเรียกอัตโนมัติจาก findAll() / findByUser() ก่อน query
     * ไม่ใช้ cron job — expire ตอนที่มีคนเข้ามาดู
     *
     * 🔄 Flow:
     * 1. SELECT pending + expires_at < NOW()
     * 2. BEGIN TRANSACTION
     * 3. วน loop: UPDATE status='expired' + books.available +1
     * 4. COMMIT
     *
     * 📤 Output: @return int จำนวนที่ถูก expire
     *
     * 🛡️ Security: transaction เพื่อ atomic (expire + คืน stock พร้อมกัน)
     * ⚠️ ห้ามแก้: ต้องคืน stock ด้วยเสมอ (ตอนจองหัก stock ไว้)
     * ✅ Use case: findAll(), findByUser() เรียกก่อน query
     */
    public function markExpiredReservations(): int
    {
        // 📝 Step 1: ดึงรายการที่หมดอายุก่อน (pending + expires_at < NOW)
        //    ดึงเฉพาะ id + book_id (ใช้คืน stock)
        $expiredStmt = $this->pdo->prepare("
            SELECT id, book_id FROM reservations 
            WHERE status = 'pending' AND expires_at < NOW()
        ");
        $expiredStmt->execute();
        $expiredList = $expiredStmt->fetchAll();
        
        // ✅ ไม่มีรายการหมดอายุ → ไม่ต้องทำอะไร
        if (empty($expiredList)) {
            return 0;
        }
        
        // 📝 Step 2: เปิด transaction เพื่อให้ atomic
        //    ต้อง expire + คืน stock พร้อมกัน (ไม่ทำครึ่งๆ ได้)
        $this->pdo->beginTransaction();
        
        try {
            // 📝 Step 3: วน loop แต่ละรายการที่หมดอายุ
            foreach ($expiredList as $res) {
                // 🔄 UPDATE status = 'expired' (เฉพาะที่ยัง pending)
                // 🛡️ WHERE status='pending' = guard ป้องกัน expire ซ้ำ
                $updateStmt = $this->pdo->prepare("
                    UPDATE reservations SET status = 'expired' 
                    WHERE id = ? AND status = 'pending'
                ");
                $updateStmt->execute([$res['id']]);
                
                // 📦 คืน stock +1 (เพราะตอนจองหัก stock ไว้)
                // ⚠️ ห้ามลบขั้นตอนนี้! ถ้าไม่คืน stock → หนังสือจะหายไป 1 เล่ม
                if ($updateStmt->rowCount() > 0) {
                    $stockStmt = $this->pdo->prepare("
                        UPDATE books SET available = available + 1 WHERE id = ?
                    ");
                    $stockStmt->execute([$res['book_id']]);
                }
            }
            
            // 📝 Step 4: commit ทั้งหมดพร้อมกัน (atomic)
            $this->pdo->commit();
            return count($expiredList);
            
        } catch (\Exception $e) {
            // ❌ rollback ทั้งหมดถ้ามี error
            $this->pdo->rollBack();
            // 🧠 Silent fail — lazy expire ไม่ควร block การทำงานหลัก
            //    ถ้าล้มเหลว ครั้งหน้าจะลองอีกครั้ง
            return 0;
        }
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการจองทั้งหมด (auto-expire ก่อน query)
     * ==========================================================================
     *
     * 📥 Input:
     * @param array $filters {
     *     status?: string  — 'pending','fulfilled','cancelled','expired'
     *     user_id?: int    — กรองเฉพาะ user
     *     book_id?: int    — กรองเฉพาะหนังสือ
     * }
     *
     * 📤 Output:
     * @return array [{reservation row + user_name, email, book_title, cover_image}, ...]
     *
     * 🧠 เหตุผล: เรียก markExpiredReservations() ก่อน query (lazy expire)
     * ✅ Use case: admin/reservations.php GET
     */
    public function findAll(array $filters = []): array
    {
        // 🔄 [LAZY EXPIRE] expire รายการหมดอายุก่อนจะ query
        //    ทำให้ข้อมูลที่แสดงเป็นปัจจุบันเสมอ
        $this->markExpiredReservations();
        
        // 📦 สร้างเงื่อนไข WHERE จาก filters
        $where = [];
        $params = [];

        // 🏷️ Filter: สถานะ (pending/fulfilled/cancelled/expired)
        if (!empty($filters['status'])) {
            $where[] = "r.status = ?";
            $params[] = $filters['status'];
        }

        // 👤 Filter: เฉพาะ user
        if (!empty($filters['user_id'])) {
            $where[] = "r.user_id = ?";
            $params[] = $filters['user_id'];
        }

        // 📖 Filter: เฉพาะหนังสือ
        if (!empty($filters['book_id'])) {
            $where[] = "r.book_id = ?";
            $params[] = $filters['book_id'];
        }

        // 🔗 ประกอบ WHERE clause
        $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // 📝 SQL: ดึงการจอง + ข้อมูล user + book
        $stmt = $this->pdo->prepare("
            SELECT r.*, u.name as user_name, u.email, b.title as book_title, b.cover_image
            FROM reservations r
            JOIN users u ON r.user_id = u.id
            JOIN books b ON r.book_id = b.id
            {$whereSQL}
            ORDER BY r.created_at DESC
        ");
        $stmt->execute($params);
        // 📤 คืน array การจองทั้งหมดที่ตรงเงื่อนไข
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงการจองตาม ID (พร้อม user_name, book_title)
     * ==========================================================================
     *
     * 📥 Input: @param int $id Reservation ID
     * 📤 Output: @return array|null reservation row + user + book หรือ null
     * ✅ Use case: admin/reservations.php?view=ID
     */
    public function findById(int $id): ?array
    {
        // 📝 SQL: ดึงการจองตาม ID + JOIN user + book
        $stmt = $this->pdo->prepare("
            SELECT r.*, u.name as user_name, u.email, b.title as book_title
            FROM reservations r
            JOIN users u ON r.user_id = u.id
            JOIN books b ON r.book_id = b.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        // 📤 คืน reservation row + user + book หรือ null
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงการจองของ user+book (เฉพาะเล่ม)
     * ==========================================================================
     *
     * 📥 Input:
     * @param int         $userId ผู้จอง
     * @param int         $bookId หนังสือ
     * @param string|null $status กรอง status (null = ทุก status)
     *
     * 📤 Output: @return array|null reservation record หรือ null
     * ✅ Use case: ReservationService เช็คก่อน fulfill
     */
    public function findByUserAndBook(int $userId, int $bookId, ?string $status = null): ?array
    {
        // 📝 SQL: ค้นการจองของ user+book (เฉพาะเล่ม)
        $sql = "SELECT * FROM reservations WHERE user_id = ? AND book_id = ?";
        $params = [$userId, $bookId];

        // 🏷️ ถ้าระบุ status → กรองเพิ่ม (null = ทุก status)
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        // 📤 คืน reservation record หรือ null
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สร้างการจองใหม่ (status='pending' อัตโนมัติ)
     * ==========================================================================
     *
     * 📥 Input:
     * @param int    $userId    ผู้จอง
     * @param int    $bookId    หนังสือ
     * @param string $expiresAt Y-m-d H:i:s เวลาหมดอายุ
     *
     * 📤 Output: @return int Reservation ID ที่สร้าง
     *
     * 🧠 เหตุผล: INSERT เท่านั้น — decrement stock ทำใน Service layer
     * ✅ Use case: ReservationService::createReservation()
     */
    public function create(int $userId, int $bookId, string $expiresAt): int
    {
        // 📝 SQL: INSERT การจองใหม่ (status = 'pending' อัตโนมัติ)
        // 🧠 INSERT เท่านั้น — decrement stock ทำใน Service layer
        //    Repository ทำแค่ SQL ไม่มี business logic
        $stmt = $this->pdo->prepare("
            INSERT INTO reservations (user_id, book_id, expires_at, status)
            VALUES (?, ?, ?, 'pending')
        ");
        // 🚀 bind: [$userId, $bookId, $expiresAt]
        $stmt->execute([$userId, $bookId, $expiresAt]);
        // 📤 คืน Reservation ID ที่สร้าง
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: เปลี่ยน status (พร้อม state transition guard)
     * ==========================================================================
     *
     * 📥 Input:
     * @param int    $id        Reservation ID
     * @param string $newStatus 'fulfilled' | 'cancelled' | 'expired'
     *
     * 📤 Output: @return bool true = สำเร็จ, false = ไม่สามารถเปลี่ยนได้
     *
     * 🛡️ Security:
     * - PHP guard: in_array($newStatus, allowedTransitions)
     * - SQL guard: WHERE status='pending' — เปลี่ยนได้เฉพาะจาก pending
     *
     * ⚠️ State machine: pending → {fulfilled, cancelled, expired}
     *    fulfilled/cancelled/expired → (ไม่สามารถเปลี่ยน)
     * ✅ Use case: ReservationService::cancelReservation(),
     *            markExpiredReservations()
     */
    public function updateStatus(int $id, string $newStatus): bool
    {
        // 🛡️ [PHP GUARD] whitelist: อนุญาตเฉพาะ 3 สถานะเท่านั้น
        //    ค่าอื่น → return false ทันที (ปลอดภัย)
        $allowedTransitions = ['fulfilled', 'cancelled', 'expired'];
        
        if (!in_array($newStatus, $allowedTransitions)) {
            return false;
        }
        
        // 📝 SQL: เปลี่ยน status (เฉพาะจาก pending เท่านั้น)
        // 🛡️ [SQL GUARD] WHERE status='pending' = ป้องกัน state machine
        //    pending → {fulfilled, cancelled, expired}
        //    fulfilled/cancelled/expired → ไม่สามารถเปลี่ยน (0 rows affected)
        $stmt = $this->pdo->prepare("
            UPDATE reservations 
            SET status = ? 
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->execute([$newStatus, $id]);
        
        // 📤 rowCount() > 0 = เปลี่ยนสำเร็จ, = 0 = ไม่ได้ (ไม่ใช่ pending)
        return $stmt->rowCount() > 0;
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: เปลี่ยน status + link borrow_id (สำหรับ fulfill)
     * ==========================================================================
     * เมื่อ admin อนุมัติการจอง → สร้าง borrow + link กลับมา
     *
     * 📥 Input:
     * @param int    $id       Reservation ID
     * @param string $status   'fulfilled'
     * @param int    $borrowId Borrow ID ที่สร้าง
     *
     * 📤 Output: @return bool true = สำเร็จ
     * ✅ Use case: ReservationService::fulfillReservation()
     */
    public function updateStatusWithBorrow(int $id, string $status, int $borrowId): bool
    {
        // 📝 SQL: เปลี่ยน status + link borrow_id พร้อมกัน
        // 🧠 ใช้ตอน fulfill: admin อนุมัติการจอง → สร้าง borrow → link กลับมา
        // 🛡️ WHERE status='pending' = guard ป้องกัน double approve
        $stmt = $this->pdo->prepare("
            UPDATE reservations 
            SET status = ?, borrow_id = ? 
            WHERE id = ? AND status = 'pending'
        ");
        // 🚀 bind: [$status, $borrowId, $id]
        $stmt->execute([$status, $borrowId, $id]);
        
        // 📤 rowCount() > 0 = สำเร็จ
        return $stmt->rowCount() > 0;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับ pending reservations ทั้งระบบ (สำหรับ badge)
     * ==========================================================================
     *
     * 📤 Output: @return int จำนวนที่รอดำเนินการ
     * ✅ Use case: admin/header.php badge, DashboardService
     */
    public function countPending(): int
    {
        // 📝 SQL: นับ pending reservations ทั้งระบบ
        // 🧠 ใช้แสดง badge ใน admin header + Dashboard
        return (int) $this->pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการที่หมดอายุ (ยังไม่ถูก expire)
     * ==========================================================================
     *
     * 📤 Output: @return array[] pending + expires_at < NOW()
     * ✅ Use case: ตรวจสอบก่อน batch expire
     */
    public function findExpired(): array
    {
        // 📝 SQL: ดึงรายการที่หมดอายุแต่ยังไม่ถูก mark เป็น 'expired'
        // 🧠 ใช้ตรวจสอบก่อน batch expire
        return $this->pdo->query("
            SELECT * FROM reservations 
            WHERE status = 'pending' AND expires_at < NOW()
        ")->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: Lock reservation pending + SELECT FOR UPDATE
     * ==========================================================================
     * ป้องกัน: double approve / cancel หลัง approve
     *
     * 📥 Input:
     * @param int      $id     Reservation ID
     * @param int|null $userId ถ้าระบุ → กรองว่าเป็นของ user นี้ (member cancel)
     *
     * 📤 Output: @return array|null reservation row (ถูก lock) หรือ null
     *
     * 🛡️ Security:
     * - FOR UPDATE = row lock
     * - AND status='pending' = กรองเฉพาะที่ยัง pending
     * - $userId เพิ่มเพื่อให้ member ยกเลิกได้เฉพาะของตัวเอง
     *
     * ✅ Use case: ReservationService::fulfillReservation(),
     *            ReservationService::cancelReservation()
     */
    public function findPendingForUpdate(int $id, ?int $userId = null): ?array
    {
        // 📝 SQL: SELECT + FOR UPDATE = ดึง + ล็อกแถว
        //    เฉพาะ pending เท่านั้น (fulfilled/cancelled ไม่คืน)
        // 🛡️ FOR UPDATE = row lock ป้องกัน double approve / cancel after approve
        // ⚠️ ต้องเรียกใน transaction!
        $sql = "SELECT * FROM reservations WHERE id = ? AND status = 'pending' FOR UPDATE";
        $params = [$id];

        // 👤 ถ้าระบุ $userId → เพิ่มเงื่อนไขว่าเป็นของ user นี้
        //    ใช้ตอน member ยกเลิกการจองของตัวเอง (ป้องกันยกเลิกของคนอื่น)
        if ($userId !== null) {
            $sql = "SELECT * FROM reservations WHERE id = ? AND user_id = ? AND status = 'pending' FOR UPDATE";
            $params = [$id, $userId];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        // 📤 คืน reservation row (ถูกล็อก) หรือ null
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: Lock รายการหมดอายุ + SELECT FOR UPDATE (batch expire)
     * ==========================================================================
     *
     * 📤 Output: @return array[] [{id, book_id}, ...] (ถูก lock)
     * ✅ Use case: batch expire process ใน transaction
     */
    public function findExpiredForUpdate(): array
    {
        // 📝 SQL: ดึงรายการหมดอายุ + lock ทั้งหมดพร้อมกัน
        // 🛡️ FOR UPDATE = ล็อกทุกแถวที่คืน — ป้องกัน expire ซ้ำจาก 2 process
        // ⚠️ ต้องเรียกใน transaction!
        $stmt = $this->pdo->prepare("
            SELECT id, book_id FROM reservations 
            WHERE status = 'pending' AND expires_at < NOW()
            FOR UPDATE
        ");
        $stmt->execute();
        // 📤 คืน [{id, book_id}, ...] (ถูกล็อก)
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงการจองของ user (auto-expire ก่อน query)
     * ==========================================================================
     *
     * 📥 Input:
     * @param int         $userId
     * @param string|null $status กรอง (null = ทั้งหมด)
     *
     * 📤 Output: @return array [{reservation row + book_title, book_author}, ...]
     *
     * 🧠 เหตุผล: เรียก markExpiredReservations() ก่อน (lazy expire)
     * ✅ Use case: my_reservations.php
     */
    public function findByUser(int $userId, ?string $status = null): array
    {
        // 🔄 [LAZY EXPIRE] expire รายการหมดอายุก่อนจะ query
        $this->markExpiredReservations();
        
        // 📝 SQL: ดึงการจองของ user + JOIN book
        $sql = "
            SELECT r.*, b.title as book_title, b.author as book_author
            FROM reservations r
            JOIN books b ON r.book_id = b.id
            WHERE r.user_id = ?
        ";
        $params = [$userId];

        // 🏷️ ถ้าระบุ status → กรองเพิ่ม (null = ทุก status)
        if ($status) {
            $sql .= " AND r.status = ?";
            $params[] = $status;
        }

        // เรียงจากใหม่สุดก่อน
        $sql .= " ORDER BY r.created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        // 📤 คืน array การจองของ user + ข้อมูลหนังสือ
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการ pending (สำหรับ admin dashboard)
     * ==========================================================================
     *
     * 📥 Input: @param int $limit (default: 10)
     * 📤 Output: @return array [{reservation row + user_name, user_phone, book_title}, ...]
     * ✅ Use case: admin/index.php → DashboardService → "รายการรอดำเนินการ"
     */
    public function findPending(int $limit = 10): array
    {
        // 📝 SQL: ดึง pending reservations + user + book
        // ORDER BY created_at ASC = เก่าสุดก่อน (คนที่จองก่อนขึ้นก่อน)
        // LIMIT ? = จำกัดจำนวน (default: 10 สำหรับ Dashboard)
        $stmt = $this->pdo->prepare("
            SELECT r.*, u.name as user_name, u.phone as user_phone,
                   b.title as book_title
            FROM reservations r
            JOIN users u ON r.user_id = u.id
            JOIN books b ON r.book_id = b.id
            WHERE r.status = 'pending'
            ORDER BY r.created_at ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        // 📤 คืน array รายการรอดำเนินการ → Dashboard
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับ pending reservations ของหนังสือ (ตรวจก่อนลบ)
     * ==========================================================================
     *
     * 📥 Input: @param int $bookId
     * 📤 Output: @return int จำนวนที่รอ pending
     * ✅ Use case: BookService::deleteBook() เช็คก่อนลบ
     */
    public function countPendingByBook(int $bookId): int
    {
        // 📝 SQL: นับ pending reservations ของหนังสือนี้
        // 🧠 ใช้เป็น guard ก่อนลบหนังสือ
        //    ถ้ามีคนจองอยู่ → ห้ามลบ
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM reservations 
            WHERE book_id = ? AND status = 'pending'
        ");
        $stmt->execute([$bookId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับ pending reservations ของ user (ตรวจก่อนลบสมาชิก)
     * ==========================================================================
     *
     * 📥 Input: @param int $userId
     * 📤 Output: @return int จำนวนที่รอ pending
     * ✅ Use case: MemberService::deleteMember() เช็คก่อนลบ
     */
    public function countPendingByUser(int $userId): int
    {
        // 📝 SQL: นับ pending reservations ของ user นี้
        // 🧠 ใช้เป็น guard ก่อนลบสมาชิก
        //    ถ้ามีการจองค้างอยู่ → ห้ามลบ
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM reservations 
            WHERE user_id = ? AND status = 'pending'
        ");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ตรวจว่า user จองหนังสือนี้อยู่แล้วหรือไม่ (ป้องกันจองซ้ำ)
     * ==========================================================================
     *
     * 📥 Input: @param int $userId, @param int $bookId
     * 📤 Output: @return bool true = มี pending อยู่แล้ว (ห้ามจองซ้ำ)
     * ✅ Use case: ReservationService::createReservation() เช็คก่อนจอง
     */
    public function hasPending(int $userId, int $bookId): bool
    {
        // 📝 SQL: ตรวจว่า user จองหนังสือนี้อยู่แล้วหรือไม่ (pending)
        // 🧠 ใช้เป็น guard ก่อนสร้างการจองใหม่ — ป้องกันจองซ้ำ
        $stmt = $this->pdo->prepare("
            SELECT id FROM reservations 
            WHERE user_id = ? AND book_id = ? AND status = 'pending'
        ");
        $stmt->execute([$userId, $bookId]);
        // 📤 fetch() !== false = มี pending อยู่ (true → ห้ามจองซ้ำ)
        return $stmt->fetch() !== false;
    }
}

