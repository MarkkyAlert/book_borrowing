<?php
/**
 * ReservationRepository - Database Access สำหรับการจอง
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - ไฟล์นี้จัดการ SQL queries สำหรับตาราง reservations
 * - ห้ามเรียกจากหน้าเว็บโดยตรง ให้เรียกผ่าน ReservationService
 * 
 * 📌 Methods สำคัญ:
 * - create()              → INSERT reservation (status = 'pending')
 * - updateStatus()        → เปลี่ยน status
 * - markExpiredReservations() → [LAZY EXPIRE] หมดอายุ + คืน stock
 * - findPendingForUpdate() → SELECT ... FOR UPDATE (ป้องกัน race)
 * 
 * ⚠️ ห้ามแก้:
 * - markExpiredReservations() - ต้องคืน stock ด้วยเสมอ
 * - findPendingForUpdate() - มี lock ที่สำคัญ
 * 
 * @package App\Repositories
 */

namespace App\Repositories;

use PDO;

class ReservationRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * [LAZY EXPIRE] อัปเดต reservation ที่หมดอายุเป็น 'expired' + คืน stock อัตโนมัติ
     * 
     * @return int จำนวน reservations ที่ถูก expire
     * 
     * @note ใช้ transaction เพื่อให้ atomic: ถ้า expire แล้วต้องคืน stock ด้วย
     */
    public function markExpiredReservations(): int
    {
        // ดึงรายการที่หมดอายุก่อน
        $expiredStmt = $this->pdo->prepare("
            SELECT id, book_id FROM reservations 
            WHERE status = 'pending' AND expires_at < NOW()
        ");
        $expiredStmt->execute();
        $expiredList = $expiredStmt->fetchAll();
        
        if (empty($expiredList)) {
            return 0;
        }
        
        // ใช้ transaction เพื่อให้ atomic
        $this->pdo->beginTransaction();
        
        try {
            foreach ($expiredList as $res) {
                // Mark as expired
                $updateStmt = $this->pdo->prepare("
                    UPDATE reservations SET status = 'expired' 
                    WHERE id = ? AND status = 'pending'
                ");
                $updateStmt->execute([$res['id']]);
                
                // คืน stock (ถ้า update สำเร็จ)
                if ($updateStmt->rowCount() > 0) {
                    $stockStmt = $this->pdo->prepare("
                        UPDATE books SET available = available + 1 WHERE id = ?
                    ");
                    $stockStmt->execute([$res['book_id']]);
                }
            }
            
            $this->pdo->commit();
            return count($expiredList);
            
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            // Silent fail - lazy expire ไม่ควร block การทำงานหลัก
            return 0;
        }
    }

    /**
     * ดึงรายการจองทั้งหมด พร้อม filter
     * 
     * @param array $filters {
     *     status?: string ('pending', 'fulfilled', 'cancelled', 'expired'),
     *     user_id?: int,
     *     book_id?: int
     * }
     * 
     * @note จะ auto-expire reservations ที่หมดอายุก่อน query (lazy expiration)
     */
    public function findAll(array $filters = []): array
    {
        // [LAZY EXPIRE] Mark expired reservations ก่อน query
        $this->markExpiredReservations();
        
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "r.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['user_id'])) {
            $where[] = "r.user_id = ?";
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['book_id'])) {
            $where[] = "r.book_id = ?";
            $params[] = $filters['book_id'];
        }

        $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->pdo->prepare("
            SELECT r.*, u.name as user_name, u.email, b.title as book_title, b.cover_image
            FROM reservations r
            JOIN users u ON r.user_id = u.id
            JOIN books b ON r.book_id = b.id
            {$whereSQL}
            ORDER BY r.created_at DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * ดึงการจองตาม ID พร้อม user_name, email, book_title
     * 
     * @param int $id reservation ID
     * @return array|null ข้อมูลการจอง หรือ null ถ้าไม่พบ
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT r.*, u.name as user_name, u.email, b.title as book_title
            FROM reservations r
            JOIN users u ON r.user_id = u.id
            JOIN books b ON r.book_id = b.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * ดึงการจองของ user สำหรับ book เฉพาะเล่ม
     * 
     * @param int         $userId ผู้จอง
     * @param int         $bookId หนังสือที่จอง
     * @param string|null $status กรองตาม status หรือ null = ทุก status
     * @return array|null reservation record หรือ null
     */
    public function findByUserAndBook(int $userId, int $bookId, ?string $status = null): ?array
    {
        $sql = "SELECT * FROM reservations WHERE user_id = ? AND book_id = ?";
        $params = [$userId, $bookId];

        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    /**
     * สร้างการจองใหม่ (status = 'pending')
     * 
     * @param int    $userId    ID ผู้จอง
     * @param int    $bookId    ID หนังสือ
     * @param string $expiresAt วันหมดอายุ (Y-m-d H:i:s)
     * @return int ID ของ reservation ที่สร้าง
     * 
     * @sideeffect INSERT ลง reservations table
     * @note ต้อง decrement stock แยกต่างหากใน Service layer
     */
    public function create(int $userId, int $bookId, string $expiresAt): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO reservations (user_id, book_id, expires_at, status)
            VALUES (?, ?, ?, 'pending')
        ");
        $stmt->execute([$userId, $bookId, $expiresAt]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * อัปเดตสถานะการจอง (พร้อม state transition guard)
     * 
     * @param int $id reservation ID
     * @param string $newStatus สถานะใหม่
     * 
     * @return bool true = สำเร็จ, false = ไม่สามารถเปลี่ยนสถานะได้
     * 
     * @note State Transitions ที่อนุญาต:
     *     - pending → fulfilled, cancelled, expired
     *     - fulfilled, cancelled, expired → (ไม่สามารถเปลี่ยนได้)
     */
    public function updateStatus(int $id, string $newStatus): bool
    {
        // [GUARD] อนุญาตเฉพาะจาก pending เท่านั้น
        $allowedTransitions = ['fulfilled', 'cancelled', 'expired'];
        
        if (!in_array($newStatus, $allowedTransitions)) {
            return false;
        }
        
        $stmt = $this->pdo->prepare("
            UPDATE reservations 
            SET status = ? 
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->execute([$newStatus, $id]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * อัปเดตสถานะพร้อม link borrow_id (สำหรับ fulfill)
     * 
     * @param int $id reservation ID
     * @param string $status สถานะใหม่ ('fulfilled')
     * @param int $borrowId ID รายการยืมที่สร้าง
     * 
     * @return bool
     */
    public function updateStatusWithBorrow(int $id, string $status, int $borrowId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE reservations 
            SET status = ?, borrow_id = ? 
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->execute([$status, $borrowId, $id]);
        
        return $stmt->rowCount() > 0;
    }

    /**
     * นับจำนวน pending reservations ทั้งระบบ (สำหรับ badge notification)
     * 
     * @return int จำนวนรายการที่รอดำเนินการ
     */
    public function countPending(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn();
    }

    /**
     * ดึงรายการที่หมดอายุ (status=pending และเลย expires_at)
     * 
     * @return array[] reservation records ที่หมดอายุแล้วแต่ยังไม่ถูก expire
     */
    public function findExpired(): array
    {
        return $this->pdo->query("
            SELECT * FROM reservations 
            WHERE status = 'pending' AND expires_at < NOW()
        ")->fetchAll();
    }

    /**
     * ดึงการจอง pending พร้อม lock (สำหรับ transaction)
     * 
     * [CONCURRENCY] FOR UPDATE ป้องกัน:
     * - Double approve (admin 2 คนกดอนุมัติพร้อมกัน)
     * - Cancel หลัง approve (race condition)
     * 
     * @note ต้องเรียกภายใน transaction เท่านั้น
     */
    public function findPendingForUpdate(int $id, ?int $userId = null): ?array
    {
        $sql = "SELECT * FROM reservations WHERE id = ? AND status = 'pending' FOR UPDATE";
        $params = [$id];

        if ($userId !== null) {
            $sql = "SELECT * FROM reservations WHERE id = ? AND user_id = ? AND status = 'pending' FOR UPDATE";
            $params = [$id, $userId];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    /**
     * ดึงรายการหมดอายุพร้อม lock (สำหรับ batch expire ใน transaction)
     * 
     * @return array[] แต่ละ element: { id: int, book_id: int }
     * 
     * @note ต้องเรียกภายใน transaction — FOR UPDATE ล็อคแถวที่ match
     */
    public function findExpiredForUpdate(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, book_id FROM reservations 
            WHERE status = 'pending' AND expires_at < NOW()
            FOR UPDATE
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * ดึงการจองของ user
     * 
     * @note auto-expire reservations ที่หมดอายุก่อน query (lazy expiration)
     */
    public function findByUser(int $userId, ?string $status = null): array
    {
        // [LAZY EXPIRE] Mark expired reservations ก่อน query
        $this->markExpiredReservations();
        
        $sql = "
            SELECT r.*, b.title as book_title, b.author as book_author
            FROM reservations r
            JOIN books b ON r.book_id = b.id
            WHERE r.user_id = ?
        ";
        $params = [$userId];

        if ($status) {
            $sql .= " AND r.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY r.created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * ดึงรายการ pending (สำหรับ admin)
     */
    public function findPending(int $limit = 10): array
    {
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
        return $stmt->fetchAll();
    }

    /**
     * นับจำนวน pending reservations ของหนังสือ (ใช้ตรวจก่อนลบหนังสือ)
     * 
     * @param int $bookId ID หนังสือ
     * @return int จำนวนการจองที่รอดำเนินการ
     */
    public function countPendingByBook(int $bookId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM reservations 
            WHERE book_id = ? AND status = 'pending'
        ");
        $stmt->execute([$bookId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * นับจำนวน pending reservations ของ user (ใช้ตรวจก่อนลบสมาชิก)
     * 
     * @param int $userId ID สมาชิก
     * @return int จำนวนการจองที่รอดำเนินการ
     */
    public function countPendingByUser(int $userId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM reservations 
            WHERE user_id = ? AND status = 'pending'
        ");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ตรวจสอบว่า user จองหนังสือเล่มนี้อยู่แล้วหรือไม่ (ป้องกันจองซ้ำ)
     * 
     * @param int $userId ID ผู้จอง
     * @param int $bookId ID หนังสือ
     * @return bool true = มีการจอง pending อยู่แล้ว (จองซ้ำไม่ได้)
     */
    public function hasPending(int $userId, int $bookId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM reservations 
            WHERE user_id = ? AND book_id = ? AND status = 'pending'
        ");
        $stmt->execute([$userId, $bookId]);
        return $stmt->fetch() !== false;
    }
}

