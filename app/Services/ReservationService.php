<?php
/**
 * ReservationService - Business Logic สำหรับการจองหนังสือ
 * 
 * @package App\Services
 */

namespace App\Services;

use PDO;
use Exception;

class ReservationService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * จองหนังสือ (สำหรับ member ที่ต้องการยืมแต่ไม่สะดวกมารับทันที)
     * 
     * Flow: จอง → รอรับของ → admin อนุมัติ → เริ่มยืม
     * 
     * @param int $userId     ID ผู้จอง (member)
     * @param int $bookId     ID หนังสือ (ต้องมี available > 0)
     * @param int $expireDays จำนวนวันก่อนหมดอายุการจอง (default: 2)
     * 
     * @return array {
     *     success: bool,
     *     message: string,    // ข้อความแจ้งผล รวมวันหมดอายุ
     *     expires_at: string  // วันหมดอายุ (Y-m-d H:i:s)
     * }
     * 
     * @throws Exception เมื่อ:
     *     - ไม่พบหนังสือ
     *     - หนังสือหมด (available = 0)
     *     - มีการจอง pending อยู่แล้ว (จองเล่มเดิมซ้ำไม่ได้)
     * 
     * @sideeffect
     *     - INSERT ลง `reservations` (status = 'pending')
     *     - UPDATE `books.available` ลดลง 1 ทันที (กัน stock ไว้)
     * 
     * @security ใช้ FOR UPDATE lock ป้องกัน 2 คนจองเล่มสุดท้ายพร้อมกัน
     * 
     * @note stock ถูกหักทันทีตอนจอง - ถ้าหมดอายุ/ยกเลิก ต้องคืน stock กลับ
     */
    public function createReservation(int $userId, int $bookId, int $expireDays = 2): array
    {
        // [DB] Transaction สำคัญ - ต้อง atomic ระหว่าง INSERT reservation และ UPDATE stock
        $this->pdo->beginTransaction();

        try {
            // [STATE CHECK] 1. ตรวจซ้ำก่อน - ป้องกันจอง 2 ครั้ง (กด refresh, multi-tab)
            if ($this->hasPendingReservation($userId, $bookId)) {
                throw new Exception('คุณได้จองหนังสือเล่มนี้ไว้แล้ว กรุณารอรับหนังสือ');
            }

            // [CONCURRENCY] 2. FOR UPDATE ล็อคแถวหนังสือ - ป้องกัน race condition
            // กรณี 2 คนกดจองเล่มสุดท้ายพร้อมกัน → คนแรกได้ คนที่สอง error
            $stmt = $this->pdo->prepare("
                SELECT available, quantity, title FROM books WHERE id = ? FOR UPDATE
            ");
            $stmt->execute([$bookId]);
            $book = $stmt->fetch();

            if (!$book) {
                throw new Exception('ไม่พบหนังสือ');
            }

            // [STATE CHECK] ตรวจว่ามี stock หรือไม่
            if ($book['available'] <= 0) {
                throw new Exception('หนังสือหมด ไม่สามารถจองได้');
            }

            // [DB WRITE] 3. สร้าง reservation - status เริ่มต้น 'pending'
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expireDays} days"));

            $stmt = $this->pdo->prepare("
                INSERT INTO reservations (user_id, book_id, expires_at, status)
                VALUES (?, ?, ?, 'pending')
            ");
            $stmt->execute([$userId, $bookId, $expiresAt]);

            // [DB WRITE] 4. ลด stock ทันที - ป้องกันคนอื่นจอง/ยืมเล่มที่ถูกจองแล้ว
            // NOTE: ถ้า reservation หมดอายุ/ถูกยกเลิก ต้องคืน stock กลับ
            $stmt = $this->pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?");
            $stmt->execute([$bookId]);

            $this->pdo->commit();

            return [
                'success' => true,
                'message' => "จองสำเร็จ! กรุณามารับหนังสือ \"{$book['title']}\" ภายในวันที่ " . date('d/m/Y', strtotime($expiresAt)),
                'expires_at' => $expiresAt
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * ยกเลิกการจอง พร้อมคืน stock กลับ
     * 
     * State Transition: pending → cancelled
     * 
     * @param int      $reservationId ID การจอง (ต้องเป็น status = 'pending')
     * @param int|null $userId        ID ผู้ยกเลิก (ถ้าระบุ = ตรวจ ownership)
     *                                - null: admin ยกเลิกให้ใครก็ได้
     *                                - int: user ยกเลิกเอง (ต้องเป็นเจ้าของ)
     * 
     * @return array {success: bool, message: string}
     * 
     * @throws Exception เมื่อ:
     *     - ไม่พบรายการจอง
     *     - สถานะไม่ใช่ 'pending' (ยกเลิกไปแล้ว หรือ fulfilled)
     *     - userId ไม่ตรงกับเจ้าของ (ถ้าระบุ userId)
     * 
     * @sideeffect
     *     - UPDATE `reservations.status` = 'cancelled'
     *     - UPDATE `books.available` เพิ่มขึ้น 1 (คืน stock)
     * 
     * @security ใช้ FOR UPDATE lock ป้องกัน cancel/approve พร้อมกัน
     */
    public function cancelReservation(int $reservationId, int $userId = null): array
    {
        $this->pdo->beginTransaction();

        try {
            // [CONCURRENCY] FOR UPDATE ล็อคแถว reservation
            $sql = "SELECT * FROM reservations WHERE id = ? AND status = 'pending' FOR UPDATE";
            $params = [$reservationId];
            
            // [AUTHORIZATION] ถ้าระบุ userId = ตรวจว่าเป็นเจ้าของการจองหรือไม่
            // ใช้สำหรับ user ยกเลิกเอง (ไม่ใช่ admin ยกเลิกให้)
            if ($userId) {
                $sql = "SELECT * FROM reservations WHERE id = ? AND user_id = ? AND status = 'pending' FOR UPDATE";
                $params = [$reservationId, $userId];
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $reservation = $stmt->fetch();

            // [STATE CHECK] ต้องเป็น pending เท่านั้นถึงยกเลิกได้
            if (!$reservation) {
                throw new Exception('ไม่พบรายการจองหรือยกเลิกไปแล้ว');
            }

            // [DB WRITE] เปลี่ยนสถานะ
            $stmt = $this->pdo->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$reservationId]);

            // [DB WRITE] คืน stock กลับ - สำคัญมาก! ต้องทำใน transaction เดียวกัน
            $stmt = $this->pdo->prepare("UPDATE books SET available = available + 1 WHERE id = ?");
            $stmt->execute([$reservation['book_id']]);

            $this->pdo->commit();

            return [
                'success' => true,
                'message' => 'ยกเลิกการจองสำเร็จ'
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Mark การจองเป็น fulfilled (ผู้จองมารับหนังสือแล้ว)
     * 
     * State Transition: pending → fulfilled
     * 
     * @param int $reservationId ID การจอง (ต้องเป็น status = 'pending')
     * 
     * @return array {success: bool, message: string}
     * 
     * @throws Exception ถ้าไม่พบรายการหรือสถานะไม่ใช่ 'pending'
     * 
     * @sideeffect UPDATE `reservations.status` = 'fulfilled'
     * 
     * @note ไม่คืน stock เพราะผู้ใช้รับของไปแล้ว (stock ถูกหักตอนจอง)
     *       method นี้ใช้กรณี admin อนุมัติใน reservations.php แล้วสร้าง borrow แยก
     */
    public function fulfillReservation(int $reservationId): array
    {
        $stmt = $this->pdo->prepare("
            UPDATE reservations SET status = 'fulfilled' 
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->execute([$reservationId]);

        if ($stmt->rowCount() === 0) {
            throw new Exception('ไม่พบรายการจองหรือไม่อยู่ในสถานะรอรับ');
        }

        return [
            'success' => true,
            'message' => 'บันทึกการรับหนังสือสำเร็จ'
        ];
    }

    /**
     * ตรวจสอบและ expire การจองที่หมดอายุ (batch job)
     * 
     * State Transition: pending (expired) → expired
     * 
     * @return int จำนวนรายการที่ถูก expire
     * 
     * @sideeffect
     *     - UPDATE `reservations.status` = 'expired' สำหรับรายการที่หมดอายุ
     *     - UPDATE `books.available` เพิ่มขึ้น 1 ต่อรายการ (คืน stock)
     * 
     * @usecase เรียกจาก cron job หรือเมื่อเปิดหน้า admin
     *          เช่น: ทุกวัน 00:01 หรือเมื่อโหลดหน้า reservations.php
     */
    public function expireOverdueReservations(): int
    {
        $this->pdo->beginTransaction();

        try {
            // Get expired reservations
            $stmt = $this->pdo->prepare("
                SELECT id, book_id FROM reservations 
                WHERE status = 'pending' AND expires_at < NOW()
                FOR UPDATE
            ");
            $stmt->execute();
            $expired = $stmt->fetchAll();

            $count = 0;
            foreach ($expired as $res) {
                // Mark as expired
                $stmt = $this->pdo->prepare("UPDATE reservations SET status = 'expired' WHERE id = ?");
                $stmt->execute([$res['id']]);

                // Return stock
                $stmt = $this->pdo->prepare("UPDATE books SET available = available + 1 WHERE id = ?");
                $stmt->execute([$res['book_id']]);

                $count++;
            }

            $this->pdo->commit();
            return $count;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * ดึงรายการจองของ user (สำหรับหน้า profile)
     * 
     * @param int         $userId ID ผู้ใช้
     * @param string|null $status กรองตามสถานะ (null = ทั้งหมด)
     *                            'pending', 'fulfilled', 'expired', 'cancelled'
     * 
     * @return array รายการจอง (รวม book_title, book_author)
     */
    public function getUserReservations(int $userId, string $status = null): array
    {
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
     * ดึงรายการจองที่รอดำเนินการ (สำหรับ admin dashboard)
     * 
     * @param int $limit จำนวนรายการสูงสุด (default: 10)
     * @return array รายการจอง pending เรียงจากเก่าสุดก่อน (FIFO)
     *     รวม: user_name, user_phone, book_title
     */
    public function getPendingReservations(int $limit = 10): array
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
     * นับจำนวนการจองที่รอดำเนินการ (สำหรับ badge notification)
     * 
     * @return int จำนวน pending reservations
     */
    public function countPending(): int
    {
        return (int) $this->pdo->query("
            SELECT COUNT(*) FROM reservations WHERE status = 'pending'
        ")->fetchColumn();
    }

    /**
     * ตรวจสอบว่า user จองหนังสือเล่มนี้ไว้แล้วหรือไม่ (pending)
     * 
     * @param int $userId ID ผู้ใช้
     * @param int $bookId ID หนังสือ
     * @return bool true = มีการจองที่รอดำเนินการอยู่
     * 
     * @usecase ป้องกันจองหนังสือเล่มเดียวกันซ้ำ
     */
    public function hasPendingReservation(int $userId, int $bookId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM reservations 
            WHERE user_id = ? AND book_id = ? AND status = 'pending'
        ");
        $stmt->execute([$userId, $bookId]);
        return $stmt->fetch() !== false;
    }
}
