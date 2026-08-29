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
// 🔄 ใช้ตอนเลื่อนคิวหลังคืนหนังสือ — ต้องประกาศไว้ตรงนี้ ไม่พึ่ง autoloader อย่างเดียว
//    เพราะไฟล์นี้ถูก require ตรง ๆ จากชุดทดสอบและสคริปต์ CLI ที่ไม่ได้โหลด bootstrap.php
require_once __DIR__ . '/ReservationService.php';

use App\Repositories\BookRepository;
use App\Repositories\BorrowRepository;
use App\Repositories\UserRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\ReservationRepository;
use App\Services\ReservationService;
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

    // 🔄 สร้างตอนใช้จริงเท่านั้น (lazy) — ไม่สร้างใน constructor เพราะ ReservationService
    //    ก็สร้าง repo ชุดเดียวกันอีกรอบ การยืม/คืนส่วนใหญ่ไม่ต้องแตะคิวเลย
    private ?ReservationService $reservationService = null;

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
     * 🔄 ReservationService แบบสร้างเมื่อใช้ — ใช้ PDO ตัวเดียวกัน
     *    จึงอยู่ใน transaction เดียวกับการคืนหนังสือได้ (สำคัญมากสำหรับการเลื่อนคิว)
     */
    private function reservations(): ReservationService
    {
        if ($this->reservationService === null) {
            $this->reservationService = new ReservationService($this->pdo);
        }
        return $this->reservationService;
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
     * @return array {success: true, borrowed[], skipped: [], due_date, message}
     *              (skipped จะว่างเสมอ เพราะใช้ atomic — ถ้าเล่มใดพัง throw Exception)
     *
     * @throws Exception ถ้า user ไม่ใช่ member / bookIds ว่าง / เกินโควต้า / หนังสือเล่มใดยืมไม่ได้ (atomic rollback)
     *
     * 🛡️ Security:
     * - lockById() ล็อค user row ก่อน
     * - countActiveBorrowsForUpdate() ล็อค borrow rows
     * - decrementAvailable() มี WHERE available > 0
     *
     * ✅ Use case: admin/borrow_form.php POST
     */
    public function createBorrow(int $userId, array $bookIds, ?int $borrowDays = null): array
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

        // 📝 Step 2: ตรวจว่า user เป็น member/staff (ไม่ใช่ admin)
        $user = $this->userRepo->findMemberById($userId);
        if (!$user) {
            throw new Exception('ไม่พบสมาชิกที่เลือก');
        }

        // 📝 Step 3: คำนวณวันยืม/คืน
        $borrowDate = date('Y-m-d');
        $dueDate = date('Y-m-d', strtotime("+{$borrowDays} days"));

        // 📝 Step 4: เปิด transaction
        // 🔁 ครอบด้วย retry — deadlock ของ InnoDB ให้ลองใหม่อัตโนมัติ (ดู FINDINGS F-20)
        //    ปลอดภัยเพราะ closure นี้เปิด/ปิด transaction เองครบ และไม่มี side effect นอก transaction
        return runWithDeadlockRetry($this->pdo, function () use ($userId, $bookIds, $borrowDate, $dueDate) {
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
                    // 🧠 [F-41] บอกที่มาของตัวเลขด้วย ไม่งั้นเจ้าหน้าที่อธิบายให้สมาชิกไม่ได้
                    //    สมาชิกถือหนังสือมา 2 เล่มแต่ระบบบอกว่าเต็ม 3 → ต้องรู้ว่าเล่มที่ 3 คือการจอง
                    throw new Exception($this->buildQuotaFullMessage('ผู้ยืม', $currentBorrows, $pendingReservations));
                }

                if (count($bookIds) > $availableSlots) {
                    throw new Exception("ผู้ยืมสามารถยืมได้อีก {$availableSlots} เล่มเท่านั้น");
                }

                // 📝 Step 7: วน loop ยืมทีละเล่ม (ภายใน transaction เดียวกัน)
                //    🛡️ [ATOMIC] ถ้าเล่มใดยืมไม่ได้ → throw Exception → rollback ทั้งหมด
                $borrowedBooks = [];

                foreach ($bookIds as $bookId) {
                    // 🔄 borrowSingleBook: lock book → check available → decrement → insert
                    $result = $this->borrowSingleBook($userId, $bookId, $borrowDate, $dueDate);

                    if ($result['success']) {
                        $borrowedBooks[] = $result['title'];
                    } else {
                        // 🛡️ [ATOMIC] เล่มใดพัง → rollback ทั้งหมด (ไม่ใช่ skip)
                        throw new Exception('ไม่สามารถยืมได้: ' . $result['reason']);
                    }
                }

                $this->pdo->commit();

                // 📤 คืนผลรวม: สำเร็จทุกเล่ม (atomic — ไม่มี skipped)
                return [
                    'success' => true,
                    'borrowed' => $borrowedBooks,
                    'skipped' => [],
                    'due_date' => $dueDate,
                    'message' => $this->buildBorrowMessage($borrowedBooks, [], $dueDate)
                ];
            } catch (Exception $e) {
                // ❌ rollback ทั้งหมด → stock ไม่ถูกหัก + ไม่มี borrow record
                $this->pdo->rollBack();
                error_log("[BorrowService::createBorrow] userId={$userId} error: " . $e->getMessage());
                throw $e;
            }
        }, 'BorrowService::createBorrow');
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
        // 🔁 ครอบด้วย retry — deadlock ของ InnoDB ให้ลองใหม่อัตโนมัติ (ดู FINDINGS F-20)
        //    ปลอดภัยเพราะ closure นี้เปิด/ปิด transaction เองครบ และไม่มี side effect นอก transaction
        return runWithDeadlockRetry($this->pdo, function () use ($borrowId, $payNow, $recordedBy) {
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

                //    3b-2. 🔄 มีคนต่อคิวรอเล่มนี้ไหม — ถ้ามี กันเล่มไว้ให้ทันที
                //    🔴 ต้องอยู่ใน transaction เดียวกับการคืน ห้ามแยกออกไปทำทีหลัง
                //       ไม่งั้นจะเกิดช่วงที่ available = 1 แล้วคนที่ไม่ได้อยู่ในคิว
                //       ชิงยืมไปก่อนคนที่รอมาเป็นเดือน
                //    promoteNextInQueue() จะหัก available กลับลงไปเองถ้าเลื่อนคิวสำเร็จ
                //    → สุทธิแล้ว available เท่าเดิม แต่เล่มถูกกันไว้ให้คนในคิวแทน
                $promoted = $this->reservations()->promoteNextInQueue((int) $borrow['book_id']);

                //    3c. บันทึก payment เฉพาะจ่ายทันที
                //    🛡️ UNIQUE บน borrow_id ป้องกันจ่ายซ้ำในระดับ DB
                if ($payNow && $fine['amount'] > 0) {
                    $this->paymentRepo->create($borrowId, $fine['amount'], $recordedBy);
                }

                $this->pdo->commit();

                // 📤 คืนผล: สำเร็จ + ค่าปรับ + จ่ายแล้วหรือยัง
                $message = $this->buildReturnMessage($fine, $payNow);
                if ($promoted !== null) {
                    // 📣 เจ้าหน้าที่ต้องรู้ทันทีว่าเล่มนี้ไม่ได้กลับขึ้นชั้น แต่ถูกกันไว้ให้คนในคิว
                    $message .= sprintf(
                        ' | 🔄 มีคนต่อคิวรอเล่มนี้ — กันเล่มไว้ให้แล้ว ให้มารับภายในวันที่ %s',
                        date('d/m/Y', strtotime($promoted['expires_at']))
                    );
                }

                return [
                    'success' => true,
                    'fine' => $fine,
                    'paid' => $payNow && $fine['amount'] > 0,
                    'promoted' => $promoted,
                    'message' => $message
                ];
            } catch (Exception $e) {
                // ❌ rollback → status ยังเป็น borrowing + stock ไม่ถูกคืน
                $this->pdo->rollBack();
                error_log("[BorrowService::returnBook] borrowId={$borrowId} error: " . $e->getMessage());
                throw $e;
            }
        }, 'BorrowService::returnBook');
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ข้อความ "เต็มโควตา" ที่บอกที่มาของตัวเลข — F-41
     * ==========================================================================
     *
     * 🧠 เดิมบอกแค่ "ถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว (3 เล่ม)"
     *    สมาชิกถือหนังสือมาแค่ 2 เล่ม เจ้าหน้าที่จึงอธิบายไม่ได้ว่าเล่มที่ 3 หายไปไหน
     *    (คำตอบคือมันคือการจองที่รอมารับอยู่ ซึ่งกินโควตาเหมือนกัน)
     *
     * 📥 Input: @param string $who 'ผู้ยืม' หรือ 'ผู้จอง', @param int $borrows, @param int $pending
     * 📤 Output: @return string ข้อความพร้อมแสดง
     *
     * ⚠️ ห้ามเอาคิวรอ (waiting) มารวม — ไม่กินโควตายืม
     */
    private function buildQuotaFullMessage(string $who, int $borrows, int $pending): string
    {
        $detail = $pending > 0
            ? sprintf('ยืมอยู่ %d เล่ม + จองรอรับอีก %d เล่ม', $borrows, $pending)
            : sprintf('ยืมอยู่ %d เล่ม', $borrows);

        return sprintf(
            '%sเต็มโควตาแล้ว — %s = %d จาก %d เล่ม%s',
            $who,
            $detail,
            $borrows + $pending,
            MAX_BORROW_BOOKS,
            $pending > 0 ? ' (การจองที่รอมารับนับรวมในโควตาด้วย)' : ''
        );
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
        // 🔁 ครอบด้วย retry — deadlock ของ InnoDB ให้ลองใหม่อัตโนมัติ (ดู FINDINGS F-20)
        //    ปลอดภัยเพราะ closure นี้เปิด/ปิด transaction เองครบ และไม่มี side effect นอก transaction
        return runWithDeadlockRetry($this->pdo, function () use ($borrowId, $recordedBy) {
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

                // 💸 Step 2.5: ยกเว้นไปแล้วต้องรับชำระไม่ได้ (ภายใต้ lock)
                //    ไม่งั้นจะเก็บเงินจากรายการที่ประกาศยกเว้นไปแล้ว
                if (!empty($borrow['fine_waived_at'])) {
                    throw new Exception('รายการนี้ถูกยกเว้นค่าปรับไปแล้ว');
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
        }, 'BorrowService::payFine');
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: แจ้งหนังสือหาย / ชำรุดจนใช้ไม่ได้ + คิดค่าชดใช้
     * ==========================================================================
     * State: borrowing → lost | damaged
     *
     * 🔄 Flow:
     * 1. ตรวจ input ก่อนเปิด transaction
     * 2. BEGIN TX → lock borrow (FOR UPDATE + status='borrowing')
     * 3. คิดค่าชดใช้ = ราคาที่ระบุ + ค่าดำเนินการ  (**ไม่คิดค่าปรับเกินกำหนดซ้ำ**)
     * 4. markAsLost() + decrementQuantityForLoss()
     * 5. COMMIT
     *
     * 📥 Input:
     * @param int        $borrowId   รายการยืมที่ยัง borrowing อยู่
     * @param string     $type       'lost' = หาย | 'damaged' = ชำรุดจนใช้ไม่ได้
     * @param float|null $price      ราคาหนังสือที่ใช้คิดค่าชดใช้
     *                               null = ใช้ books.price · ถ้า books.price ก็ null → error
     * @param string     $note       รายละเอียด/เหตุผล (บังคับ)
     * @param int|null   $reportedBy ผู้แจ้ง (users.id)
     *
     * 📤 Output: @return array {success, status, charge, message}
     *
     * 🧠 **ทำไมไม่คิดค่าปรับเกินกำหนดซ้ำ** (กติกาที่ตกลงไว้ใน ROADMAP)
     *    หนังสือ 200 บาทที่หายไป 60 วันจะกลายเป็นหนี้ 800 บาท ซึ่งไม่มีใครจ่าย
     *    และห้องสมุดจริงไม่คิดแบบนั้น — ค่าชดใช้ "แทนที่" ค่าปรับ ไม่ใช่บวกเพิ่ม
     *
     * 🔴 **ห้ามคิดค่าชดใช้เป็น 0 เงียบ ๆ** ถ้าไม่รู้ราคา
     *    หนังสือทุกเล่มในระบบเดิม price = NULL → ถ้าปล่อยผ่านเป็น 0
     *    จะกลายเป็น "ทำหายแล้วไม่ต้องจ่าย" ต้องบังคับให้คนกรอกราคา
     *
     * 🛡️ Race: FOR UPDATE lock + WHERE status='borrowing' ใน UPDATE
     *    ยิงพร้อมกัน 2 ครั้ง → ลด quantity ครั้งเดียว
     *
     * ✅ Use case: admin/borrows.php → ปุ่ม "แจ้งหาย/ชำรุด"
     */
    public function markAsLost(
        int $borrowId,
        string $type,
        ?float $price,
        string $note,
        ?int $reportedBy = null
    ): array {
        // 📝 ตรวจ input ก่อนเปิด transaction — ไม่ต้องไปล็อคแถวถ้ายังไงก็ไม่ผ่าน
        if (!in_array($type, ['lost', 'damaged'], true)) {
            throw new Exception('ประเภทไม่ถูกต้อง — ต้องเป็น "หาย" หรือ "ชำรุด" เท่านั้น');
        }

        $note = trim($note);
        if ($note === '') {
            throw new Exception('กรุณากรอกรายละเอียด — เป็นเรื่องเงิน ต้องมีร่องรอยว่าทำไมถึงคิดเงิน');
        }
        if (mb_strlen($note) > 255) {
            throw new Exception('รายละเอียดต้องไม่เกิน 255 ตัวอักษร');
        }
        if ($price !== null && $price < 0) {
            throw new Exception('ราคาหนังสือติดลบไม่ได้');
        }

        return runWithDeadlockRetry($this->pdo, function () use ($borrowId, $type, $price, $note, $reportedBy) {
            $this->pdo->beginTransaction();

            try {
                // 🔒 Step 1: ล็อคแถว borrow (FOR UPDATE + WHERE status='borrowing')
                //    ถ้าคืนไปแล้ว หรือแจ้งหายไปแล้ว → คืน null
                $borrow = $this->borrowRepo->findByIdForUpdate($borrowId);

                if (!$borrow) {
                    throw new Exception('ไม่พบรายการยืม — อาจคืนไปแล้ว หรือแจ้งหายไปแล้ว');
                }

                // 📝 Step 2: หาราคาที่จะใช้คิด
                //    ลำดับ: ราคาที่เจ้าหน้าที่กรอกตอนแจ้ง → books.price
                $book = $this->bookRepo->findById((int) $borrow['book_id']);
                $bookPrice = ($book && $book['price'] !== null) ? (float) $book['price'] : null;
                $usePrice  = $price ?? $bookPrice;

                if ($usePrice === null) {
                    // 🔴 ไม่รู้ราคา = หยุด ห้ามคิด 0
                    throw new Exception(
                        'หนังสือเล่มนี้ยังไม่ได้ระบุราคาปก — กรุณากรอกราคาที่ใช้คิดค่าชดใช้ '
                        . '(หรือไปใส่ราคาปกในหน้าแก้ไขหนังสือก่อน)'
                    );
                }

                // 💰 Step 3: ค่าชดใช้ = ราคาหนังสือ + ค่าดำเนินการ
                //    ⚙️ ค่าดำเนินการแก้ได้ที่หน้า "ตั้งค่าระบบ" (LOST_BOOK_FEE, ค่าเริ่มต้น 0)
                $charge = round($usePrice + (float) LOST_BOOK_FEE, 2);

                // 📝 Step 4: 2 writes ใน 1 transaction (atomic)
                //    4a. ปิดรายการยืม + snapshot ค่าชดใช้ลง fine_amount
                $ok = $this->borrowRepo->markAsLost($borrowId, $type, $charge, $reportedBy, $note);
                if (!$ok) {
                    // 🛡️ มีคนแจ้งไปแล้วระหว่างที่เรากำลังทำ → ห้ามลด quantity ซ้ำ
                    throw new Exception('รายการนี้ถูกแจ้งไปแล้ว');
                }

                //    4b. ลด quantity ลง 1 (ไม่แตะ available — ดูเหตุผลที่ Repository)
                if (!$this->bookRepo->decrementQuantityForLoss((int) $borrow['book_id'])) {
                    throw new Exception('ลดจำนวนหนังสือไม่สำเร็จ — ยกเลิกการแจ้งทั้งหมด');
                }

                $this->pdo->commit();

                $typeLabel = $type === 'lost' ? 'หาย' : 'ชำรุด';
                $feeNote   = LOST_BOOK_FEE > 0
                    ? sprintf(' (ราคาหนังสือ %s + ค่าดำเนินการ %s)', number_format($usePrice, 2), number_format((float) LOST_BOOK_FEE, 2))
                    : '';

                return [
                    'success' => true,
                    'status'  => $type,
                    'charge'  => $charge,
                    'message' => sprintf(
                        'บันทึกหนังสือ%s แล้ว | ค่าชดใช้ %s บาท%s | จำนวนหนังสือในระบบลดลง 1 เล่ม',
                        $typeLabel,
                        number_format($charge, 2),
                        $feeNote
                    ),
                ];
            } catch (Exception $e) {
                // ❌ rollback → status ยังเป็น borrowing + quantity ไม่ถูกลด
                $this->pdo->rollBack();
                error_log("[BorrowService::markAsLost] borrowId={$borrowId} error: " . $e->getMessage());
                throw $e;
            }
        }, 'BorrowService::markAsLost');
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ย้อนการแจ้งหาย/ชำรุด — หาหนังสือเจอทีหลัง
     * ==========================================================================
     * State: lost | damaged → returned
     *
     * 📥 Input:
     * @param int      $borrowId
     * @param string   $note      เหตุผลที่ย้อน (บังคับ)
     * @param int|null $undoneBy  ผู้ย้อน (users.id)
     *
     * 📤 Output: @return array {success, refundNeeded, paidAmount, message}
     *
     * 🧠 หนังสือหาแล้วเจอเป็นเรื่องปกติของห้องสมุด และกล่องยืนยันหลายอันในระบบนี้
     *    ไม่บอกว่ากำลังทำอะไรกับใคร (F-47) — กดผิดแถวแล้วย้อนไม่ได้
     *    จะเสียทั้งสต็อกและเก็บเงินผิดคน จึงต้องย้อนได้ แต่ต้องเหลือร่องรอย
     *
     * 💰 เรื่องเงิน:
     *    - ยังไม่ได้จ่าย → ล้างค่าชดใช้ทิ้ง (fine_amount = 0)
     *    - จ่ายไปแล้ว   → **ไม่แตะเงิน** เก็บ payment ไว้เหมือนเดิม แล้วบอกให้คนคืนเงินเอง
     *      (ระบบไม่มีระบบคืนเงิน จะลบ payment ทิ้งเงียบ ๆ ไม่ได้ รายงานรายได้จะเพี้ยน)
     *
     * ⚠️ ตั้ง return_date = วันนี้ ตรงนี้ถูกต้อง เพราะหนังสือกลับเข้าชั้นจริง
     *    (ต่างจากตอนแจ้งหายที่ห้ามตั้ง — ดูหมายเหตุใน BorrowRepository::markAsLost)
     *
     * ✅ Use case: admin/borrows.php → ปุ่ม "ย้อนการแจ้ง"
     */
    public function undoLost(int $borrowId, string $note, ?int $undoneBy = null): array
    {
        $note = trim($note);
        if ($note === '') {
            throw new Exception('กรุณากรอกเหตุผลที่ย้อนการแจ้ง');
        }
        if (mb_strlen($note) > 200) {
            throw new Exception('เหตุผลต้องไม่เกิน 200 ตัวอักษร');
        }

        return runWithDeadlockRetry($this->pdo, function () use ($borrowId, $note, $undoneBy) {
            $this->pdo->beginTransaction();

            try {
                // 🔒 ล็อคเฉพาะแถวที่แจ้งหาย/ชำรุดไว้จริง — กันย้อนซ้ำ (quantity จะเกิน)
                $borrow = $this->borrowRepo->findLostByIdForUpdate($borrowId);

                if (!$borrow) {
                    throw new Exception('ไม่พบรายการที่แจ้งหาย/ชำรุดไว้ — อาจถูกย้อนไปแล้ว');
                }

                // 💰 เช็คว่าจ่ายค่าชดใช้ไปหรือยัง
                $paid       = $this->paymentRepo->findByBorrowId($borrowId);
                $paidAmount = $paid ? (float) $paid['amount'] : 0.0;

                // 📝 ยังไม่จ่าย → ล้างหนี้ทิ้ง · จ่ายแล้ว → คงยอดเดิมไว้คู่กับ payment
                $newFine = $paid ? (float) $borrow['fine_amount'] : 0.0;

                $trail = sprintf(
                    '[ย้อนการแจ้ง %s โดย #%s] %s',
                    date('Y-m-d H:i'),
                    $undoneBy ?? '-',
                    $note
                );

                if (!$this->borrowRepo->undoLost($borrowId, $newFine, $trail)) {
                    throw new Exception('ย้อนการแจ้งไม่สำเร็จ — อาจมีคนย้อนไปแล้ว');
                }

                // 📚 คืนหนังสือเข้าระบบ: quantity +1 และ available +1
                //    invariant: รายการนี้เป็น 'returned' แล้ว ไม่ถูกนับเป็นกำลังยืม
                //    available = quantity − ยืม − จอง → ต้องขึ้นทั้งคู่
                $this->bookRepo->addQuantity((int) $borrow['book_id'], 1);

                // 🔄 หนังสือกลับเข้าระบบแล้ว — ถ้ามีคนต่อคิวรอ ต้องกันเล่มให้เหมือนตอนคืน
                //    ถ้าลืมตรงนี้ เล่มที่หาแล้วเจอจะขึ้นชั้นให้คนอื่นยืมแซงคนที่รอคิวอยู่
                $promotedUndo = $this->reservations()->promoteNextInQueue((int) $borrow['book_id']);

                $this->pdo->commit();

                $msg = 'ย้อนการแจ้งแล้ว | หนังสือกลับเข้าระบบ 1 เล่ม';
                if ($promotedUndo !== null) {
                    $msg .= sprintf(
                        ' | 🔄 กันเล่มไว้ให้คนที่ต่อคิวรอ ให้มารับภายในวันที่ %s',
                        date('d/m/Y', strtotime($promotedUndo['expires_at']))
                    );
                }
                if ($paidAmount > 0) {
                    $msg .= sprintf(
                        ' | ⚠️ ชำระค่าชดใช้ไปแล้ว %s บาท ระบบไม่คืนเงินให้อัตโนมัติ — ต้องคืนเงินเอง',
                        number_format($paidAmount, 2)
                    );
                } else {
                    $msg .= ' | ยกเลิกค่าชดใช้ที่ค้างอยู่';
                }

                return [
                    'success'      => true,
                    'refundNeeded' => $paidAmount > 0,
                    'paidAmount'   => $paidAmount,
                    'message'      => $msg,
                ];
            } catch (Exception $e) {
                $this->pdo->rollBack();
                error_log("[BorrowService::undoLost] borrowId={$borrowId} error: " . $e->getMessage());
                throw $e;
            }
        }, 'BorrowService::undoLost');
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ต่ออายุการยืม — เลื่อนกำหนดคืนออกไปอีกรอบ
     * ==========================================================================
     * State: borrowing (ยังไม่เกินกำหนด) → borrowing (due_date ใหม่)
     *
     * 🔄 Flow:
     * 1. BEGIN TX → lock borrow (FOR UPDATE, status='borrowing')
     * 2. ตรวจ 3 ข้อ: ยังไม่เกินกำหนด · ยังไม่เกินโควตาต่ออายุ · ไม่มีคนจองรออยู่
     * 3. UPDATE due_date + renew_count → COMMIT
     *
     * 📥 Input:
     * @param int $borrowId รายการยืม
     * @param int|null $days จำนวนวันที่ต่อ (null = DEFAULT_BORROW_DAYS)
     *
     * 📤 Output: @return array {success, due_date, renew_count, message}
     * @throws Exception ถ้าคืนแล้ว / เลยกำหนด / เต็มโควตา / มีคนจองรอ
     *
     * 🧠 เลื่อนจาก **กำหนดคืนเดิม** ไม่ใช่จากวันนี้
     *    ต่อวันไหนก็ได้เท่ากัน = "ได้เพิ่มอีก 7 วันจากกำหนดเดิม" อธิบายง่ายและไม่ลงโทษคนมาต่อเร็ว
     *
     * 🔴 ทำไมห้ามต่อเมื่อเลยกำหนดแล้ว — เป็นข้อจำกัดทางเทคนิค ไม่ใช่แค่นโยบาย:
     *    ระบบนี้ไม่เก็บค่าปรับของรายการที่ยังไม่คืน (fine_amount = 0 จนกว่าจะคืน)
     *    ค่าปรับคำนวณสดจาก due_date ตอนคืน → เลื่อน due_date = **ลบค่าปรับที่เกิดไปแล้วทิ้ง**
     *    กลายเป็นช่องหนีค่าปรับที่เจ้าหน้าที่กดให้เองได้ (ดู ROADMAP ข้อ 3)
     *
     * 🔴 ทำไมห้ามต่อเมื่อมีคนจองรออยู่:
     *    ถ้าปล่อยให้ต่อได้ คนที่จองไว้จะไม่มีวันได้หนังสือ — เป็นกติกามาตรฐานห้องสมุด
     *
     * ✅ Use case: admin/borrows.php → ปุ่ม "ต่ออายุ"
     */
    public function renewBorrow(int $borrowId, ?int $days = null): array
    {
        $days = $days ?? DEFAULT_BORROW_DAYS;

        // 📝 ปิดการต่ออายุทั้งระบบได้ด้วยการตั้งค่าเป็น 0 (หน้าตั้งค่าระบบ)
        if (MAX_RENEW_COUNT < 1) {
            throw new Exception('ระบบปิดการต่ออายุการยืมไว้');
        }

        return runWithDeadlockRetry($this->pdo, function () use ($borrowId, $days) {
            $this->pdo->beginTransaction();

            try {
                // 🔒 Step 1: lock แถว borrow (กรอง status='borrowing' อยู่แล้ว)
                $borrow = $this->borrowRepo->findByIdForUpdate($borrowId);

                if (!$borrow) {
                    throw new Exception('ไม่พบรายการยืม หรือรายการนี้คืนไปแล้ว');
                }

                // 📅 Step 2: ต้องยังไม่เกินกำหนด (วันครบกำหนดพอดียังต่อได้)
                if ($borrow['due_date'] < date('Y-m-d')) {
                    $overdueDays = (int) ((strtotime('today') - strtotime($borrow['due_date'])) / 86400);
                    throw new Exception(sprintf(
                        'เลยกำหนดคืนมาแล้ว %d วัน ต่ออายุไม่ได้ — ต้องคืนก่อนแล้วยืมใหม่',
                        $overdueDays
                    ));
                }

                // 🔢 Step 3: โควตาการต่ออายุ
                $renewCount = (int) ($borrow['renew_count'] ?? 0);
                if ($renewCount >= MAX_RENEW_COUNT) {
                    throw new Exception(sprintf(
                        'ต่ออายุครบจำนวนที่กำหนดแล้ว (%d ครั้ง) — ต้องคืนก่อนแล้วยืมใหม่',
                        MAX_RENEW_COUNT
                    ));
                }

                // 🔖 Step 4: มีคนจองเล่มนี้รออยู่หรือไม่ (ภายใต้ transaction เดียวกัน)
                //    ถ้ามี ต้องให้คิวได้หนังสือ ไม่ใช่ให้คนเดิมถือต่อ
                // 🧠 นับคิวรอด้วย — คนที่ต่อคิวรออยู่ก็คือคนที่รอเล่มนี้เหมือนกัน
                //    ถ้าไม่นับ คนยืมจะต่ออายุไปเรื่อย ๆ ส่วนคนที่รอคิวไม่มีวันได้
                if ($this->reservationRepo->countActiveByBook((int) $borrow['book_id']) > 0) {
                    throw new Exception('มีสมาชิกจองหนังสือเล่มนี้รออยู่ ต่ออายุไม่ได้');
                }

                // 📝 Step 5: เลื่อนกำหนดคืนจากกำหนดเดิม
                $newDue = date('Y-m-d', strtotime($borrow['due_date'] . " +{$days} days"));

                if (!$this->borrowRepo->renewBorrow($borrowId, $newDue, MAX_RENEW_COUNT)) {
                    // 📌 มาถึงตรงนี้แปลว่ามีคนต่ออายุตัดหน้าไปแล้วระหว่าง lock
                    throw new Exception('ต่ออายุไม่สำเร็จ — รายการนี้อาจถูกต่ออายุหรือคืนไปแล้ว');
                }

                $this->pdo->commit();

                return [
                    'success' => true,
                    'due_date' => $newDue,
                    'renew_count' => $renewCount + 1,
                    'message' => sprintf(
                        'ต่ออายุสำเร็จ | กำหนดคืนใหม่: %s (ต่อครั้งที่ %d จาก %d)',
                        date('d/m/Y', strtotime($newDue)),
                        $renewCount + 1,
                        MAX_RENEW_COUNT
                    ),
                ];
            } catch (Exception $e) {
                $this->pdo->rollBack();
                error_log("[BorrowService::renewBorrow] borrowId={$borrowId} error: " . $e->getMessage());
                throw $e;
            }
        }, 'BorrowService::renewBorrow');
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ยกเว้นค่าปรับ (ไม่เก็บเงิน แต่ไม่นับเป็นค้างชำระอีก)
     * ==========================================================================
     * State: fine ค้างชำระ → ยกเว้นแล้ว
     *
     * 🔄 Flow:
     * 1. BEGIN TX → lock borrow (FOR UPDATE ทุก status)
     * 2. ตรวจ: มีค่าปรับจริง · ยังไม่ถูกยกเว้น · ยังไม่ถูกชำระ
     * 3. ตรวจสิทธิ์ตามยอด — staff ยกเว้นได้ไม่เกิน FINE_WAIVE_STAFF_LIMIT
     * 4. UPDATE fine_waived_* → COMMIT
     *
     * 📥 Input:
     * @param int    $borrowId  รายการยืม
     * @param string $note      เหตุผล (บังคับ — เว้นว่างไม่ได้)
     * @param int    $waivedBy  users.id ของผู้กด
     * @param string $waiverRole 'admin' | 'staff' — ใช้ตัดสินเพดาน
     *
     * 📤 Output: @return array {success, amount, message}
     * @throws Exception ถ้าไม่มีค่าปรับ / ยกเว้นซ้ำ / ชำระแล้ว / เกินสิทธิ์ / ไม่ได้กรอกเหตุผล
     *
     * 🧠 ทำไมไม่ตั้ง fine_amount = 0:
     *    ต้องเก็บไว้ว่าเดิมเท่าไรแล้วยกเว้นให้ ไม่งั้นตรวจย้อนหลังไม่ได้ว่ายกเว้นไปเท่าไร
     *
     * 🛡️ เป็นเรื่องเงิน — บังคับบันทึกว่าใคร เมื่อไหร่ เพราะอะไร ทุกครั้ง
     *    เพราะระบบยังไม่มี audit trail กลาง (ดู KNOWN_LIMITATIONS §4)
     *
     * ✅ Use case: admin/payments.php → ปุ่ม "ยกเว้นค่าปรับ"
     */
    public function waiveFine(int $borrowId, string $note, int $waivedBy, string $waiverRole = 'staff'): array
    {
        // 📝 ตรวจเหตุผลก่อนเปิด transaction — ไม่ต้องไปล็อคแถวถ้ายังไงก็ไม่ผ่าน
        $note = trim($note);
        if ($note === '') {
            throw new Exception('กรุณากรอกเหตุผลที่ยกเว้นค่าปรับ');
        }
        if (mb_strlen($note) > 255) {
            throw new Exception('เหตุผลต้องไม่เกิน 255 ตัวอักษร');
        }

        return runWithDeadlockRetry($this->pdo, function () use ($borrowId, $note, $waivedBy, $waiverRole) {
            $this->pdo->beginTransaction();

            try {
                // 🔒 Step 1: ล็อคแถว borrow (ทุก status เหมือน payFine)
                $borrow = $this->borrowRepo->findByIdForUpdateAnyStatus($borrowId);

                if (!$borrow) {
                    throw new Exception('ไม่พบรายการยืม');
                }

                if ($borrow['fine_amount'] <= 0) {
                    throw new Exception('รายการนี้ไม่มีค่าปรับ');
                }

                if (!empty($borrow['fine_waived_at'])) {
                    throw new Exception('รายการนี้ถูกยกเว้นค่าปรับไปแล้ว');
                }

                // 💰 ชำระไปแล้วต้องยกเว้นไม่ได้ — เงินเข้าไปแล้วจะมายกเว้นทีหลังไม่ได้
                if ($this->paymentRepo->findByBorrowId($borrowId)) {
                    throw new Exception('รายการนี้ชำระค่าปรับแล้ว ยกเว้นไม่ได้');
                }

                // 🔒 Step 2: ตรวจสิทธิ์ตามยอด
                //    เจ้าหน้าที่ยกเว้นเองได้ในวงเงินที่ตั้งไว้ เกินกว่านั้นต้องให้ผู้ดูแล
                //    (ตั้งค่าได้ที่หน้า "ตั้งค่าระบบ" → เจ้าหน้าที่ยกเว้นค่าปรับได้ไม่เกิน)
                $amount = (float) $borrow['fine_amount'];
                if ($waiverRole !== 'admin' && $amount > FINE_WAIVE_STAFF_LIMIT) {
                    throw new Exception(sprintf(
                        'ค่าปรับ %s บาท เกินวงเงินที่เจ้าหน้าที่ยกเว้นได้ (%s บาท) — ต้องให้ผู้ดูแลระบบเป็นผู้ยกเว้น',
                        number_format($amount),
                        number_format(FINE_WAIVE_STAFF_LIMIT)
                    ));
                }

                // 📝 Step 3: บันทึกการยกเว้น
                //    WHERE fine_waived_at IS NULL ในตัว query เป็นด่านสุดท้ายกันยกเว้นซ้ำ
                if (!$this->borrowRepo->waiveFine($borrowId, $waivedBy, $note)) {
                    throw new Exception('รายการนี้ถูกยกเว้นค่าปรับไปแล้ว');
                }

                $this->pdo->commit();

                return [
                    'success' => true,
                    'amount' => $amount,
                    'message' => 'ยกเว้นค่าปรับ ' . number_format($amount) . ' บาท เรียบร้อยแล้ว'
                ];
            } catch (Exception $e) {
                $this->pdo->rollBack();
                error_log("[BorrowService::waiveFine] borrowId={$borrowId} by={$waivedBy} error: " . $e->getMessage());
                throw $e;
            }
        }, 'BorrowService::waiveFine');
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

        // 📚 [BUSINESS RULE] หนังสืออ้างอิงยืมออกไม่ได้ — อ่านในห้องสมุดเท่านั้น
        //    🛡️ ต้องตรวจที่นี่ (Service) ไม่ใช่แค่ซ่อนปุ่มบนหน้าจอ
        //       เพราะ admin/borrow_form.php รับ book_ids[] จาก POST ตรง ๆ
        //       ถ้าเช็คแค่ฝั่งหน้าเว็บ ยิง POST ตรงก็ยืมได้อยู่ดี (รูปแบบเดียวกับที่เคยพลาดใน F-01)
        //    📌 borrowSingleBook() เป็นจุดเดียวที่ทุกเส้นทางการยืมผ่าน จึงคุมได้ครบด้วยด่านเดียว
        if (!empty($book['is_reference'])) {
            return ['success' => false, 'reason' => $book['title'] . ' (หนังสืออ้างอิง อ่านในห้องสมุดเท่านั้น)'];
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
        // 📝 สร้างข้อความแจ้งผล
        //    🛡️ [ATOMIC] skipped จะว่างเสมอ — ถ้าเล่มใดพังจะ throw Exception ก่อนถึงตรงนี้
        $message = "บันทึกการยืมสำเร็จ " . count($borrowed) . " เล่ม";
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
