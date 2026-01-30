<?php
/**
 * BookService - Business Logic สำหรับการจัดการหนังสือ
 * 
 * @package App\Services
 */

namespace App\Services;

use PDO;
use Exception;

class BookService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ดึงรายการหนังสือทั้งหมด พร้อม filter
     */
    public function getBooks(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $where[] = "(b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        if (!empty($filters['category_id'])) {
            $where[] = "b.category_id = ?";
            $params[] = $filters['category_id'];
        }

        if (isset($filters['status'])) {
            if ($filters['status'] === 'available') {
                $where[] = "b.available > 0";
            } elseif ($filters['status'] === 'borrowed') {
                $where[] = "b.available < b.quantity";
            }
        }

        $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "
            SELECT b.*, c.name as category_name 
            FROM books b
            LEFT JOIN categories c ON b.category_id = c.id
            {$whereSQL}
            ORDER BY b.created_at DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * ดึงหนังสือตาม ID
     */
    public function getBookById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.*, c.name as category_name 
            FROM books b
            LEFT JOIN categories c ON b.category_id = c.id
            WHERE b.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * ดึงหนังสือที่ยังว่างอยู่
     */
    public function getAvailableBooks(): array
    {
        return $this->pdo->query("
            SELECT * FROM books WHERE available > 0 ORDER BY title
        ")->fetchAll();
    }

    /**
     * สร้างหนังสือใหม่
     */
    public function createBook(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO books (title, author, isbn, category_id, description, cover_image, quantity, available)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $quantity = $data['quantity'] ?? 1;

        $stmt->execute([
            $data['title'],
            $data['author'],
            $data['isbn'] ?? null,
            $data['category_id'] ?? null,
            $data['description'] ?? null,
            $data['cover_image'] ?? null,
            $quantity,
            $quantity // available = quantity for new books
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * อัปเดตหนังสือ
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
        $quantityDiff = $newQuantity - $oldQuantity;
        $newAvailable = max(0, $book['available'] + $quantityDiff);

        $stmt = $this->pdo->prepare("
            UPDATE books SET 
                title = ?, author = ?, isbn = ?, category_id = ?, 
                description = ?, cover_image = COALESCE(?, cover_image), 
                quantity = ?, available = ?
            WHERE id = ?
        ");

        return $stmt->execute([
            $data['title'],
            $data['author'],
            $data['isbn'] ?? null,
            $data['category_id'] ?? null,
            $data['description'] ?? null,
            $data['cover_image'] ?? null,
            $newQuantity,
            $newAvailable,
            $id
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
            $stmt = $this->pdo->prepare("SELECT available, quantity, cover_image FROM books WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $book = $stmt->fetch();

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

            // Delete book
            $stmt = $this->pdo->prepare("DELETE FROM books WHERE id = ?");
            $stmt->execute([$id]);

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
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM borrows WHERE book_id = ? AND status = 'borrowing'");
        $stmt->execute([$bookId]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * ตรวจสอบว่าหนังสือมีประวัติการยืมหรือไม่
     */
    public function hasBorrowHistory(int $bookId): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM borrows WHERE book_id = ?");
        $stmt->execute([$bookId]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * ค้นหาหนังสือโดย ID หรือ ISBN (สำหรับ barcode scan)
     */
    public function findByIdOrIsbn(string $identifier): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id, title, author, available FROM books WHERE id = ? OR isbn = ?");
        $stmt->execute([$identifier, $identifier]);
        return $stmt->fetch() ?: null;
    }

    /**
     * ดึงสถิติหนังสือ
     */
    public function getStatistics(): array
    {
        return [
            'total' => (int) $this->pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM books")->fetchColumn(),
            'available' => (int) $this->pdo->query("SELECT COALESCE(SUM(available), 0) FROM books")->fetchColumn(),
            'borrowed' => (int) $this->pdo->query("SELECT COALESCE(SUM(quantity - available), 0) FROM books")->fetchColumn(),
            'titles' => (int) $this->pdo->query("SELECT COUNT(*) FROM books")->fetchColumn(),
        ];
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
