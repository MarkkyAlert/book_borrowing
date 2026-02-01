<?php
/**
 * BorrowService - Business Logic สำหรับการยืม-คืนหนังสือ
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - ไฟล์นี้คือ "สมอง" ของระบบยืม-คืน
 * - ถูกเรียกจาก admin/borrow_form.php, admin/borrows.php
 * - ห้ามเรียก Repository โดยตรงจากหน้าเว็บ ให้เรียกผ่าน Service นี้
 * 
 * 🔄 Flow หลัก:
 * 1. createBorrow() → สร้างรายการยืม (หักสต็อก)
 * 2. returnBook()   → คืนหนังสือ (คืนสต็อก + คำนวณค่าปรับ)
 * 
 * ⚙️ ถ้าต้องการแก้กฎ:
 * - จำนวนวันยืม/เล่มสูงสุด → แก้ที่ includes/config.php (MAX_BORROW_BOOKS, DEFAULT_BORROW_DAYS)
 * - สูตรค่าปรับ           → แก้ที่ calculateFine() ในไฟล์นี้
 * 
 * @package App\Services
 */

namespace App\Services;

require_once __DIR__ . '/../Repositories/BookRepository.php';
require_once __DIR__ . '/../Repositories/BorrowRepository.php';
require_once __DIR__ . '/../Repositories/UserRepository.php';
require_once __DIR__ . '/../Repositories/PaymentRepository.php';

use App\Repositories\BookRepository;
use App\Repositories\BorrowRepository;
use App\Repositories\UserRepository;
use App\Repositories\PaymentRepository;
use PDO;
use Exception;

class BorrowService
{
    private PDO $pdo;
    private BookRepository $bookRepo;
    private BorrowRepository $borrowRepo;
    private UserRepository $userRepo;
    private PaymentRepository $paymentRepo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->bookRepo = new BookRepository($pdo);
        $this->borrowRepo = new BorrowRepository($pdo);
        $this->userRepo = new UserRepository($pdo);
        $this->paymentRepo = new PaymentRepository($pdo);
    }

    /**
     * สร้างรายการยืมหนังสือ (รองรับยืมหลายเล่มพร้อมกัน)
     * 
     * @param int      $userId     ID ผู้ยืม (ต้องเป็น member เท่านั้น)
     * @param array    $bookIds    รายการ ID หนังสือ (array of int, ไม่เกิน MAX_BORROW_BOOKS)
     * @param int|null $borrowDays จำนวนวันยืม 1-30 (null = ใช้ DEFAULT_BORROW_DAYS)
     * 
     * @return array {
     *     success: bool,           // true ถ้ายืมได้อย่างน้อย 1 เล่ม
     *     borrowed: string[],      // รายชื่อหนังสือที่ยืมสำเร็จ
     *     skipped: string[],       // รายชื่อหนังสือที่ข้าม พร้อมเหตุผล
     *     due_date: string,        // วันกำหนดคืน (Y-m-d)
     *     message: string          // ข้อความสรุปผล
     * }
     * 
     * @throws Exception เมื่อ:
     *     - userId ไม่ถูกต้องหรือไม่ใช่ member
     *     - bookIds ว่าง
     *     - borrowDays ไม่อยู่ในช่วง 1-30
     *     - ผู้ยืมถึงโควต้าสูงสุด (MAX_BORROW_BOOKS)
     * 
     * @sideeffect
     *     - INSERT ลง `borrows` table
     *     - UPDATE `books.available` ลดลง 1 ต่อเล่ม
     * 
     * @security ใช้ FOR UPDATE lock ป้องกัน race condition (ยืมทะลุโควต้า)
     */
    public function createBorrow(int $userId, array $bookIds, int $borrowDays = null): array
    {
        // ใช้ค่า default จาก config ถ้าไม่ระบุ
        $borrowDays = $borrowDays ?? DEFAULT_BORROW_DAYS;
        
        // Validate
        if ($userId <= 0) {
            throw new Exception('กรุณาเลือกผู้ยืม');
        }
        
        if (empty($bookIds)) {
            throw new Exception('กรุณาเลือกหนังสืออย่างน้อย 1 เล่ม');
        }
        
        if ($borrowDays < 1 || $borrowDays > 30) {
            throw new Exception('จำนวนวันยืมต้องอยู่ระหว่าง 1-30 วัน');
        }

        // Validate user exists and is member
        $user = $this->userRepo->findMemberById($userId);
        if (!$user) {
            throw new Exception('ไม่พบสมาชิกที่เลือก');
        }

        $borrowDate = date('Y-m-d');
        $dueDate = date('Y-m-d', strtotime("+{$borrowDays} days"));

        $this->pdo->beginTransaction();

        try {
            // 🔒 Critical Fix: ล็อคแถวข้อมูลผู้ใช้งาน (User Row) ก่อนเป็นอันดับแรก
            $this->userRepo->lockById($userId);

            // ตรวจสอบจำนวนหนังสือที่ยืมอยู่ปัจจุบัน
            $currentBorrows = $this->borrowRepo->countActiveBorrowsForUpdate($userId);
            $availableSlots = MAX_BORROW_BOOKS - $currentBorrows;

            if ($availableSlots <= 0) {
                throw new Exception('ผู้ยืมถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว (' . MAX_BORROW_BOOKS . ' เล่ม)');
            }

            if (count($bookIds) > $availableSlots) {
                throw new Exception("ผู้ยืมสามารถยืมได้อีก {$availableSlots} เล่มเท่านั้น");
            }

            $borrowedBooks = [];
            $skippedBooks = [];

            foreach ($bookIds as $bookId) {
                $result = $this->borrowSingleBook($userId, $bookId, $borrowDate, $dueDate);
                
                if ($result['success']) {
                    $borrowedBooks[] = $result['title'];
                } else {
                    $skippedBooks[] = $result['reason'];
                }
            }

            $this->pdo->commit();

            return [
                'success' => count($borrowedBooks) > 0,
                'borrowed' => $borrowedBooks,
                'skipped' => $skippedBooks,
                'due_date' => $dueDate,
                'message' => $this->buildBorrowMessage($borrowedBooks, $skippedBooks, $dueDate)
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * คืนหนังสือ พร้อมคำนวณค่าปรับและบันทึกการชำระเงิน (ถ้ามี)
     * 
     * State Transition: borrowing → returned
     * 
     * @param int      $borrowId   ID รายการยืม (ต้องมี status = 'borrowing')
     * @param bool     $payNow     true = รับชำระค่าปรับทันที (สร้าง payment record)
     * @param int|null $recordedBy ID staff ที่บันทึก (ใช้สำหรับ payment.recorded_by)
     * 
     * @return array {
     *     success: bool,
     *     fine: {days: int, amount: float},  // ค่าปรับ (0 ถ้าไม่เกินกำหนด)
     *     paid: bool,                         // true ถ้ารับชำระแล้ว
     *     message: string
     * }
     * 
     * @throws Exception เมื่อ:
     *     - ไม่พบรายการยืม
     *     - รายการนี้คืนไปแล้ว (status ≠ 'borrowing')
     * 
     * @sideeffect
     *     - UPDATE `borrows`: status='returned', return_date, fine_amount
     *     - UPDATE `books.available` เพิ่มขึ้น 1
     *     - INSERT `payments` (ถ้า payNow && มีค่าปรับ)
     * 
     * @security ใช้ FOR UPDATE lock ป้องกันคืนซ้ำ (กดปุ่มคืน 2 ครั้ง)
     */
    public function returnBook(int $borrowId, bool $payNow = false, ?int $recordedBy = null): array
    {
        $this->pdo->beginTransaction();

        try {
            // Lock row - ป้องกันคืนซ้ำ
            $borrow = $this->borrowRepo->findByIdForUpdate($borrowId);

            if (!$borrow) {
                throw new Exception('ไม่พบรายการยืมหรือคืนหนังสือแล้ว');
            }

            // คำนวณค่าปรับตาม due_date
            $fine = $this->calculateFine($borrow['due_date'], date('Y-m-d'));

            // เปลี่ยนสถานะ + บันทึกค่าปรับ
            $this->borrowRepo->markAsReturned($borrowId, $fine['amount']);

            // คืน stock
            $this->bookRepo->incrementAvailable($borrow['book_id']);

            // บันทึก payment ถ้าจ่ายทันที
            if ($payNow && $fine['amount'] > 0) {
                $this->paymentRepo->create($borrowId, $fine['amount'], $recordedBy);
            }

            $this->pdo->commit();

            return [
                'success' => true,
                'fine' => $fine,
                'paid' => $payNow && $fine['amount'] > 0,
                'message' => $this->buildReturnMessage($fine, $payNow)
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * คำนวณค่าปรับจากวันเกินกำหนด
     * 
     * สูตรปัจจุบัน: จำนวนวันเกิน × FINE_PER_DAY (ค่าคงที่ต่อวัน)
     * ⭐ แก้ไขสูตรคำนวณค่าปรับที่ method นี้
     */
    public function calculateFine(string $dueDate, ?string $returnDate = null): array
    {
        $due = new \DateTime($dueDate);
        $returnDateStr = (!empty($returnDate)) ? $returnDate : date('Y-m-d');
        $return = new \DateTime($returnDateStr);

        // If return date is after due date (overdue)
        if ($return > $due) {
            $daysOverdue = $return->diff($due)->days;
            $fineAmount = $daysOverdue * FINE_PER_DAY;

            return ['days' => $daysOverdue, 'amount' => $fineAmount];
        }

        return ['days' => 0, 'amount' => 0];
    }

    /**
     * นับจำนวนหนังสือที่ผู้ใช้ยืมอยู่ (ยังไม่คืน)
     */
    public function countActiveBorrows(int $userId): int
    {
        return $this->borrowRepo->countActiveBorrows($userId);
    }

    /**
     * ตรวจสอบว่าผู้ใช้ยืมหนังสือเล่มนี้อยู่หรือไม่
     */
    public function isAlreadyBorrowing(int $userId, int $bookId): bool
    {
        return $this->borrowRepo->isAlreadyBorrowing($userId, $bookId);
    }

    /**
     * ดึงรายการยืมที่เกินกำหนดคืน (สำหรับ dashboard/notification)
     */
    public function getOverdueBorrows(int $limit = 10): array
    {
        return $this->borrowRepo->findOverdue($limit);
    }

    /**
     * ดึงรายการยืมล่าสุด (สำหรับ dashboard)
     */
    public function getRecentBorrows(int $limit = 5): array
    {
        return $this->borrowRepo->findRecent($limit);
    }

    // ==================== Private Methods ====================

    /**
     * ยืมหนังสือทีละเล่ม (internal - ใช้ภายใน transaction ของ createBorrow)
     */
    private function borrowSingleBook(int $userId, int $bookId, string $borrowDate, string $dueDate): array
    {
        // Lock book row
        $book = $this->bookRepo->findByIdForUpdate($bookId);

        if (!$book) {
            return ['success' => false, 'reason' => "หนังสือ ID: {$bookId} ไม่พบ"];
        }

        if ($book['available'] <= 0) {
            return ['success' => false, 'reason' => $book['title'] . ' (ไม่มีเล่มว่าง)'];
        }

        // Check if already borrowing this book
        if ($this->borrowRepo->isAlreadyBorrowing($userId, $bookId)) {
            return ['success' => false, 'reason' => $book['title'] . ' (ยืมอยู่แล้ว)'];
        }

        // Insert borrow record
        $this->borrowRepo->create([
            'user_id' => $userId,
            'book_id' => $bookId,
            'borrow_date' => $borrowDate,
            'due_date' => $dueDate
        ]);

        // Update book available count
        $this->bookRepo->decrementAvailable($bookId);

        return ['success' => true, 'title' => $book['title']];
    }

    /**
     * สร้างข้อความแจ้งผลการยืม
     */
    private function buildBorrowMessage(array $borrowed, array $skipped, string $dueDate): string
    {
        if (empty($borrowed)) {
            return 'ไม่สามารถยืมหนังสือได้: ' . implode(', ', $skipped);
        }

        $message = "บันทึกการยืมสำเร็จ " . count($borrowed) . " เล่ม";
        if (!empty($skipped)) {
            $message .= " (ข้าม: " . implode(', ', $skipped) . ")";
        }
        $message .= " | กำหนดคืน: " . date('d/m/Y', strtotime($dueDate));

        return $message;
    }

    /**
     * สร้างข้อความแจ้งผลการคืน
     */
    private function buildReturnMessage(array $fine, bool $paid): string
    {
        if ($fine['amount'] > 0) {
            $message = "บันทึกการคืนหนังสือสำเร็จ - ค่าปรับ: {$fine['amount']} บาท (เกิน {$fine['days']} วัน)";
            $message .= $paid ? " [รับชำระเงินแล้ว]" : " [ยังไม่จ่าย]";
            return $message;
        }

        return 'บันทึกการคืนหนังสือสำเร็จ';
    }
}
