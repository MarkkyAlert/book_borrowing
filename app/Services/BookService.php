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
        // แปลง filters สำหรับ repository
        $repoFilters = [];

        if (!empty($filters['search'])) {
            $repoFilters['search'] = $filters['search'];
        }

        if (!empty($filters['category_id'])) {
            $repoFilters['category_id'] = $filters['category_id'];
        }

        // Status filter - ใช้ repository findAll แล้วกรอง
        // หรือสร้าง method ใหม่ใน repo ถ้าต้องการ performance ดีกว่า
        $books = $this->bookRepo->findAll($repoFilters);

        if (!empty($filters['status'])) {
            $status = $filters['status'];
            $books = array_filter($books, function ($book) use ($status) {
                if ($status === 'available') {
                    return $book['available'] > 0;
                } elseif ($status === 'borrowed') {
                    return $book['available'] < $book['quantity'];
                } elseif ($status === 'out_of_stock') {
                    return $book['available'] === 0;
                } elseif ($status === 'low_stock') {
                    return $book['available'] > 0 && $book['available'] <= 2;
                }
                return true;
            });
        }

        // Sort
        $sort = $filters['sort'] ?? 'newest';
        usort($books, function ($a, $b) use ($sort) {
            switch ($sort) {
                case 'oldest':
                    return strtotime($a['created_at']) - strtotime($b['created_at']);
                case 'az':
                    return strcmp($a['title'], $b['title']);
                default: // newest
                    return strtotime($b['created_at']) - strtotime($a['created_at']);
            }
        });

        return array_values($books);
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
     * ดึงหนังสือที่ยังว่างอยู่
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
        
        // [FIX] ห้ามลด quantity ต่ำกว่าจำนวนที่ออกอยู่ (ยืม+จอง)
        $currentlyOut = $oldQuantity - $book['available']; // จำนวนที่ออกอยู่
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
     * ลบหนังสือ
     * 
     * @throws Exception ถ้าหนังสือกำลังถูกยืมหรือมีประวัติการยืม
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

            // Check if being borrowed
            if ($this->isBeingBorrowed($id)) {
                throw new Exception('ไม่สามารถลบได้ หนังสือเล่มนี้กำลังถูกยืมอยู่');
            }

            // Check if has borrow history
            if ($this->hasBorrowHistory($id)) {
                throw new Exception('ไม่สามารถลบได้ หนังสือเล่มนี้มีประวัติการยืม');
            }

            // Check if has pending reservations
            if ($this->reservationRepo->countPendingByBook($id) > 0) {
                throw new Exception('ไม่สามารถลบได้ หนังสือเล่มนี้มีการจองที่รอดำเนินการอยู่');
            }

            // Delete book
            $this->bookRepo->delete($id);

            $this->pdo->commit();

            // Delete cover image file if exists
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
     * ตรวจสอบว่าหนังสือกำลังถูกยืมอยู่หรือไม่
     */
    public function isBeingBorrowed(int $bookId): bool
    {
        return $this->borrowRepo->countActiveByBook($bookId) > 0;
    }

    /**
     * ตรวจสอบว่าหนังสือมีประวัติการยืมหรือไม่
     */
    public function hasBorrowHistory(int $bookId): bool
    {
        return $this->borrowRepo->countByBook($bookId) > 0;
    }

    /**
     * ค้นหาหนังสือโดย ID หรือ ISBN (สำหรับ barcode scan)
     */
    public function findByIdOrIsbn(string $identifier): ?array
    {
        return $this->bookRepo->findByIdOrIsbn($identifier);
    }

    /**
     * ดึงสถิติหนังสือ
     */
    public function getStatistics(): array
    {
        return $this->bookRepo->getStatistics();
    }

    /**
     * ลบไฟล์รูปปก
     */
    private function deleteCoverImage(string $filename): void
    {
        $coverPath = dirname(__DIR__, 2) . '/uploads/covers/' . $filename;
        if (file_exists($coverPath)) {
            unlink($coverPath);
        }
    }
}
