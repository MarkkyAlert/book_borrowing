<?php
/**
 * BorrowService - Business Logic สำหรับการยืม-คืนหนังสือ
 * 
 * ไฟล์นี้รวม business logic ทั้งหมดที่เกี่ยวข้องกับการยืม-คืน
 * ลูกค้าที่ต้องการแก้ไขกฎการยืม ให้แก้ไขที่ไฟล์นี้
 * 
 * @package App\Services
 */

namespace App\Services;

use PDO;
use Exception;

class BorrowService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
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
        $user = $this->getValidMember($userId);
        if (!$user) {
            throw new Exception('ไม่พบสมาชิกที่เลือก');
        }

        $borrowDate = date('Y-m-d');
        $dueDate = date('Y-m-d', strtotime("+{$borrowDays} days"));

        $this->pdo->beginTransaction();

        try {
            // 🔒 Critical Fix: ล็อคแถวข้อมูลผู้ใช้งาน (User Row) ก่อนเป็นอันดับแรก เพื่อป้องกัน Race Condition
            // เพื่อให้แน่ใจว่าจะมี Transaction เดียวเท่านั้นที่ทำงานกับ User นี้ได้ในช่วงเวลานั้น
            // (ป้องกันกรณีเปิด 2 แท็บแล้วกดยืมพร้อมกันจนทะลุโควต้า)
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);

            // ตรวจสอบจำนวนหนังสือที่ยืมอยู่ปัจจุบัน
            $currentBorrows = $this->countActiveBorrows($userId);
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
        // [DB] Transaction - ต้อง atomic: update status + คืน stock + บันทึก payment
        $this->pdo->beginTransaction();

        try {
            // [CONCURRENCY] FOR UPDATE ล็อคแถว - ป้องกันคืนซ้ำ (กด 2 ครั้ง, multi-tab)
            // [STATE CHECK] ต้องเป็น 'borrowing' เท่านั้นถึงคืนได้
            $stmt = $this->pdo->prepare("SELECT * FROM borrows WHERE id = ? AND status = 'borrowing' FOR UPDATE");
            $stmt->execute([$borrowId]);
            $borrow = $stmt->fetch();

            if (!$borrow) {
                throw new Exception('ไม่พบรายการยืมหรือคืนหนังสือแล้ว');
            }

            // [BUSINESS RULE] คำนวณค่าปรับตาม due_date
            $fine = $this->calculateFine($borrow['due_date'], date('Y-m-d'));

            // [DB WRITE] เปลี่ยนสถานะ + บันทึกค่าปรับ
            $stmt = $this->pdo->prepare("
                UPDATE borrows 
                SET status = 'returned', return_date = CURDATE(), fine_amount = ? 
                WHERE id = ?
            ");
            $stmt->execute([$fine['amount'], $borrowId]);

            // [DB WRITE] คืน stock - สำคัญมาก! ต้องทำใน transaction เดียวกัน
            $stmt = $this->pdo->prepare("UPDATE books SET available = available + 1 WHERE id = ?");
            $stmt->execute([$borrow['book_id']]);

            // [DB WRITE] บันทึก payment ถ้าจ่ายทันที
            if ($payNow && $fine['amount'] > 0) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO payments (borrow_id, amount, recorded_by) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$borrowId, $fine['amount'], $recordedBy]);
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
     * 
     * @param string      $dueDate    วันกำหนดคืน (Y-m-d)
     * @param string|null $returnDate วันที่คืนจริง (null = วันนี้)
     * 
     * @return array {
     *     days: int,      // จำนวนวันที่เกิน (0 ถ้าไม่เกิน)
     *     amount: float   // ค่าปรับ (หน่วยบาท)
     * }
     * 
     * @example
     *     // เกิน 3 วัน, ค่าปรับวันละ 10 บาท
     *     $fine = $service->calculateFine('2024-01-01', '2024-01-04');
     *     // ['days' => 3, 'amount' => 30]
     */
    public function calculateFine(string $dueDate, ?string $returnDate = null): array
    {
        $due = new \DateTime($dueDate);
        $returnDateStr = (!empty($returnDate)) ? $returnDate : date('Y-m-d');
        $return = new \DateTime($returnDateStr);

        // If return date is after due date (overdue)
        if ($return > $due) {
            $daysOverdue = $return->diff($due)->days;
            
            // =====================================================
            // ⭐ สูตรคำนวณค่าปรับ - แก้ไขตรงนี้
            // =====================================================
            // ค่าปรับแบบคงที่ต่อวัน (default)
            $fineAmount = $daysOverdue * FINE_PER_DAY;
            
            // ตัวอย่างค่าปรับแบบขั้นบันได (uncomment เพื่อใช้):
            // if ($daysOverdue <= 3) {
            //     $fineAmount = $daysOverdue * 10;
            // } elseif ($daysOverdue <= 7) {
            //     $fineAmount = (3 * 10) + (($daysOverdue - 3) * 20);
            // } else {
            //     $fineAmount = (3 * 10) + (4 * 20) + (($daysOverdue - 7) * 30);
            // }
            
            // ตัวอย่างค่าปรับสูงสุด (cap):
            // $maxFine = 500;
            // $fineAmount = min($fineAmount, $maxFine);
            // =====================================================

            return ['days' => $daysOverdue, 'amount' => $fineAmount];
        }

        return ['days' => 0, 'amount' => 0];
    }

    /**
     * นับจำนวนหนังสือที่ผู้ใช้ยืมอยู่ (ยังไม่คืน)
     * 
     * @param int $userId ID ผู้ใช้
     * @return int จำนวนเล่มที่ยืมอยู่
     * 
     * @note ใช้ FOR UPDATE เพื่อ lock - ควรเรียกใน transaction เท่านั้น
     */
    public function countActiveBorrows(int $userId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM borrows 
            WHERE user_id = ? AND status = 'borrowing' 
            FOR UPDATE
        ");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ตรวจสอบว่าผู้ใช้ยืมหนังสือเล่มนี้อยู่หรือไม่
     * 
     * @param int $userId ID ผู้ใช้
     * @param int $bookId ID หนังสือ
     * @return bool true = กำลังยืมเล่มนี้อยู่ (ยังไม่คืน)
     * 
     * @usecase ป้องกันยืมหนังสือเล่มเดียวกันซ้ำ
     */
    public function isAlreadyBorrowing(int $userId, int $bookId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM borrows 
            WHERE user_id = ? AND book_id = ? AND status = 'borrowing'
        ");
        $stmt->execute([$userId, $bookId]);
        return $stmt->fetch() !== false;
    }

    /**
     * ดึงรายการยืมที่เกินกำหนดคืน (สำหรับ dashboard/notification)
     * 
     * @param int $limit จำนวนรายการสูงสุด (default: 10)
     * @return array รายการยืมที่เกินกำหนด เรียงจากเกินนานสุดก่อน
     *     แต่ละรายการมี: user_name, phone, book_title, due_date, ...
     */
    public function getOverdueBorrows(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.*, u.name as user_name, u.phone, bk.title as book_title
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            WHERE b.status = 'borrowing' AND b.due_date < CURDATE()
            ORDER BY b.due_date ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * ดึงรายการยืมล่าสุด (สำหรับ dashboard)
     * 
     * @param int $limit จำนวนรายการสูงสุด (default: 5)
     * @return array รายการยืมล่าสุด เรียงจากใหม่สุดก่อน
     *     แต่ละรายการมี: user_name, book_title, borrow_date, ...
     */
    public function getRecentBorrows(int $limit = 5): array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.*, u.name as user_name, bk.title as book_title
            FROM borrows b
            JOIN users u ON b.user_id = u.id
            JOIN books bk ON b.book_id = bk.id
            ORDER BY b.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    // ==================== Private Methods ====================

    /**
     * ยืมหนังสือทีละเล่ม (internal - ใช้ภายใน transaction ของ createBorrow)
     * 
     * @param int    $userId     ID ผู้ยืม
     * @param int    $bookId     ID หนังสือ
     * @param string $borrowDate วันที่ยืม (Y-m-d)
     * @param string $dueDate    วันกำหนดคืน (Y-m-d)
     * 
     * @return array {success: bool, title?: string, reason?: string}
     * 
     * @internal ต้องเรียกภายใน transaction เท่านั้น (มีการใช้ FOR UPDATE)
     */
    private function borrowSingleBook(int $userId, int $bookId, string $borrowDate, string $dueDate): array
    {
        // Lock book row
        $stmt = $this->pdo->prepare("SELECT id, title, available FROM books WHERE id = ? FOR UPDATE");
        $stmt->execute([$bookId]);
        $book = $stmt->fetch();

        if (!$book) {
            return ['success' => false, 'reason' => "หนังสือ ID: {$bookId} ไม่พบ"];
        }

        if ($book['available'] <= 0) {
            return ['success' => false, 'reason' => $book['title'] . ' (ไม่มีเล่มว่าง)'];
        }

        // Check if already borrowing this book
        if ($this->isAlreadyBorrowing($userId, $bookId)) {
            return ['success' => false, 'reason' => $book['title'] . ' (ยืมอยู่แล้ว)'];
        }

        // Insert borrow record
        $stmt = $this->pdo->prepare("
            INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status)
            VALUES (?, ?, ?, ?, 'borrowing')
        ");
        $stmt->execute([$userId, $bookId, $borrowDate, $dueDate]);

        // Update book available count
        $stmt = $this->pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?");
        $stmt->execute([$bookId]);

        return ['success' => true, 'title' => $book['title']];
    }

    /**
     * ตรวจสอบว่า user เป็น member ที่ถูกต้อง
     */
    private function getValidMember(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'member'");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
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
