<?php
/**
 * BorrowService - Business Logic สำหรับการยืม-คืนหนังสือ
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * Service นี้คือ "สมอง" ของระบบยืม-คืน จัดการ:
 * - สร้างรายการยืม (หัก stock + เช็คโควต้า)
 * - คืนหนังสือ (คืน stock + คำนวณค่าปรับ)
 * - รับชำระค่าปรับ
 *
 * 🏗️ สถาปัตยกรรม:
 * Controller (admin/borrow_form.php) → BorrowService → BorrowRepository
 *                                                     → BookRepository
 *                                                     → UserRepository
 *                                                     → PaymentRepository
 *
 * 📍 Entrypoints:
 * - admin/borrow_form.php → createBorrow()
 * - admin/borrows.php     → returnBook()
 * - admin/payments.php    → payFine()
 * - DashboardService      → getOverdueBorrows(), getRecentBorrows()
 *
 * �️ Security Design:
 * - createBorrow(): transaction + user lock + quota lock (FOR UPDATE)
 * - returnBook(): transaction + borrow lock (FOR UPDATE)
 * - payFine(): transaction + borrow lock (FOR UPDATE)
 *
 * ⚙️ ถ้าต้องการแก้กฎ:
 * - จำนวนวันยืม/เล่มสูงสุด → config.php (MAX_BORROW_BOOKS, DEFAULT_BORROW_DAYS)
 * - สูตรค่าปรับ           → calculateFine() ในไฟล์นี้
 *
 * @package App\Services
 */

namespace App\Services;

require_once __DIR__ . '/../Repositories/BookRepository.php';
require_once __DIR__ . '/../Repositories/BorrowRepository.php';
require_once __DIR__ . '/../Repositories/UserRepository.php';
require_once __DIR__ . '/../Repositories/PaymentRepository.php';
require_once __DIR__ . '/../Repositories/ReservationRepository.php';

use App\Repositories\BookRepository;
use App\Repositories\BorrowRepository;
use App\Repositories\UserRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\ReservationRepository;
use PDO;
use Exception;

class BorrowService
{
    // 🗄️ PDO connection — ใช้สำหรับ transaction (createBorrow, returnBook, payFine)
    private PDO $pdo;
    // 🗄️ Repositories — แต่ละตัวจัดการ table เฉพาะ
    private BookRepository $bookRepo;
    private BorrowRepository $borrowRepo;
    private UserRepository $userRepo;
    private PaymentRepository $paymentRepo;
    private ReservationRepository $reservationRepo;

    // 🏗️ Constructor: สร้าง repo ทั้งหมด — ใช้ PDO เดียวกัน
    //    สำคัญ! ถ้า PDO คนละ instance → transaction ข้าม repo ไม่ทำงาน
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->bookRepo = new BookRepository($pdo);
        $this->borrowRepo = new BorrowRepository($pdo);
        $this->userRepo = new UserRepository($pdo);
        $this->paymentRepo = new PaymentRepository($pdo);
        $this->reservationRepo = new ReservationRepository($pdo);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สร้างรายการยืม (รองรับหลายเล่มพร้อมกัน)
     * ==========================================================================
     *
     * 🔄 Flow:
     * 1. validate input
     * 2. BEGIN TX → lock user → check quota (FOR UPDATE)
     * 3. loop bookIds: lock book → check available → decrement → insert borrow
     * 4. COMMIT
     *
     * 📥 Input:
     * @param int      $userId     ID member
     * @param array    $bookIds    [book_id, ...]
     * @param int|null $borrowDays 1-30 (null = DEFAULT_BORROW_DAYS)
     *
     * 📤 Output:
     * @return array {success, borrowed[], skipped[], due_date, message}
     *
     * @throws Exception ถ้า user ไม่ใช่ member / bookIds ว่าง / เกินโควต้า
     *
     * 🛡️ Security:
     * - lockById() ล็อค user row ก่อน
     * - countActiveBorrowsForUpdate() ล็อค borrow rows
     * - decrementAvailable() มี WHERE available > 0
     *
     * ✅ Use case: admin/borrow_form.php POST
     */
    public function createBorrow(int $userId, array $bookIds, int $borrowDays = null): array
    {
        // 📝 ใช้ค่า default จาก config.php ถ้าไม่ระบุ
        //    ⚙️ แก้จำนวนวันยืม → config.php → DEFAULT_BORROW_DAYS
        $borrowDays = $borrowDays ?? DEFAULT_BORROW_DAYS;
        
        // 📝 Step 1: Validate input (ก่อนเปิด transaction)
        if ($userId <= 0) {
            throw new Exception('กรุณาเลือกผู้ยืม');
        }
        
        if (empty($bookIds)) {
            throw new Exception('กรุณาเลือกหนังสืออย่างน้อย 1 เล่ม');
        }
        
        if ($borrowDays < 1 || $borrowDays > 30) {
            throw new Exception('จำนวนวันยืมต้องอยู่ระหว่าง 1-30 วัน');
        }

        // 📝 Step 2: ตรวจว่า user เป็น member (ไม่ใช่ admin/staff)
        $user = $this->userRepo->findMemberById($userId);
        if (!$user) {
            throw new Exception('ไม่พบสมาชิกที่เลือก');
        }

        // 📝 Step 3: คำนวณวันยืม/คืน
        $borrowDate = date('Y-m-d');
        $dueDate = date('Y-m-d', strtotime("+{$borrowDays} days"));

        // 📝 Step 4: เปิด transaction
        $this->pdo->beginTransaction();

        try {
            // 🔒 Step 5: ล็อค User Row ก่อน — ป้องกัน race condition
            //    เช่น admin 2 คนกดยืมให้ member เดียวกันพร้อมกัน
            $this->userRepo->lockById($userId);

            // 📝 Step 6: ตรวจโควต้า (FOR UPDATE lock บน borrows)
            //    ⚙️ แก้เล่มสูงสุด → config.php → MAX_BORROW_BOOKS
            //    🛡️ นับ pending reservations ด้วย — เพราะจะกลายเป็น borrow เมื่อ approve
            //    ป้องกัน: admin สร้าง borrow จนเต็ม → approve reservation ไม่ได้
            $currentBorrows = $this->borrowRepo->countActiveBorrowsForUpdate($userId);
            $pendingReservations = $this->reservationRepo->countPendingByUser($userId);
            $availableSlots = MAX_BORROW_BOOKS - $currentBorrows - $pendingReservations;

            if ($availableSlots <= 0) {
                throw new Exception('ผู้ยืมถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว (' . MAX_BORROW_BOOKS . ' เล่ม)');
            }

            if (count($bookIds) > $availableSlots) {
                throw new Exception("ผู้ยืมสามารถยืมได้อีก {$availableSlots} เล่มเท่านั้น");
            }

            // 📝 Step 7: วน loop ยืมทีละเล่ม (ภายใน transaction เดียวกัน)
            $borrowedBooks = [];
            $skippedBooks = [];

            foreach ($bookIds as $bookId) {
                // 🔄 borrowSingleBook: lock book → check available → decrement → insert
                $result = $this->borrowSingleBook($userId, $bookId, $borrowDate, $dueDate);
                
                if ($result['success']) {
                    $borrowedBooks[] = $result['title'];
                } else {
                    $skippedBooks[] = $result['reason'];
                }
            }

            $this->pdo->commit();

            // 📤 คืนผลรวม: สำเร็จกี่เล่ม ข้ามกี่เล่ม กำหนดคืนเมื่อไหร่
            return [
                'success' => count($borrowedBooks) > 0,
                'borrowed' => $borrowedBooks,
                'skipped' => $skippedBooks,
                'due_date' => $dueDate,
                'message' => $this->buildBorrowMessage($borrowedBooks, $skippedBooks, $dueDate)
            ];

        } catch (Exception $e) {
            // ❌ rollback ทั้งหมด → stock ไม่ถูกหัก + ไม่มี borrow record
            $this->pdo->rollBack();
            error_log("[BorrowService::createBorrow] userId={$userId} error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: คืนหนังสือ + คำนวณค่าปรับ + บันทึก payment
     * ==========================================================================
     * State: borrowing → returned
     *
     * 🔄 Flow:
     * 1. BEGIN TX → lock borrow (FOR UPDATE + status='borrowing')
     * 2. calculateFine()
     * 3. markAsReturned() + incrementAvailable()
     * 4. ถ้า payNow + มีค่าปรับ → insert payment
     * 5. COMMIT
     *
     * 📥 Input:
     * @param int      $borrowId   Borrow ID (status='borrowing')
     * @param bool     $payNow     true = รับชำระทันที
     * @param int|null $recordedBy ID staff
     *
     * 📤 Output:
     * @return array {success, fine: {days, amount}, paid, message}
     *
     * 🛡️ Security: FOR UPDATE lock ป้องกันคืนซ้ำ
     * ✅ Use case: admin/borrows.php → ปุ่มคืนหนังสือ
     */
    public function returnBook(int $borrowId, bool $payNow = false, ?int $recordedBy = null): array
    {
        $this->pdo->beginTransaction();

        try {
            // 🔒 Step 1: ล็อคแถว borrow (FOR UPDATE + WHERE status='borrowing')
            //    ป้องกันคืนซ้ำ: ถ้าสถานะเป็น 'returned' แล้ว → query คืน null
            $borrow = $this->borrowRepo->findByIdForUpdate($borrowId);

            if (!$borrow) {
                throw new Exception('ไม่พบรายการยืมหรือคืนหนังสือแล้ว');
            }

            // 📝 Step 2: คำนวณค่าปรับ (วันเกิน × FINE_PER_DAY)
            $fine = $this->calculateFine($borrow['due_date'], date('Y-m-d'));

            // 📝 Step 3: 3 writes ใน 1 transaction (atomic)
            //    3a. เปลี่ยน status borrowing → returned + บันทึกค่าปรับ
            $this->borrowRepo->markAsReturned($borrowId, $fine['amount']);
            //    3b. คืน stock +1
            $this->bookRepo->incrementAvailable($borrow['book_id']);

            //    3c. บันทึก payment เฉพาะจ่ายทันที
            //    🛡️ UNIQUE บน borrow_id ป้องกันจ่ายซ้ำในระดับ DB
            if ($payNow && $fine['amount'] > 0) {
                $this->paymentRepo->create($borrowId, $fine['amount'], $recordedBy);
            }

            $this->pdo->commit();

            // 📤 คืนผล: สำเร็จ + ค่าปรับ + จ่ายแล้วหรือยัง
            return [
                'success' => true,
                'fine' => $fine,
                'paid' => $payNow && $fine['amount'] > 0,
                'message' => $this->buildReturnMessage($fine, $payNow)
            ];

        } catch (Exception $e) {
            // ❌ rollback → status ยังเป็น borrowing + stock ไม่ถูกคืน
            $this->pdo->rollBack();
            error_log("[BorrowService::returnBook] borrowId={$borrowId} error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: คำนวณค่าปรับ (days × FINE_PER_DAY)
     * ==========================================================================
     * ⭐ แก้สูตรค่าปรับที่ method นี้
     *
     * 📥 Input: @param string $dueDate, @param string|null $returnDate (null = วันนี้)
     * 📤 Output: @return array {days: int, amount: float}
     * ✅ Use case: returnBook(), admin/borrows.php (แสดง preview ค่าปรับ)
     */
    public function calculateFine(string $dueDate, ?string $returnDate = null): array
    {
        // 📝 แปลง string เป็น DateTime เพื่อเปรียบเทียบ
        $due = new \DateTime($dueDate);
        $returnDateStr = (!empty($returnDate)) ? $returnDate : date('Y-m-d');
        $return = new \DateTime($returnDateStr);

        // 📝 คืนเกินกำหนด → คิดค่าปรับ
        //    ⚙️ แก้สูตรค่าปรับ → แก้ตรงนี้ หรือ config.php → FINE_PER_DAY
        if ($return > $due) {
            $daysOverdue = $return->diff($due)->days;
            // 💰 สูตร: วันเกิน × ค่าปรับต่อวัน
            $fineAmount = $daysOverdue * FINE_PER_DAY;

            return ['days' => $daysOverdue, 'amount' => $fineAmount];
        }

        // 📤 คืนตรงเวลาหรือก่อนกำหนด → ไม่มีค่าปรับ
        return ['days' => 0, 'amount' => 0];
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับการยืม active ของ user (read-only)
     * ==========================================================================
     *
     * 📥 Input: @param int $userId
     * 📤 Output: @return int
     * ✅ Use case: UI แสดงจำนวนที่ยืมอยู่
     */
    public function countActiveBorrows(int $userId): int
    {
        // 📝 Pass-through (read-only, ไม่ lock) — สำหรับ UI แสดงจำนวน
        return $this->borrowRepo->countActiveBorrows($userId);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ตรวจว่ายืมเล่มนี้อยู่หรือไม่ (read-only)
     * ==========================================================================
     *
     * 📥 Input: @param int $userId, @param int $bookId
     * 📤 Output: @return bool
     * ✅ Use case: UI แสดงสถานะการยืม
     */
    public function isAlreadyBorrowing(int $userId, int $bookId): bool
    {
        // 📝 Pass-through (read-only) — ตรวจว่ายืมเล่มนี้อยู่หรือไม่
        return $this->borrowRepo->isAlreadyBorrowing($userId, $bookId);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการเกินกำหนด (pass-through)
     * ==========================================================================
     *
     * 📥 Input: @param int $limit
     * 📤 Output: @return array
     * ✅ Use case: DashboardService
     */
    public function getOverdueBorrows(int $limit = 10): array
    {
        // 📝 Pass-through → borrows ที่ due_date < today + status='borrowing'
        return $this->borrowRepo->findOverdue($limit);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการยืมล่าสุด (pass-through)
     * ==========================================================================
     *
     * 📥 Input: @param int $limit
     * 📤 Output: @return array
     * ✅ Use case: DashboardService
     */
    public function getRecentBorrows(int $limit = 5): array
    {
        // 📝 Pass-through → borrows ล่าสุด ORDER BY borrow_date DESC
        return $this->borrowRepo->findRecent($limit);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: รับชำระค่าปรับทีหลัง (คืนแล้วแต่ยังไม่จ่าย)
     * ==========================================================================
     *
     * 🔄 Flow:
     * 1. BEGIN TX → lock borrow (FOR UPDATE, any status)
     * 2. check fine > 0 + ยังไม่มี payment
     * 3. insert payment
     * 4. COMMIT
     *
     * 📥 Input: @param int $borrowId, @param int|null $recordedBy
     * 📤 Output: @return array {success, amount, message}
     *
     * 🛡️ Security: FOR UPDATE lock ป้องกันชำระซ้ำ
     * ✅ Use case: admin/payments.php → ปุ่มรับชำระ
     */
    public function payFine(int $borrowId, ?int $recordedBy = null): array
    {
        $this->pdo->beginTransaction();
        
        try {
            // 🔒 Step 1: ล็อคแถว borrow (FOR UPDATE, ทุก status)
            //    ใช้ AnyStatus เพราะต้องหา borrow ที่ returned แล้ว (ไม่ใช่ borrowing)
            //    ป้องกัน 2 คนกดชำระพร้อมกัน
            $borrow = $this->borrowRepo->findByIdForUpdateAnyStatus($borrowId);
            
            if (!$borrow) {
                throw new Exception('ไม่พบรายการยืม');
            }
            
            // 📝 Step 2: ตรวจว่ามีค่าปรับหรือไม่
            if ($borrow['fine_amount'] <= 0) {
                throw new Exception('รายการนี้ไม่มีค่าปรับ');
            }
            
            // 📝 Step 3: ตรวจว่าชำระแล้วหรือยัง (ภายใต้ lock)
            //    🛡️ UNIQUE constraint บน borrow_id เป็นด่านสุดท้าย
            $existingPayment = $this->paymentRepo->findByBorrowId($borrowId);
            if ($existingPayment) {
                throw new Exception('รายการนี้ชำระค่าปรับแล้ว');
            }
            
            // 📝 Step 4: บันทึก payment
            $this->paymentRepo->create($borrowId, $borrow['fine_amount'], $recordedBy);
            
            $this->pdo->commit();
            
            // 📤 คืนผล: สำเร็จ + จำนวนเงิน
            return [
                'success' => true,
                'amount' => $borrow['fine_amount'],
                'message' => 'รับชำระค่าปรับ ' . number_format($borrow['fine_amount']) . ' บาท เรียบร้อยแล้ว'
            ];
            
        } catch (Exception $e) {
            // ❌ rollback → ไม่มี payment record
            $this->pdo->rollBack();
            error_log("[BorrowService::payFine] borrowId={$borrowId} error: " . $e->getMessage());
            throw $e;
        }
    }

    // ==================== Private Methods ====================

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ยืมหนังสือทีละเล่ม (internal — เรียกใน TX ของ createBorrow)
     * ==========================================================================
     *
     * 🔄 Flow: lock book → check available → check duplicate → decrement → insert
     *
     * 📥 Input: @param int $userId, $bookId, string $borrowDate, $dueDate
     * 📤 Output: @return array {success: bool, title?: string, reason?: string}
     * ✅ Use case: createBorrow() loop ภายใน
     */
    private function borrowSingleBook(int $userId, int $bookId, string $borrowDate, string $dueDate): array
    {
        // 🔒 Lock book row (FOR UPDATE) — ป้องกัน 2 คนยืมเล่มสุดท้ายพร้อมกัน
        $book = $this->bookRepo->findByIdForUpdate($bookId);

        if (!$book) {
            return ['success' => false, 'reason' => "หนังสือ ID: {$bookId} ไม่พบ"];
        }

        // 📝 ตรวจ stock (ภายใต้ lock)
        if ($book['available'] <= 0) {
            return ['success' => false, 'reason' => $book['title'] . ' (ไม่มีเล่มว่าง)'];
        }

        // 🛡️ [DATA INTEGRITY] ตรวจยืมซ้ำภายใต้ lock
        //    ป้องกัน concurrent requests ยืมเล่มเดิม
        if ($this->borrowRepo->isAlreadyBorrowing($userId, $bookId)) {
            return ['success' => false, 'reason' => $book['title'] . ' (ยืมอยู่แล้ว)'];
        }

        // 🛡️ [DATA INTEGRITY] Atomic decrement (WHERE available > 0)
        //    ด่านสุดท้าย — แม้ข้างบนผ่าน DB ก็ยังป้องกัน stock ติดลบ
        if (!$this->bookRepo->decrementAvailable($bookId)) {
            return ['success' => false, 'reason' => $book['title'] . ' (stock หมดระหว่างดำเนินการ)'];
        }

        // 📝 INSERT borrow record
        $this->borrowRepo->create([
            'user_id' => $userId,
            'book_id' => $bookId,
            'borrow_date' => $borrowDate,
            'due_date' => $dueDate
        ]);

        return ['success' => true, 'title' => $book['title']];
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สร้างข้อความแจ้งผลการยืม (internal helper)
     * ==========================================================================
     */
    private function buildBorrowMessage(array $borrowed, array $skipped, string $dueDate): string
    {
        // 📝 สร้างข้อความแจ้งผล — แสดงเล่มที่สำเร็จ + เล่มที่ข้าม
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
     * ==========================================================================
     * 🎯 จุดประสงค์: สร้างข้อความแจ้งผลการคืน (internal helper)
     * ==========================================================================
     */
    private function buildReturnMessage(array $fine, bool $paid): string
    {
        // 📝 สร้างข้อความแจ้งผลคืน — แสดงค่าปรับ + สถานะชำระ
        if ($fine['amount'] > 0) {
            $message = "บันทึกการคืนหนังสือสำเร็จ - ค่าปรับ: {$fine['amount']} บาท (เกิน {$fine['days']} วัน)";
            $message .= $paid ? " [รับชำระเงินแล้ว]" : " [ยังไม่จ่าย]";
            return $message;
        }

        // 📝 คืนตรงเวลา → ไม่มีค่าปรับ
        return 'บันทึกการคืนหนังสือสำเร็จ';
    }
}
