<?php
/**
 * ReservationService - Business Logic สำหรับการจองหนังสือ
 * 
 * @package App\Services
 */

namespace App\Services;

require_once __DIR__ . '/../Repositories/BookRepository.php';
require_once __DIR__ . '/../Repositories/ReservationRepository.php';

use App\Repositories\BookRepository;
use App\Repositories\ReservationRepository;
use PDO;
use Exception;

class ReservationService
{
    private PDO $pdo;
    private BookRepository $bookRepo;
    private ReservationRepository $reservationRepo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->bookRepo = new BookRepository($pdo);
        $this->reservationRepo = new ReservationRepository($pdo);
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
        $this->pdo->beginTransaction();

        try {
            // ตรวจซ้ำก่อน - ป้องกันจอง 2 ครั้ง
            if ($this->reservationRepo->hasPending($userId, $bookId)) {
                throw new Exception('คุณได้จองหนังสือเล่มนี้ไว้แล้ว กรุณารอรับหนังสือ');
            }

            // Lock หนังสือ - ป้องกัน race condition
            $book = $this->bookRepo->findByIdForUpdate($bookId);

            if (!$book) {
                throw new Exception('ไม่พบหนังสือ');
            }

            if ($book['available'] <= 0) {
                throw new Exception('หนังสือหมด ไม่สามารถจองได้');
            }

            // สร้าง reservation
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expireDays} days"));
            $this->reservationRepo->create($userId, $bookId, $expiresAt);

            // ลด stock ทันที
            $this->bookRepo->decrementAvailable($bookId);

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
     */
    public function cancelReservation(int $reservationId, int $userId = null): array
    {
        $this->pdo->beginTransaction();

        try {
            // Lock reservation
            $reservation = $this->reservationRepo->findPendingForUpdate($reservationId, $userId);

            if (!$reservation) {
                throw new Exception('ไม่พบรายการจองหรือยกเลิกไปแล้ว');
            }

            // เปลี่ยนสถานะ
            $this->reservationRepo->updateStatus($reservationId, 'cancelled');

            // คืน stock กลับ
            $this->bookRepo->incrementAvailable($reservation['book_id']);

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
     */
    public function fulfillReservation(int $reservationId): array
    {
        $result = $this->reservationRepo->updateStatus($reservationId, 'fulfilled');

        if (!$result) {
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
     */
    public function expireOverdueReservations(): int
    {
        $this->pdo->beginTransaction();

        try {
            // Get expired reservations with lock
            $expired = $this->reservationRepo->findExpiredForUpdate();

            $count = 0;
            foreach ($expired as $res) {
                // Mark as expired
                $this->reservationRepo->updateStatus($res['id'], 'expired');

                // Return stock
                $this->bookRepo->incrementAvailable($res['book_id']);

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
     */
    public function getUserReservations(int $userId, string $status = null): array
    {
        return $this->reservationRepo->findByUser($userId, $status);
    }

    /**
     * ดึงรายการจองที่รอดำเนินการ (สำหรับ admin dashboard)
     */
    public function getPendingReservations(int $limit = 10): array
    {
        return $this->reservationRepo->findPending($limit);
    }

    /**
     * นับจำนวนการจองที่รอดำเนินการ (สำหรับ badge notification)
     */
    public function countPending(): int
    {
        return $this->reservationRepo->countPending();
    }

    /**
     * ตรวจสอบว่า user จองหนังสือเล่มนี้ไว้แล้วหรือไม่ (pending)
     */
    public function hasPendingReservation(int $userId, int $bookId): bool
    {
        return $this->reservationRepo->hasPending($userId, $bookId);
    }
    
    /**
     * ดึงข้อมูลการจองที่รอดำเนินการของ user สำหรับหนังสือเล่มนี้
     */
    public function getUserPendingReservation(int $userId, int $bookId): ?array
    {
        return $this->reservationRepo->findByUserAndBook($userId, $bookId, 'pending');
    }
}
