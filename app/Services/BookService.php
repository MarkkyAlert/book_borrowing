<?php

/**
 * BookService - Business Logic สำหรับการจัดการหนังสือ
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * Service นี้จัดการ CRUD หนังสือ + business rule validation
 * เช่น quantity/available ต้องสัมพันธ์กัน และเงื่อนไขการลบ
 *
 * 🏗️ สถาปัตยกรรม:
 * Controller (admin/books.php) → BookService → BookRepository
 *                                              → BorrowRepository (เช็คก่อนลบ)
 *                                              → ReservationRepository (เช็คก่อนลบ)
 *
 * 📍 Entrypoints:
 * - admin/books.php      → getBooks(), deleteBook()
 * - admin/book_form.php  → createBook(), updateBook()
 * - index.php, book.php  → getBooks(), getBookById()
 * - DashboardService     → getStatistics()
 *
 * 🛡️ Security Design:
 * - deleteBook() ใช้ transaction + row lock + ตรวจ 3 เงื่อนไขก่อนลบ
 * - updateBook() ตรวจว่าลด quantity ได้โดยไม่ทำให้ available ติดลบ
 *
 * ⚠️ ห้ามแก้:
 * - available ห้ามแก้โดยตรง — ต้องผ่าน BorrowService/ReservationService
 * - deleteBook() ตรวจ borrow history ก่อนลบ
 *
 * @package App\Services
 */

namespace App\Services;

require_once __DIR__ . '/../Repositories/BookRepository.php';
require_once __DIR__ . '/../Repositories/BorrowRepository.php';
require_once __DIR__ . '/../Repositories/ReservationRepository.php';

use App\Repositories\BookRepository;
use App\Repositories\BorrowRepository;
use App\Repositories\ReservationRepository;
use PDO;
use Exception;

class BookService
{
    // 🗄️ PDO connection — ใช้สำหรับ transaction (deleteBook)
    private PDO $pdo;
    // 🗄️ Repositories — แต่ละตัวจัดการ table เฉพาะ
    private BookRepository $bookRepo;
    private BorrowRepository $borrowRepo;
    private ReservationRepository $reservationRepo;

    // 🏗️ Constructor: สร้าง repo ทั้งหมด — ใช้ PDO เดียวกัน = transaction ทำงานถูกต้อง
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->bookRepo = new BookRepository($pdo);
        $this->borrowRepo = new BorrowRepository($pdo);
        $this->reservationRepo = new ReservationRepository($pdo);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการหนังสือ + filters (pass-through ไป Repository)
     * ==========================================================================
     *
     * 📥 Input: @param array $filters {search?, category_id?, status?, sort?}
     * 📤 Output: @return array รายการหนังสือ
     * ✅ Use case: admin/books.php (index.php ใช้ HomeService::getBooks() แทน)
     */
    public function getBooks(array $filters = []): array
    {
        // � [LAZY EXPIRE] คืน stock จาก reservation ที่หมดอายุก่อนดึงข้อมูล
        //    ถ้าไม่ทำ → admin/books.php จะแสดง available ผิด (จองหมดอายุแล้วแต่ stock ยังไม่คืน)
        $this->reservationRepo->markExpiredReservations();

        // �� Pass-through ไป Repository — Repository จัดการ search, category_id, status, sort ใน SQL
        //    Service ไม่เพิ่ม logic เพราะเป็นแค่การดึงข้อมูล
        return $this->bookRepo->findAll($filters);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับจำนวนหนังสือที่ตรงเงื่อนไข (สำหรับแบ่งหน้า)
     * ==========================================================================
     * 📝 Pass-through ไป Repository — ไม่มี logic เพิ่ม เหมือน getBooks()
     * ✅ Use case: admin/books.php ต้องรู้ยอดรวมเพื่อคำนวณว่ามีกี่หน้า
     * ⚠️ ต้องส่ง $filters ชุดเดียวกับที่ส่งให้ getBooks() ไม่งั้นยอดกับรายการจะไม่ตรงกัน
     */
    public function countBooks(array $filters = []): int
    {
        return $this->bookRepo->countAll($filters);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงหนังสือตาม ID (พร้อม category_name)
     * ==========================================================================
     *
     * 📥 Input: @param int $id
     * 📤 Output: @return array|null หนังสือ + category_name หรือ null
     * ✅ Use case: book.php, admin/book_form.php (edit mode)
     */
    public function getBookById(int $id): ?array
    {
        // � [LAZY EXPIRE] คืน stock จาก reservation ที่หมดอายุก่อนดึงข้อมูล
        //    ถ้าไม่ทำ → book.php จะแสดง available ผิด (จองหมดอายุแล้วแต่ stock ยังไม่คืน)
        $this->reservationRepo->markExpiredReservations();

        // �� Pass-through → findById (JOIN category_name)
        return $this->bookRepo->findById($id);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงหนังสือที่ยังว่าง (available > 0)
     * ==========================================================================
     *
     * 📤 Output: @return array[] หนังสือที่มี stock เรียงตามชื่อ
     * ✅ Use case: admin/borrow_form.php → dropdown เลือกหนังสือ
     */
    public function getAvailableBooks(): array
    {
        // 🔄 [LAZY EXPIRE] คืน stock จาก reservation ที่หมดอายุก่อนดึง dropdown
        //    ถ้าไม่ทำ → admin/borrow_form.php dropdown จะแสดงหนังสือว่างน้อยกว่าจริง
        $this->reservationRepo->markExpiredReservations();

        // 📝 Pass-through → findAvailable (WHERE available > 0)
        return $this->bookRepo->findAvailable();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สร้างหนังสือใหม่ (pass-through ไป Repository)
     * ==========================================================================
     *
     * 📥 Input: @param array $data {title, author, isbn?, category_id?, description?, cover_image?, quantity?}
     * 📤 Output: @return int Book ID ที่สร้าง
     * ✅ Use case: admin/book_form.php POST (create mode)
     */
    public function createBook(array $data): int
    {
        // 📝 Pass-through → INSERT (available = quantity โดย default ใน repo)
        return $this->bookRepo->create($data);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: อัปเดตหนังสือ + คำนวณ available ตาม quantity diff
     * ==========================================================================
     *
     * 🔄 Flow: findById → validate quantity → calc available → update
     *
     * 📥 Input: @param int $id, @param array $data {title, author, isbn?, ...}
     * 📤 Output: @return bool true = สำเร็จ
     * @throws Exception ถ้าลด quantity ต่ำกว่าจำนวนที่ออกอยู่
     *
     * 🧠 เหตุผล: available = old_available + (new_quantity - old_quantity)
     *   ห้ามลด quantity < currentlyOut (จะทำให้ available ติดลบ)
     *
     * ✅ Use case: admin/book_form.php POST (edit mode)
     */
    public function updateBook(int $id, array $data): bool
    {
        // �️ [I-06 FIX] ครอบ transaction + FOR UPDATE lock ป้องกัน race condition
        //    ถ้าไม่ lock → concurrent borrow/reserve อาจเปลี่ยน available ระหว่างที่เรา
        //    อ่านค่าเดิม กับ เขียนค่าใหม่ → stock inconsistency
        //    เช่น: admin อ่าน available=3, user จองทำให้เป็น 2, admin เขียนทับเป็น 8 → สูญหาย 1
        $this->pdo->beginTransaction();

        try {
            // 📝 Step 1: Lock book row (FOR UPDATE) ก่อนอ่านค่า
            //    ป้องกัน concurrent borrow/reserve แก้ available ระหว่างเราคำนวณ
            $book = $this->bookRepo->findByIdForUpdate($id);
            if (!$book) {
                throw new Exception('ไม่พบหนังสือ');
            }

            // 📝 Step 2: คำนวณ available ใหม่จาก quantity diff
            //    สูตร: available_ใหม่ = available_เดิม + (quantity_ใหม่ - quantity_เดิม)
            $oldQuantity = $book['quantity'];
            $newQuantity = $data['quantity'] ?? $oldQuantity;

            // 🛡️ [DATA INTEGRITY] ห้ามลด quantity ต่ำกว่าจำนวนที่ออกอยู่ (ยืม+จอง)
            //    เช่น quantity=10, available=3 → currentlyOut=7 → ลดเป็น 6 ไม่ได้ (ติดลบ)
            $currentlyOut = $oldQuantity - $book['available'];
            if ($newQuantity < $currentlyOut) {
                throw new \Exception("ไม่สามารถลดจำนวนเป็น {$newQuantity} ได้ เพราะมีหนังสือออกอยู่ {$currentlyOut} เล่ม (ยืม/จอง)");
            }

            // 📝 Step 3: คำนวณ available ใหม่
            //    max(0, ...) ป้องกันติดลบ (safety net)
            $quantityDiff = $newQuantity - $oldQuantity;
            $newAvailable = max(0, $book['available'] + $quantityDiff);

            // 📝 Step 4: UPDATE ทั้งข้อมูล + quantity/available ที่คำนวณแล้ว
            $result = $this->bookRepo->update($id, [
                'title' => $data['title'],
                'author' => $data['author'],
                'isbn' => $data['isbn'] ?? null,
                // 📍 เลขเรียกหนังสือ — ต้องส่งต่อ ไม่งั้นแก้ไขแล้วค่าหาย
                //    (Service สร้าง array ใหม่ทีละ key คีย์ที่ไม่ได้ระบุจะตกหล่นเงียบ ๆ)
                'call_number' => $data['call_number'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'description' => $data['description'] ?? null,
                // 📓 หมายเหตุรายเล่ม — ต้องส่งต่อด้วยเหตุผลเดียวกับ call_number
                'copy_notes' => $data['copy_notes'] ?? null,
                'cover_image' => $data['cover_image'] ?? null,
                'quantity' => $newQuantity,
                'available' => $newAvailable,
                'is_visible' => $data['is_visible'] ?? 1
            ]);

            $this->pdo->commit();
            return $result;
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            error_log("[BookService::updateBook] bookId={$id} error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ลบหนังสือ + ตรวจ 3 เงื่อนไข + ลบรูปปก
     * ==========================================================================
     *
     * 🔄 Flow: BEGIN TX → lock row → check 3 guards → DELETE → COMMIT → delete cover file
     *
     * 📥 Input: @param int $id Book ID
     * 📤 Output: @return bool true = สำเร็จ
     * @throws Exception ถ้ามีคนยืม/มีประวัติ/มี pending reservation
     *
     * 🛡️ Security: transaction + FOR UPDATE lock
     * 🧠 เหตุผล: ลบรูปหลัง COMMIT — ป้องกัน orphan file ถ้า DB ล้มเหลว
     * ✅ Use case: admin/books.php DELETE
     */
    public function deleteBook(int $id): bool
    {
        // 📝 Step 1: เปิด transaction
        $this->pdo->beginTransaction();

        try {
            // 🔒 Step 2: Lock book row (FOR UPDATE) ป้องกันลบพร้อมกัน 2 admin
            $book = $this->bookRepo->findByIdForUpdate($id);

            if (!$book) {
                throw new Exception('ไม่พบหนังสือที่ต้องการลบ');
            }

            // 🛡️ Step 3: ตรวจ 3 เงื่อนไขก่อนลบ — CASCADE DELETE จะทำลายข้อมูลที่เกี่ยวข้อง
            // Guard #1: มีคนยืมอยู่หรือไม่
            if ($this->isBeingBorrowed($id)) {
                throw new Exception('ไม่สามารถลบได้ หนังสือเล่มนี้กำลังถูกยืมอยู่');
            }

            // Guard #2: มีประวัติการยืมหรือไม่ (ลบแล้วสถิติหาย)
            if ($this->hasBorrowHistory($id)) {
                throw new Exception('ไม่สามารถลบได้ หนังสือเล่มนี้มีประวัติการยืม');
            }

            // Guard #3: มี pending reservation (หัก stock ไปแล้ว ลบจะทำให้ stock ไม่ถูกคืน)
            // 🧠 ต้องนับคิวรอด้วย — ลบเล่มที่มีคนต่อคิวรออยู่ไม่ได้
            if ($this->reservationRepo->countActiveByBook($id) > 0) {
                // 🧠 [F-46] ด่านนี้ใช้ countActiveByBook() ซึ่งนับทั้งการจองที่รอมารับ **และคิวรอ**
                throw new Exception('ไม่สามารถลบได้ หนังสือเล่มนี้ยังมีคนจองรอมารับ หรือต่อคิวรออยู่');
            }

            // 📝 Step 4: DELETE book จาก DB
            $this->bookRepo->delete($id);

            $this->pdo->commit();

            // 🧹 Step 5: ลบรูปปกหลัง DB commit สำเร็จ
            //    ลบหลัง commit เพราะถ้าลบรูปก่อนแล้ว DB rollback → orphan file
            if (!empty($book['cover_image'])) {
                $this->deleteCoverImage($book['cover_image']);
            }

            return true;
        } catch (Exception $e) {
            // ❌ rollback → หนังสือยังอยู่
            $this->pdo->rollBack();
            error_log("[BookService::deleteBook] bookId={$id} error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: บอกเหตุผลว่าทำไมลบหนังสือเล่มนี้ไม่ได้ (สำหรับแสดงบน UI)
     * ==========================================================================
     *
     * 📥 Input: @param array $book แถวจาก BookRepository::findAll()
     *           (ต้องมี total_borrows, active_borrows, pending_reservations)
     * 📤 Output: @return string|null ข้อความเหตุผล หรือ null = ลบได้
     *
     * 🧠 เหตุผล: เงื่อนไขต้องตรงกับ guard ใน deleteBook() เป๊ะ ๆ
     *   ก่อนหน้านี้หน้า list เช็คแค่ "available == quantity" ทำให้ปุ่มลบเปิดใช้งาน
     *   ทั้งที่หนังสือมีประวัติการยืม → เจ้าหน้าที่กดแล้วเจอ error ทุกครั้ง
     *   ⚠️ แก้ guard ใน deleteBook() เมื่อไหร่ ต้องแก้ที่นี่ด้วย
     *
     * ✅ Use case: admin/books.php (disable ปุ่มลบ + tooltip บอกเหตุผล)
     */
    public function getDeleteBlockReason(array $book): ?string
    {
        if (($book['active_borrows'] ?? 0) > 0) {
            return 'ไม่สามารถลบได้ เนื่องจากกำลังถูกยืมอยู่';
        }
        if (($book['total_borrows'] ?? 0) > 0) {
            return 'ไม่สามารถลบได้ เนื่องจากมีประวัติการยืม';
        }
        if (($book['pending_reservations'] ?? 0) > 0) {
            return 'ไม่สามารถลบได้ เนื่องจากยังมีคนจองรอมารับ หรือต่อคิวรออยู่';
        }
        return null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ตรวจว่าหนังสือกำลังถูกยืมอยู่หรือไม่
     * ==========================================================================
     *
     * 📥 Input: @param int $bookId
     * 📤 Output: @return bool true = มีคนยืมอยู่
     * ✅ Use case: deleteBook() guard #1
     */
    public function isBeingBorrowed(int $bookId): bool
    {
        // 📝 นับ status='borrowing' ของหนังสือนี้ > 0 = มีคนยืมอยู่
        return $this->borrowRepo->countActiveByBook($bookId) > 0;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ตรวจว่าหนังสือมีประวัติการยืมหรือไม่ (ทุก status)
     * ==========================================================================
     *
     * 📥 Input: @param int $bookId
     * 📤 Output: @return bool true = มีประวัติ (ไม่ควรลบ)
     * ✅ Use case: deleteBook() guard #2
     */
    public function hasBorrowHistory(int $bookId): bool
    {
        // 📝 นับทุก status ของหนังสือนี้ > 0 = มีประวัติ (ไม่ควรลบ)
        return $this->borrowRepo->countByBook($bookId) > 0;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ค้นหาหนังสือโดย ID หรือ ISBN (สำหรับ barcode scan)
     * ==========================================================================
     *
     * 📥 Input: @param string $identifier (ID หรือ ISBN)
     * 📤 Output: @return array|null {id, title, author, available} หรือ null
     * ✅ Use case: admin/borrow_form.php → search book by barcode/ID
     */
    public function findByIdOrIsbn(string $identifier): ?array
    {
        // 📝 Pass-through → ค้นหาตาม ID หรือ ISBN (สำหรับ barcode scan)
        return $this->bookRepo->findByIdOrIsbn($identifier);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สถิติหนังสือภาพรวม (สำหรับ dashboard)
     * ==========================================================================
     *
     * 📤 Output: @return array {total, available, borrowed, titles}
     * ✅ Use case: DashboardService, HomeService
     */
    public function getStatistics(): array
    {
        // 📝 Pass-through → {total, available, borrowed, titles}
        return $this->bookRepo->getStatistics();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ลบไฟล์รูปปกจาก disk (เรียกหลัง DB commit)
     * ==========================================================================
     *
     * 📥 Input: @param string $filename ชื่อไฟล์ (ไม่รวม path)
     * 🧠 เหตุผล: เรียกหลัง commit — ป้องกัน orphan file ถ้า DB rollback
     * ✅ Use case: deleteBook() ขั้นตอนสุดท้าย
     */
    private function deleteCoverImage(string $filename): void
    {
        // 📝 สร้าง full path จาก project root + uploads/covers/
        $coverPath = dirname(__DIR__, 2) . '/uploads/covers/' . $filename;
        // 🛡️ file_exists ป้องกัน error ถ้าไฟล์ถูกลบไปแล้ว
        if (file_exists($coverPath)) {
            unlink($coverPath);
        }
    }
}
