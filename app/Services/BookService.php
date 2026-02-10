<?php
/**
 * BookService - Business Logic สำหรับการจัดการหนังสือ
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - Service นี้จัดการ CRUD หนังสือ
 * - quantity = จำนวนทั้งหมด, available = จำนวนที่ว่าง
 * - available จะลดเมื่อยืม/จอง และเพิ่มเมื่อคืน/ยกเลิก
 * 
 * 📍 Entrypoints:
 * - admin/books.php      → getBooks(), deleteBook()
 * - admin/book_form.php  → createBook(), updateBook()
 * - index.php, book.php  → getBooks(), getBookById()
 * 
 * ⚠️ ห้ามแก้:
 * - available ห้ามแก้โดยตรง - ต้องผ่าน BorrowService/ReservationService
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
    private PDO $pdo;
    private BookRepository $bookRepo;
    private BorrowRepository $borrowRepo;
    private ReservationRepository $reservationRepo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->bookRepo = new BookRepository($pdo);
        $this->borrowRepo = new BorrowRepository($pdo);
        $this->reservationRepo = new ReservationRepository($pdo);
    }

    /**
     * ดึงรายการหนังสือทั้งหมด พร้อม filter
     * 
     * @param array $filters {
     *     search?: string,
     *     category_id?: int,
     *     status?: string ('available', 'borrowed', 'out_of_stock', 'low_stock'),
     *     sort?: string ('newest', 'oldest', 'az')
     * }
     */
    public function getBooks(array $filters = []): array
    {
        // Repository จัดการ search, category_id, status, sort ใน SQL ทั้งหมด
        return $this->bookRepo->findAll($filters);
    }

    /**
     * ดึงหนังสือตาม ID
     * 
     * @param int $id ID หนังสือ
     * @return array|null ข้อมูลหนังสือพร้อม category_name, null ถ้าไม่พบ
     */
    public function getBookById(int $id): ?array
    {
        return $this->bookRepo->findById($id);
    }

    /**
     * ดึงหนังสือที่ยังว่างอยู่ (available > 0)
     * 
     * @return array[] รายการหนังสือที่มี stock เรียงตามชื่อ
     */
    public function getAvailableBooks(): array
    {
        return $this->bookRepo->findAvailable();
    }

    /**
     * สร้างหนังสือใหม่
     * 
     * @param array $data {
     *     title: string,        // ชื่อหนังสือ (required)
     *     author: string,       // ผู้แต่ง (required)
     *     isbn?: string,        // ISBN
     *     category_id?: int,    // ID หมวดหมู่
     *     description?: string, // รายละเอียด
     *     cover_image?: string, // ชื่อไฟล์รูปปก
     *     quantity?: int        // จำนวน (default: 1)
     * }
     * @return int ID หนังสือที่สร้าง
     * @sideeffect INSERT ลง books table
     */
    public function createBook(array $data): int
    {
        return $this->bookRepo->create($data);
    }

    /**
     * อัปเดตหนังสือ
     * 
     * @param int $id ID หนังสือ
     * @param array $data ข้อมูลที่ต้องการอัปเดต (ดูโครงสร้างใน createBook)
     * @return bool true = สำเร็จ
     * @throws Exception ถ้าไม่พบหนังสือ
     * @sideeffect UPDATE books table, available จะถูกคำนวณใหม่ตาม quantity diff
     */
    public function updateBook(int $id, array $data): bool
    {
        $book = $this->getBookById($id);
        if (!$book) {
            throw new Exception('ไม่พบหนังสือ');
        }

        // Calculate new available based on quantity change
        $oldQuantity = $book['quantity'];
        $newQuantity = $data['quantity'] ?? $oldQuantity;
        
        // [DATA INTEGRITY] ห้ามลด quantity ต่ำกว่าจำนวนที่ออกอยู่ — ไม่งั้น available จะติดลบ
        $currentlyOut = $oldQuantity - $book['available'];
        if ($newQuantity < $currentlyOut) {
            throw new \Exception("ไม่สามารถลดจำนวนเป็น {$newQuantity} ได้ เพราะมีหนังสือออกอยู่ {$currentlyOut} เล่ม (ยืม/จอง)");
        }
        
        $quantityDiff = $newQuantity - $oldQuantity;
        $newAvailable = max(0, $book['available'] + $quantityDiff);

        return $this->bookRepo->update($id, [
            'title' => $data['title'],
            'author' => $data['author'],
            'isbn' => $data['isbn'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'description' => $data['description'] ?? null,
            'cover_image' => $data['cover_image'] ?? null,
            'quantity' => $newQuantity,
            'available' => $newAvailable
        ]);
    }

    /**
     * ลบหนังสือพร้อมตรวจเงื่อนไข 3 ข้อ และลบไฟล์รูปปก
     * 
     * @param int $id ID หนังสือ
     * @return bool true = สำเร็จ
     * 
     * @throws Exception ถ้า:
     *     - หนังสือกำลังถูกยืม (isBeingBorrowed)
     *     - มีประวัติการยืม (hasBorrowHistory)
     *     - มีการจองที่รอดำเนินการ (countPendingByBook > 0)
     * 
     * @sideeffect DELETE จาก books table + ลบไฟล์ uploads/covers/
     * @security ใช้ transaction + row lock (FOR UPDATE) ป้องกัน race condition
     */
    public function deleteBook(int $id): bool
    {
        $this->pdo->beginTransaction();

        try {
            // Lock book row
            $book = $this->bookRepo->findByIdForUpdate($id);

            if (!$book) {
                throw new Exception('ไม่พบหนังสือที่ต้องการลบ');
            }

            // [DATA INTEGRITY] ตรวจ 3 เงื่อนไขก่อนลบ — CASCADE DELETE จะทำลายข้อมูลที่เกี่ยวข้อง
            if ($this->isBeingBorrowed($id)) {
                throw new Exception('ไม่สามารถลบได้ หนังสือเล่มนี้กำลังถูกยืมอยู่');
            }

            if ($this->hasBorrowHistory($id)) {
                throw new Exception('ไม่สามารถลบได้ หนังสือเล่มนี้มีประวัติการยืม');
            }

            // pending reservation ถูกหัก stock ไปแล้ว — ลบจะทำให้ stock ไม่ถูกคืน
            if ($this->reservationRepo->countPendingByBook($id) > 0) {
                throw new Exception('ไม่สามารถลบได้ หนังสือเล่มนี้มีการจองที่รอดำเนินการอยู่');
            }

            // Delete book
            $this->bookRepo->delete($id);

            $this->pdo->commit();

            // [CLEANUP] ลบรูปหลัง DB commit สำเร็จ — ป้องกัน orphan file ถ้า DB ล้มเหลว
            if (!empty($book['cover_image'])) {
                $this->deleteCoverImage($book['cover_image']);
            }

            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * ตรวจสอบว่าหนังสือกำลังถูกยืมอยู่หรือไม่ (status='borrowing')
     * 
     * @param int $bookId ID หนังสือ
     * @return bool true = มีคนยืมอยู่
     */
    public function isBeingBorrowed(int $bookId): bool
    {
        return $this->borrowRepo->countActiveByBook($bookId) > 0;
    }

    /**
     * ตรวจสอบว่าหนังสือมีประวัติการยืมหรือไม่ (ทุก status รวมคืนแล้ว)
     * 
     * @param int $bookId ID หนังสือ
     * @return bool true = มีประวัติ (ไม่ควรลบ)
     */
    public function hasBorrowHistory(int $bookId): bool
    {
        return $this->borrowRepo->countByBook($bookId) > 0;
    }

    /**
     * ค้นหาหนังสือโดย ID หรือ ISBN (สำหรับ barcode scan)
     * 
     * @param string $identifier ID หรือ ISBN
     * @return array|null { id, title, author, available } หรือ null
     */
    public function findByIdOrIsbn(string $identifier): ?array
    {
        return $this->bookRepo->findByIdOrIsbn($identifier);
    }

    /**
     * ดึงสถิติหนังสือภาพรวม (สำหรับ dashboard)
     * 
     * @return array { total: int, available: int, borrowed: int, titles: int }
     */
    public function getStatistics(): array
    {
        return $this->bookRepo->getStatistics();
    }

    /**
     * ลบไฟล์รูปปกจาก disk (เรียกหลัง DB commit สำเร็จเท่านั้น)
     * 
     * @param string $filename ชื่อไฟล์ (ไม่รวม path)
     * @sideeffect ลบไฟล์จาก uploads/covers/
     */
    private function deleteCoverImage(string $filename): void
    {
        $coverPath = dirname(__DIR__, 2) . '/uploads/covers/' . $filename;
        if (file_exists($coverPath)) {
            unlink($coverPath);
        }
    }
}
