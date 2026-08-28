<?php

/**
 * ReservationService - Business Logic สำหรับการจองหนังสือ
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * Service นี้จัดการการจองหนังสือ:
 * - จอง (หัก stock ทันที)
 * - อนุมัติ (สร้าง borrow โดยไม่ต้องหัก stock อีก)
 * - ยกเลิก/หมดอายุ (คืน stock กลับ)
 *
 * 🏗️ สถาปัตยกรรม:
 * Controller → ReservationService → ReservationRepository
 *                                  → BookRepository
 *                                  → BorrowRepository (สำหรับ fulfill)
 *
 * 🔄 State Transitions:
 * - pending → fulfilled (admin อนุมัติ → สร้าง borrow)
 * - pending → cancelled (user/admin ยกเลิก → คืน stock)
 * - pending → expired   (lazy expire / cron → คืน stock)
 *
 * 📍 Entrypoints:
 * - api/reserve_book.php           → createReservation()
 * - admin/reservations.php         → fulfillReservation(), cancelReservation()
 * - cron/expire_reservations.php   → expireOverdueReservations()
 * - my_reservations.php            → getUserReservations(), cancelReservation()
 *
 * 🛡️ Security Design:
 * - createReservation(): transaction + FOR UPDATE ป้องกันจองเล่มสุดท้าย
 * - fulfillReservation(): transaction + FOR UPDATE ป้องกัน double approve
 * - cancelReservation(): transaction + FOR UPDATE + owner check
 *
 * ⚠️ ห้ามแก้:
 * - stock ถูกหักตอนจอง — ยกเลิก/หมดอายุต้องคืน stock เสมอ
 *
 * @package App\Services
 */

namespace App\Services;

require_once __DIR__ . '/../Repositories/BookRepository.php';
require_once __DIR__ . '/../Repositories/ReservationRepository.php';
require_once __DIR__ . '/../Repositories/BorrowRepository.php';
require_once __DIR__ . '/../Repositories/UserRepository.php';

use App\Repositories\BookRepository;
use App\Repositories\ReservationRepository;
use App\Repositories\BorrowRepository;
use App\Repositories\UserRepository;
use PDO;
use Exception;

class ReservationService
{
    // 🗄️ PDO + Repositories
    private PDO $pdo;
    private BookRepository $bookRepo;
    private ReservationRepository $reservationRepo;
    private BorrowRepository $borrowRepo;
    private UserRepository $userRepo; // [I-07 FIX] เพิ่มเพื่อ lockById() ตอน check quota

    // 🏗️ Constructor: สร้าง repo ทั้งหมด — ใช้ PDO เดียวกัน = transaction ทำงานข้าม repo
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->bookRepo = new BookRepository($pdo);
        $this->reservationRepo = new ReservationRepository($pdo);
        $this->borrowRepo = new BorrowRepository($pdo);
        $this->userRepo = new UserRepository($pdo); // [I-07 FIX]
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: จองหนังสือ (หัก stock ทันที)
     * ==========================================================================
     *
     * 🔄 Flow:
     * 1. markExpiredReservations() (คืน stock จากที่หมดอายุ)
     * 2. BEGIN TX → lock book (FOR UPDATE)
     * 3. check available > 0 + ไม่มี pending ซ้ำ + ไม่ได้ยืมอยู่ + ไม่เกินโควต้า
     * 4. insert reservation + decrement available
     * 5. COMMIT
     *
     * 📥 Input:
     * @param int $userId     ID member
     * @param int $bookId     ID หนังสือ
     * @param int|null $expireDays วันหมดอายุ (null = RESERVATION_EXPIRE_DAYS จากหน้าตั้งค่าระบบ)
     *
     * 📤 Output: @return array {success, message, expires_at}
     * @throws Exception ถ้าหนังสือหมด/จองซ้ำ
     *
     * 🛡️ Security: FOR UPDATE lock ป้องกันจองเล่มสุดท้าย
     * ⚠️ stock ถูกหักทันที — ยกเลิก/หมดอายุต้องคืน stock
     * ✅ Use case: api/reserve_book.php POST
     */
    public function createReservation(int $userId, int $bookId, ?int $expireDays = null): array
    {
        // ⚙️ แก้จำนวนวันหมดอายุ → หน้า "ตั้งค่าระบบ" (เดิมฝังเลข 2 ไว้ตรงนี้ แก้ไม่ได้เลย)
        //    ลำดับค่า: ตาราง settings → .env → default ใน includes/rules.php
        $expireDays = $expireDays ?? RESERVATION_EXPIRE_DAYS;

        // 📝 Step 0: Lazy expire — คืน stock จาก reservation ที่หมดอายุก่อน
        //    ป้องกันหนังสือดูเหมือน "หมด" ทั้งที่ความจริงว่างแล้ว
        $this->reservationRepo->markExpiredReservations();

        // 📝 Step 1: เปิด transaction
        // 🔁 ครอบด้วย retry — deadlock ของ InnoDB ให้ลองใหม่อัตโนมัติ (ดู FINDINGS F-20)
        //    ปลอดภัยเพราะ closure นี้เปิด/ปิด transaction เองครบ และไม่มี side effect นอก transaction
        return runWithDeadlockRetry($this->pdo, function () use ($userId, $bookId, $expireDays) {
            $this->pdo->beginTransaction();

            try {
                // 🔒 Step 2: Lock หนังสือ (FOR UPDATE)
                //    ป้องกัน 2 คนจองเล่มสุดท้ายพร้อมกัน
                $book = $this->bookRepo->findByIdForUpdate($bookId);

                if (!$book) {
                    throw new Exception('ไม่พบหนังสือ');
                }

                // 👁️ [SECURITY] ป้องกันจองหนังสือที่ถูกซ่อน
                if (empty($book['is_visible'])) {
                    throw new Exception('หนังสือนี้ไม่เปิดให้จองในขณะนี้');
                }

                // 📚 [BUSINESS RULE] หนังสืออ้างอิงจองไม่ได้ — ยืมออกไม่ได้อยู่แล้ว
                //    ต้องกันตั้งแต่ตอนจอง ไม่งั้นสมาชิกจะจองสำเร็จแล้วมารับไม่ได้
                //    (และ stock จะถูกกันไว้เปล่า ๆ 2 วันจนกว่าจะหมดอายุ)
                if (!empty($book['is_reference'])) {
                    throw new Exception('หนังสือเล่มนี้เป็นหนังสืออ้างอิง อ่านได้ที่ห้องสมุดเท่านั้น');
                }

                // 📝 Step 3: ตรวจ stock (ภายใต้ lock)
                if ($book['available'] <= 0) {
                    throw new Exception('หนังสือหมด ไม่สามารถจองได้');
                }

                // 🛡️ Step 4: ตรวจจองซ้ำภายใต้ lock
                //    ป้องกัน concurrent requests จองเล่มเดียวกัน
                if ($this->reservationRepo->hasPending($userId, $bookId)) {
                    throw new Exception('คุณได้จองหนังสือเล่มนี้ไว้แล้ว กรุณารอรับหนังสือ');
                }

                // 🛡️ Step 4.1: ตรวจว่ายืมเล่มนี้อยู่แล้วหรือไม่
                //    ป้องกัน: จองสำเร็จ แต่ admin อนุมัติไม่ได้ (เสีย stock ฟรี)
                //    แจ้ง user ทันทีแทนที่จะรอ admin เจอ error ตอน approve
                if ($this->borrowRepo->isAlreadyBorrowing($userId, $bookId)) {
                    throw new Exception('คุณกำลังยืมหนังสือเล่มนี้อยู่แล้ว ไม่สามารถจองซ้ำได้');
                }

                // 🔒 [I-07 FIX] Lock user row ก่อน check quota
                //    ป้องกัน: admin สร้าง borrow ให้ user ขณะเดียวกับที่ user จอง
                //    → ถ้าไม่ lock ทั้งคู่อาจเห็น count ต่ำกว่าจริง → เกินโควต้าได้
                //    เทียบกับ BorrowService::createBorrow() ที่ใช้ lockById() เช่นกัน
                $this->userRepo->lockById($userId);

                // 🛡️ Step 4.2: ตรวจโควต้า (active borrows + pending reservations)
                //    ป้องกัน: จองสำเร็จ แต่ admin อนุมัติไม่ได้เพราะเกินโควต้า
                //    ⚠️ ต้องนับ pending reservations ด้วย เพราะจะกลายเป็น borrow เมื่อ approve
                //    🔒 [I-07 FIX] ใช้ countActiveBorrowsForUpdate() แทน countActiveBorrows()
                //    เพื่อ lock borrow rows ป้องกัน concurrent borrow+reserve
                $activeBorrows = $this->borrowRepo->countActiveBorrowsForUpdate($userId);
                $pendingReservations = $this->reservationRepo->countPendingByUser($userId);
                if (($activeBorrows + $pendingReservations) >= MAX_BORROW_BOOKS) {
                    throw new Exception('คุณถึงจำนวนหนังสือที่ยืม/จองได้สูงสุดแล้ว (' . MAX_BORROW_BOOKS . ' เล่ม)');
                }

                // 📝 Step 5: INSERT reservation + คำนวณวันหมดอายุ
                $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expireDays} days"));
                $reservationId = $this->reservationRepo->create($userId, $bookId, $expiresAt);

                // 📝 Step 6: หัก stock ทันที (available -1)
                //    ⚠️ stock ถูกหักตอนจอง ไม่ใช่ตอนยืม!
                //    ถ้ายกเลิก/หมดอายุ ต้องคืน stock เสมอ
                $this->bookRepo->decrementAvailable($bookId);

                $this->pdo->commit();

                // 📤 คืนผลสำเร็จ + วันหมดอายุ
                return [
                    'success' => true,
                    'message' => "จองสำเร็จ! กรุณามารับหนังสือ \"{$book['title']}\" ภายในวันที่ " . date('d/m/Y', strtotime($expiresAt)),
                    'reservation_id' => $reservationId, // Added for testing
                    'expires_at' => $expiresAt
                ];
            } catch (Exception $e) {
                // ❌ rollback → stock ไม่ถูกหัก + ไม่มี reservation
                $this->pdo->rollBack();
                error_log("[ReservationService::createReservation] userId={$userId} bookId={$bookId} error: " . $e->getMessage());
                throw $e;
            }
        }, 'ReservationService::createReservation');
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: เข้าคิวรอหนังสือที่ถูกยืมหมด
     * ==========================================================================
     * State: (ไม่มี) → waiting
     *
     * 🧠 **ต่างจาก createReservation() ยังไง**
     *    createReservation() = จองเล่มที่ว่างบนชั้น → หัก available ทันที กันเล่มไว้ให้
     *    joinQueue()         = ต่อคิวรอเล่มที่คนอื่นยืมอยู่ → **ไม่แตะ available เลย**
     *    สองอันนี้เป็นคนละเรื่องกัน อย่ารวมเป็นเมธอดเดียว
     *
     * 📥 Input: @param int $userId, @param int $bookId
     * 📤 Output: @return array {success, reservation_id, position, message}
     *
     * 🧠 กติกาที่ตกลงไว้:
     *    - คิวรอ **ไม่กินโควตายืม** (ยังไม่ได้ถือหนังสือ)
     *    - แต่จำกัดจำนวนคิวต่อคน = MAX_BORROW_BOOKS ("ยืมได้ 3 จองรอได้ 3")
     *    - คิวไม่มีวันหมดอายุ ยกเลิกเองได้
     *
     * ✅ Use case: book.php → ปุ่ม "เข้าคิวรอ" (api/reserve_book.php)
     */
    public function joinQueue(int $userId, int $bookId): array
    {
        // 📝 Step 0: Lazy expire ก่อน — เผื่อมีเล่มว่างอยู่จริงแต่ถูกกันไว้โดยการจองที่หมดอายุ
        //    ถ้าไม่ทำ สมาชิกจะถูกให้ต่อคิวทั้งที่ควรจองได้เลย
        $this->reservationRepo->markExpiredReservations();

        return runWithDeadlockRetry($this->pdo, function () use ($userId, $bookId) {
            $this->pdo->beginTransaction();

            try {
                // 🔒 ล็อคแถวหนังสือ — กันคนคืนหนังสือพร้อมกับคนกดเข้าคิว
                $book = $this->bookRepo->findByIdForUpdate($bookId);

                if (!$book) {
                    throw new Exception('ไม่พบหนังสือ');
                }
                if (empty($book['is_visible'])) {
                    throw new Exception('หนังสือนี้ไม่เปิดให้จองในขณะนี้');
                }
                if (!empty($book['is_reference'])) {
                    throw new Exception('หนังสือเล่มนี้เป็นหนังสืออ้างอิง อ่านได้ที่ห้องสมุดเท่านั้น');
                }

                // 📚 ไม่มีเล่มให้ยืมเลย = ต่อคิวไปก็ไม่มีวันได้
                if ((int) $book['quantity'] <= 0) {
                    throw new Exception('หนังสือเล่มนี้ไม่มีอยู่ในระบบแล้ว ต่อคิวไม่ได้');
                }

                // 🛡️ มีเล่มว่างอยู่ → ต้องจองตรง ๆ ไม่ใช่ต่อคิว
                //    เช็คภายใต้ lock เพราะ available อาจเพิ่งเปลี่ยนไปเมื่อเสี้ยววินาทีก่อน
                if ((int) $book['available'] > 0) {
                    throw new Exception('หนังสือเล่มนี้มีให้ยืมแล้ว กรุณากดจองตามปกติ');
                }

                // 🛡️ กันจองซ้ำ — hasPending() นับ waiting ด้วยแล้ว
                if ($this->reservationRepo->hasPending($userId, $bookId)) {
                    throw new Exception('คุณจองหรือต่อคิวหนังสือเล่มนี้ไว้แล้ว');
                }

                // 🛡️ ยืมเล่มนี้อยู่แล้วก็ไม่ต้องต่อคิว
                if ($this->borrowRepo->isAlreadyBorrowing($userId, $bookId)) {
                    throw new Exception('คุณกำลังยืมหนังสือเล่มนี้อยู่แล้ว');
                }

                // 🛡️ จำกัดจำนวนคิวต่อคน
                //    🧠 **ไม่รวมจำนวนที่ยืมอยู่** — คิวรอไม่กินโควตายืม (ตกลงไว้ใน ROADMAP)
                //    ถ้ารวม คนที่ยืมครบ 3 เล่มจะต่อคิวไม่ได้เลย ทั้งที่การต่อคิว
                //    คือสิ่งที่เขาควรทำระหว่างรออ่านเล่มที่ถืออยู่ให้จบ
                $waiting = $this->reservationRepo->countWaitingByUser($userId);
                if ($waiting >= MAX_BORROW_BOOKS) {
                    throw new Exception('คุณต่อคิวครบ ' . MAX_BORROW_BOOKS . ' เล่มแล้ว กรุณายกเลิกคิวเดิมก่อน');
                }

                // 📝 เข้าคิว — ไม่แตะ available
                $reservationId = $this->reservationRepo->createWaiting($userId, $bookId);

                $position = $this->reservationRepo->getQueuePosition($reservationId);

                $this->pdo->commit();

                return [
                    'success'        => true,
                    'reservation_id' => $reservationId,
                    'position'       => $position,
                    'message'        => sprintf(
                        'เข้าคิวสำเร็จ! คุณอยู่คิวที่ %d ของ "%s" — ระบบจะกันเล่มไว้ให้อัตโนมัติเมื่อมีคนคืน',
                        $position,
                        $book['title']
                    ),
                ];
            } catch (Exception $e) {
                $this->pdo->rollBack();
                error_log("[ReservationService::joinQueue] userId={$userId} bookId={$bookId} error: " . $e->getMessage());
                throw $e;
            }
        }, 'ReservationService::joinQueue');
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: เลื่อนคิวคนถัดไปเมื่อหนังสือกลับเข้าระบบ
     * ==========================================================================
     * State: waiting → pending (+ หัก available กันเล่มไว้ให้)
     *
     * 🔴 **ต้องเรียกภายใน transaction ที่เปิดไว้แล้วเท่านั้น**
     *    เมธอดนี้ไม่เปิด/ปิด transaction เอง โดยตั้งใจ —
     *    ถ้าเลื่อนคิวนอกทรานแซกชันของการคืนหนังสือ จะเกิดช่วงเวลาที่ available = 1
     *    แล้วคนที่ไม่ได้อยู่ในคิวชิงยืมไปก่อนคนที่รอมาเป็นเดือน
     *
     * 🔄 เรียกจาก 4 ที่ที่หนังสือกลับเข้าระบบ:
     *    1. BorrowService::returnBook()   — คืนหนังสือ
     *    2. BorrowService::undoLost()     — ย้อนการแจ้งหาย (เจอหนังสือ)
     *    3. cancelReservation()           — ยกเลิกการจองที่กันเล่มไว้
     *    4. markExpiredReservations()     — การจองหมดอายุ (ผ่าน expireAndPromote)
     *    ทุกที่ที่ available เพิ่มขึ้น ต้องผ่านตรงนี้ ไม่งั้นคิวจะไม่ขยับ
     *
     * 📥 Input: @param int $bookId
     * 📤 Output: @return array|null ข้อมูลคนที่ถูกเลื่อน หรือ null ถ้าไม่มีใครรอ
     *
     * ⚠️ คืน null = ไม่มีคิว → ผู้เรียกต้องปล่อยให้ available เพิ่มตามปกติ
     *    คืน array = เลื่อนแล้ว + หัก available ไปแล้ว → เล่มถูกกันไว้ให้คนในคิว
     */
    public function promoteNextInQueue(int $bookId, ?int $expireDays = null): ?array
    {
        $expireDays = $expireDays ?? RESERVATION_EXPIRE_DAYS;

        // 🧠 ตรรกะจริงอยู่ที่ Repository ที่เดียว เพราะ markExpiredReservations()
        //    ซึ่งอยู่ชั้น Repository ก็ต้องใช้ตัวเดียวกัน — ดูเหตุผลเต็มที่เมธอดนั้น
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expireDays} days"));

        return $this->reservationRepo->promoteNextInQueue($bookId, $expiresAt);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ยกเลิกการจอง + คืน stock
     * ==========================================================================
     * State: pending → cancelled
     *
     * 🔄 Flow: BEGIN TX → lock reservation → updateStatus → incrementAvailable → COMMIT
     *
     * 📥 Input:
     * @param int      $reservationId
     * @param int|null $userId  ถ้าส่ง = ต้องเป็นเจ้าของ, null = admin
     *
     * 📤 Output: @return array {success, message}
     *
     * 🛡️ Security: ถ้าเรียกจาก member ต้องส่ง userId เพื่อป้องกัน authorization leak
     * ✅ Use case: admin/reservations.php, my_reservations.php
     */
    public function cancelReservation(int $reservationId, ?int $userId = null): array
    {
        // 🔁 ครอบด้วย retry — deadlock ของ InnoDB ให้ลองใหม่อัตโนมัติ (ดู FINDINGS F-20)
        //    ปลอดภัยเพราะ closure นี้เปิด/ปิด transaction เองครบ และไม่มี side effect นอก transaction
        return runWithDeadlockRetry($this->pdo, function () use ($reservationId, $userId) {
            $this->pdo->beginTransaction();

            try {
                // 🔒 Step 1: Lock reservation — รับทั้ง waiting และ pending
                //    $userId != null → เพิ่ม WHERE user_id = ? (ตรวจ ownership)
                //    $userId = null → admin ยกเลิกได้ทุกคน
                $reservation = $this->reservationRepo->findActiveForUpdate($reservationId, $userId);

                if (!$reservation) {
                    throw new Exception('ไม่พบรายการจองหรือยกเลิกไปแล้ว');
                }

                // 🧠 จำสถานะเดิมไว้ก่อนเปลี่ยน — ตัวนี้ตัดสินว่าต้องคืนสต็อกไหม
                $wasPending = ($reservation['status'] === 'pending');

                // 📝 Step 2: waiting|pending → cancelled
                $this->reservationRepo->updateStatus($reservationId, 'cancelled');

                $promoted = null;
                if ($wasPending) {
                    // 📝 Step 3: คืน stock กลับ (available +1)
                    //    ⚠️ ต้องคืนเสมอ! stock ถูกหักตอนจอง
                    //    🧠 เฉพาะ pending เท่านั้น — คิวรอ (waiting) ไม่เคยหักสต็อกไว้
                    //       ถ้าคืนให้ด้วย หนังสือจะงอกขึ้นมาจากอากาศ
                    $this->bookRepo->incrementAvailable($reservation['book_id']);

                    // 🔄 Step 4: เล่มที่เพิ่งว่างต้องไปหาคนที่ต่อคิวรอก่อน ไม่ใช่ขึ้นชั้นให้ใครก็ได้
                    //    ต้องอยู่ใน transaction เดียวกัน ด้วยเหตุผลเดียวกับตอนคืนหนังสือ
                    $promoted = $this->promoteNextInQueue((int) $reservation['book_id']);
                }

                $this->pdo->commit();

                $message = $wasPending ? 'ยกเลิกการจองสำเร็จ' : 'ออกจากคิวรอสำเร็จ';
                if ($promoted !== null) {
                    $message .= ' | 🔄 กันเล่มไว้ให้คนที่ต่อคิวรอถัดไปแล้ว';
                }

                return [
                    'success' => true,
                    'was_waiting' => !$wasPending,
                    'promoted' => $promoted,
                    'message' => $message,
                    'reservation_id' => $reservationId
                ];
            } catch (Exception $e) {
                // ❌ rollback → ยังเป็น pending + stock ไม่ถูกคืน
                $this->pdo->rollBack();
                error_log("[ReservationService::cancelReservation] resId={$reservationId} error: " . $e->getMessage());
                throw $e;
            }
        }, 'ReservationService::cancelReservation');
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: อนุมัติการจอง → สร้าง borrow อัตโนมัติ
     * ==========================================================================
     * State: pending → fulfilled
     *
     * 🔄 Flow:
     * 1. BEGIN TX → lock reservation (FOR UPDATE)
     * 2. check ไม่ยืมซ้ำ + check โควต้า
     * 3. insert borrow
     * 4. updateStatusWithBorrow(fulfilled, borrow_id)
     * 5. COMMIT
     *
     * 📥 Input: @param int $reservationId, @param int|null $borrowDays
     * 📤 Output: @return array {success, borrow_id, due_date, message}
     *
     * 🧠 เหตุผล: ไม่หัก stock อีก เพราะหักไว้แล้วตอนจอง
     * 🛡️ Security: FOR UPDATE lock ป้องกัน double approve
     * ✅ Use case: admin/reservations.php → ปุ่มอนุมัติ
     */
    public function fulfillReservation(int $reservationId, ?int $borrowDays = null): array
    {
        // 📝 ใช้ default จาก config.php
        $borrowDays = $borrowDays ?? DEFAULT_BORROW_DAYS;

        // 🔁 ครอบด้วย retry — deadlock ของ InnoDB ให้ลองใหม่อัตโนมัติ (ดู FINDINGS F-20)
        //    ปลอดภัยเพราะ closure นี้เปิด/ปิด transaction เองครบ และไม่มี side effect นอก transaction
        return runWithDeadlockRetry($this->pdo, function () use ($reservationId, $borrowDays) {
            $this->pdo->beginTransaction();

            try {
                // 🔒 Step 1: Lock reservation (FOR UPDATE) ป้องกัน double approve
                //    2 admin กดอนุมัติพร้อมกัน → คนที่ 2 จะได้ null
                $reservation = $this->reservationRepo->findPendingForUpdate($reservationId);

                if (!$reservation) {
                    throw new Exception('ไม่พบรายการจองหรือไม่อยู่ในสถานะรอรับ');
                }

                // 🛡️ Step 2: ตรวจยืมเล่มนี้ซ้ำหรือไม่ (ป้องกัน duplicate borrow)
                if ($this->borrowRepo->isAlreadyBorrowing($reservation['user_id'], $reservation['book_id'])) {
                    throw new Exception('ผู้จองกำลังยืมหนังสือเล่มนี้อยู่แล้ว');
                }

                // 🛡️ Step 3: ตรวจโควต้า (FOR UPDATE lock บน borrows)
                //    🔒 [I-08 FIX] นับ pending reservations อื่นด้วย (ลบ 1 = ตัวที่กำลัง fulfill)
                //    ป้องกัน: user มี 2 pending + 1 borrow (max=3) → ถ้า approve ทั้ง 2 จะเกินโควต้า
                //    เดิมเช็คแค่ currentBorrows → ไม่เห็น pending อื่นที่กำลังจะกลายเป็น borrow
                $currentBorrows = $this->borrowRepo->countActiveBorrowsForUpdate($reservation['user_id']);
                $otherPending = $this->reservationRepo->countPendingByUser($reservation['user_id']) - 1;
                if (($currentBorrows + max(0, $otherPending)) >= MAX_BORROW_BOOKS) {
                    throw new Exception('ผู้จองถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว (' . MAX_BORROW_BOOKS . ' เล่ม)');
                }

                // 📝 Step 4: INSERT borrow record
                //    ไม่ต้องหัก stock อีก เพราะหักไว้แล้วตอนจอง
                $borrowDate = date('Y-m-d');
                $dueDate = date('Y-m-d', strtotime("+{$borrowDays} days"));

                $borrowId = $this->borrowRepo->create([
                    'user_id' => $reservation['user_id'],
                    'book_id' => $reservation['book_id'],
                    'borrow_date' => $borrowDate,
                    'due_date' => $dueDate
                ]);

                // 📝 Step 5: pending → fulfilled + link borrow_id
                $this->reservationRepo->updateStatusWithBorrow($reservationId, 'fulfilled', $borrowId);

                $this->pdo->commit();

                // 📤 คืนผล: borrow_id + กำหนดคืน
                return [
                    'success' => true,
                    'borrow_id' => $borrowId,
                    'due_date' => $dueDate,
                    'message' => 'อนุมัติการจองสำเร็จ! สร้างรายการยืมแล้ว กำหนดคืน: ' . date('d/m/Y', strtotime($dueDate))
                ];
            } catch (Exception $e) {
                // ❌ rollback → ยังเป็น pending + ไม่มี borrow
                $this->pdo->rollBack();
                error_log("[ReservationService::fulfillReservation] resId={$reservationId} error: " . $e->getMessage());
                throw $e;
            }
        }, 'ReservationService::fulfillReservation');
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: expire การจองหมดอายุ + คืน stock (batch job)
     * ==========================================================================
     * State: pending (expires_at < now) → expired
     *
     * 🔄 Flow: BEGIN TX → findExpiredForUpdate → loop: updateStatus + incrementAvailable → COMMIT
     *
     * 📤 Output: @return int จำนวนที่ expire
     * ✅ Use case: cron/expire_reservations.php
     */
    public function expireOverdueReservations(): int
    {
        $this->pdo->beginTransaction();

        try {
            // 🔒 Step 1: ดึง reservation ที่หมดอายุ (FOR UPDATE lock)
            $expired = $this->reservationRepo->findExpiredForUpdate();

            $count = 0;
            foreach ($expired as $res) {
                // 📝 Step 2a: pending → expired
                $this->reservationRepo->updateStatus($res['id'], 'expired');

                // 📝 Step 2b: คืน stock (available +1)
                $this->bookRepo->incrementAvailable($res['book_id']);

                $count++;
            }

            $this->pdo->commit();
            // 📤 คืนจำนวนที่ expire
            return $count;
        } catch (Exception $e) {
            // ❌ rollback → reservation ยังเป็น pending
            $this->pdo->rollBack();
            error_log("[ReservationService::expireOverdue] error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการจองของ user (pass-through)
     * ==========================================================================
     *
     * 📥 Input: @param int $userId, @param string|null $status
     * 📤 Output: @return array
     * ✅ Use case: my_reservations.php
     */
    public function getUserReservations(int $userId, ?string $status = null): array
    {
        // 📝 Pass-through → reservation ของ user (กรอง status ถ้าระบุ)
        return $this->reservationRepo->findByUser($userId, $status);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึง pending reservations (pass-through)
     * ==========================================================================
     *
     * 📥 Input: @param int $limit
     * 📤 Output: @return array
     * ✅ Use case: DashboardService, admin/reservations.php
     */
    public function getPendingReservations(int $limit = 10): array
    {
        // 📝 Pass-through → pending reservations
        return $this->reservationRepo->findPending($limit);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับ pending reservations (pass-through)
     * ==========================================================================
     *
     * 📤 Output: @return int
     * ✅ Use case: DashboardService → badge notification
     */
    public function countPending(): int
    {
        // 📝 Pass-through → COUNT pending (สำหรับ badge บน dashboard)
        return $this->reservationRepo->countPending();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ตรวจว่ามี pending reservation อยู่หรือไม่
     * ==========================================================================
     *
     * 📥 Input: @param int $userId, @param int $bookId
     * 📤 Output: @return bool
     * ✅ Use case: book.php → แสดงปุ่มจอง/ยกเลิก
     */
    public function hasPendingReservation(int $userId, int $bookId): bool
    {
        // 📝 Pass-through → มี pending ของ user+book หรือไม่
        return $this->reservationRepo->hasPending($userId, $bookId);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงข้อมูล pending reservation ของ user+book
     * ==========================================================================
     *
     * 📥 Input: @param int $userId, @param int $bookId
     * 📤 Output: @return array|null reservation data หรือ null
     * ✅ Use case: book.php → แสดงปุ่มยกเลิกการจอง
     */
    public function getUserPendingReservation(int $userId, int $bookId): ?array
    {
        // 📝 Pass-through → reservation data ของ user+book (สำหรับปุ่มยกเลิก)
        return $this->reservationRepo->findByUserAndBook($userId, $bookId, 'pending');
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงการจองที่ยังมีชีวิตของสมาชิกกับหนังสือเล่มนี้ (รวมคิวรอ)
     * ==========================================================================
     * 📤 Output: @return array|null แถวการจอง + 'queue_position' ถ้าเป็นคิวรอ
     *
     * 🧠 ต่างจาก getUserPendingReservation() ที่ได้เฉพาะ pending —
     *    หน้า book.php ต้องรู้ด้วยว่าคนนี้ต่อคิวอยู่ไหม ไม่งั้นจะโชว์ปุ่ม
     *    "เข้าคิวรอ" ให้คนที่ต่อคิวไปแล้ว แล้วกดไปโดนปฏิเสธ
     */
    public function getUserActiveReservation(int $userId, int $bookId): ?array
    {
        $row = $this->reservationRepo->findByUserAndBook($userId, $bookId, 'pending')
            ?? $this->reservationRepo->findByUserAndBook($userId, $bookId, 'waiting');

        if ($row && $row['status'] === 'waiting') {
            $row['queue_position'] = $this->reservationRepo->getQueuePosition((int) $row['id']);
        }

        return $row;
    }

    /**
     * 🎯 จำนวนคนที่ต่อคิวรอหนังสือเล่มนี้ (สำหรับแสดงบนหน้ารายละเอียด)
     */
    public function countWaitingForBook(int $bookId): int
    {
        return $this->reservationRepo->countWaitingByBook($bookId);
    }
}
