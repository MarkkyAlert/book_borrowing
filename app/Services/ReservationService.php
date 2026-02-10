<?php
/**
 * ReservationService - Business Logic สำหรับการจองหนังสือ
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - ระบบจองให้ user จองหนังสือแล้วมารับทีหลัง
 * - สต็อกถูกหักทันทีตอนจอง (กัน stock ไว้)
 * - ถ้าไม่มารับภายในกำหนด → หมดอายุ → คืน stock
 * 
 * 🔄 State Transitions:
 * - pending   → fulfilled (admin อนุมัติ → สร้าง borrow)
 * - pending   → cancelled (user/admin ยกเลิก → คืน stock)
 * - pending   → expired   (cron job → คืน stock)
 * 
 * 📍 Entrypoints:
 * - api/reserve_book.php    → createReservation()
 * - admin/reservations.php  → fulfillReservation(), cancelReservation()
 * - cron/expire_reservations.php → expireOverdueReservations()
 * 
 * @package App\Services
 */

namespace App\Services;

require_once __DIR__ . '/../Repositories/BookRepository.php';
require_once __DIR__ . '/../Repositories/ReservationRepository.php';
require_once __DIR__ . '/../Repositories/BorrowRepository.php';

use App\Repositories\BookRepository;
use App\Repositories\ReservationRepository;
use App\Repositories\BorrowRepository;
use PDO;
use Exception;

class ReservationService
{
    private PDO $pdo;
    private BookRepository $bookRepo;
    private ReservationRepository $reservationRepo;
    private BorrowRepository $borrowRepo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->bookRepo = new BookRepository($pdo);
        $this->reservationRepo = new ReservationRepository($pdo);
        $this->borrowRepo = new BorrowRepository($pdo);
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
        // คืน stock จาก reservation ที่หมดอายุก่อน — ป้องกันหนังสือดูเหมือน "หมด" ทั้งที่ว่างแล้ว
        $this->reservationRepo->markExpiredReservations();

        $this->pdo->beginTransaction();

        try {
            // Lock หนังสือก่อน — ป้องกัน race condition (2 คนจองเล่มสุดท้ายพร้อมกัน)
            $book = $this->bookRepo->findByIdForUpdate($bookId);

            if (!$book) {
                throw new Exception('ไม่พบหนังสือ');
            }

            if ($book['available'] <= 0) {
                throw new Exception('หนังสือหมด ไม่สามารถจองได้');
            }

            // ตรวจซ้ำภายใต้ lock — ป้องกัน duplicate reservation จาก concurrent requests
            if ($this->reservationRepo->hasPending($userId, $bookId)) {
                throw new Exception('คุณได้จองหนังสือเล่มนี้ไว้แล้ว กรุณารอรับหนังสือ');
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
     * 
     * @param int $reservationId ID การจอง
     * @param int|null $userId   ID ผู้ใช้ (ถ้าส่งมา = ต้องเป็นเจ้าของถึงจะยกเลิกได้)
     *                           null = admin/staff ยกเลิกได้ทุกรายการ
     * 
     * @security ถ้าเรียกจาก member endpoint ต้องส่ง userId เสมอเพื่อป้องกัน authorization leak
     */
    public function cancelReservation(int $reservationId, ?int $userId = null): array
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
     * อนุมัติการจอง → สร้าง borrow record ให้อัตโนมัติ
     * 
     * State Transition: pending → fulfilled
     * 
     * @param int $reservationId ID การจอง
     * @param int|null $borrowDays จำนวนวันยืม (null = ใช้ DEFAULT_BORROW_DAYS)
     * 
     * @return array {
     *     success: bool,
     *     borrow_id: int,      // ID รายการยืมที่สร้าง
     *     due_date: string,    // วันกำหนดคืน
     *     message: string
     * }
     * 
     * @throws Exception เมื่อ:
     *     - ไม่พบรายการจอง
     *     - รายการไม่อยู่ในสถานะ pending
     *     - ผู้ยืมถึงโควต้าสูงสุด
     * 
     * @sideeffect
     *     - INSERT ลง `borrows` table (สร้างรายการยืม)
     *     - UPDATE `reservations.status` = 'fulfilled'
     *     - UPDATE `reservations.borrow_id` = borrow_id ที่สร้าง
     *     - ไม่ต้อง update stock เพราะหักไปแล้วตอนจอง
     */
    public function fulfillReservation(int $reservationId, ?int $borrowDays = null): array
    {
        $borrowDays = $borrowDays ?? DEFAULT_BORROW_DAYS;
        
        $this->pdo->beginTransaction();
        
        try {
            // [LOCK] ล็อค reservation ป้องกัน double approve
            $reservation = $this->reservationRepo->findPendingForUpdate($reservationId);
            
            if (!$reservation) {
                throw new Exception('ไม่พบรายการจองหรือไม่อยู่ในสถานะรอรับ');
            }
            
            // [VALIDATE] ตรวจว่ายืมเล่มนี้อยู่แล้วหรือไม่ (ป้องกัน duplicate borrow)
            if ($this->borrowRepo->isAlreadyBorrowing($reservation['user_id'], $reservation['book_id'])) {
                throw new Exception('ผู้จองกำลังยืมหนังสือเล่มนี้อยู่แล้ว');
            }
            
            // [VALIDATE] ตรวจโควต้าผู้ยืม (ใช้ FOR UPDATE ป้องกัน race condition)
            $currentBorrows = $this->borrowRepo->countActiveBorrowsForUpdate($reservation['user_id']);
            if ($currentBorrows >= MAX_BORROW_BOOKS) {
                throw new Exception('ผู้จองถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว (' . MAX_BORROW_BOOKS . ' เล่ม)');
            }
            
            // [CREATE] สร้าง borrow record
            $borrowDate = date('Y-m-d');
            $dueDate = date('Y-m-d', strtotime("+{$borrowDays} days"));
            
            $borrowId = $this->borrowRepo->create([
                'user_id' => $reservation['user_id'],
                'book_id' => $reservation['book_id'],
                'borrow_date' => $borrowDate,
                'due_date' => $dueDate
            ]);
            
            // [STATE] เปลี่ยนสถานะ + link borrow_id
            $this->reservationRepo->updateStatusWithBorrow($reservationId, 'fulfilled', $borrowId);
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'borrow_id' => $borrowId,
                'due_date' => $dueDate,
                'message' => 'อนุมัติการจองสำเร็จ! สร้างรายการยืมแล้ว กำหนดคืน: ' . date('d/m/Y', strtotime($dueDate))
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
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
