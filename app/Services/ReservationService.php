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
     * จองหนังสือ
     * 
     * @param int $userId ผู้จอง
     * @param int $bookId หนังสือที่ต้องการจอง
     * @param int $expireDays จำนวนวันก่อนหมดอายุ (default: 2 วัน)
     * @return array ['success' => bool, 'message' => string]
     * @throws Exception
     */
    public function createReservation(int $userId, int $bookId, int $expireDays = 2): array
    {
        $this->pdo->beginTransaction();

        try {
            // 1. ตรวจสอบว่า User เคยจองเล่มนี้ไว้แล้วหรือยัง (สถานะ pending)
            if ($this->hasPendingReservation($userId, $bookId)) {
                throw new Exception('คุณได้จองหนังสือเล่มนี้ไว้แล้ว กรุณารอรับหนังสือ');
            }

            // 2. ตรวจสอบว่าหนังสือว่างไหม (พร้อมล็อคแถวเพื่อกันแย่งกันจอง)
            $stmt = $this->pdo->prepare("
                SELECT available, quantity, title FROM books WHERE id = ? FOR UPDATE
            ");
            $stmt->execute([$bookId]);
            $book = $stmt->fetch();

            if (!$book) {
                throw new Exception('ไม่พบหนังสือ');
            }

            if ($book['available'] <= 0) {
                throw new Exception('หนังสือหมด ไม่สามารถจองได้');
            }

            // 3. สร้างรายการจอง (Create Reservation)
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expireDays} days"));

            $stmt = $this->pdo->prepare("
                INSERT INTO reservations (user_id, book_id, expires_at, status)
                VALUES (?, ?, ?, 'pending')
            ");
            $stmt->execute([$userId, $bookId, $expiresAt]);

            // 4. ตัดสต็อกหนังสือ (Decrement Stock)
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
     * ยกเลิกการจอง
     */
    public function cancelReservation(int $reservationId, int $userId = null): array
    {
        $this->pdo->beginTransaction();

        try {
            // Get reservation
            $sql = "SELECT * FROM reservations WHERE id = ? AND status = 'pending' FOR UPDATE";
            $params = [$reservationId];
            
            // If userId provided, check ownership
            if ($userId) {
                $sql = "SELECT * FROM reservations WHERE id = ? AND user_id = ? AND status = 'pending' FOR UPDATE";
                $params = [$reservationId, $userId];
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $reservation = $stmt->fetch();

            if (!$reservation) {
                throw new Exception('ไม่พบรายการจองหรือยกเลิกไปแล้ว');
            }

            // Update status
            $stmt = $this->pdo->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$reservationId]);

            // Return stock
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
     * Mark การจองเป็น fulfilled (รับหนังสือแล้ว)
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
     * ตรวจสอบและ expire การจองที่หมดอายุ
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
     * ดึงรายการจองของ user
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
     * ดึงรายการจองที่รอดำเนินการ
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
     * นับจำนวนการจองที่รอดำเนินการ
     */
    public function countPending(): int
    {
        return (int) $this->pdo->query("
            SELECT COUNT(*) FROM reservations WHERE status = 'pending'
        ")->fetchColumn();
    }

    /**
     * ตรวจสอบว่า user มีการจอง pending อยู่หรือไม่
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
