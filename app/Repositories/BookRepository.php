<?php
/**
 * BookRepository - Data Access Layer สำหรับหนังสือ
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - Repository นี้จัดการ CRUD สำหรับตาราง books
 * - ไม่มี business logic - เป็นแค่ data access
 * - ห้ามเรียกจากหน้าเว็บโดยตรง ให้เรียกผ่าน BookService
 * 
 * 📌 Methods สำคัญ:
 * - findByIdForUpdate()    → SELECT ... FOR UPDATE (ยืม/จอง)
 * - decrementAvailable()   → ลด stock (atomic, ป้องกันติดลบ)
 * - incrementAvailable()   → เพิ่ม stock (คืน/ยกเลิก)
 * 
 * ⚠️ ห้ามแก้:
 * - decrementAvailable() มี WHERE available > 0 ป้องกันติดลบ
 * - findByIdForUpdate() มี lock ที่สำคัญ
 * 
 * @package App\Repositories
 */

namespace App\Repositories;

use PDO;

class BookRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ดึงหนังสือทั้งหมดตาม filters ที่กำหนด
     * 
     * @param array $filters {
     *     search?: string,        // ค้นหาใน title, author, isbn
     *     category_id?: int,      // กรองตามหมวดหมู่
     *     available_only?: bool   // true = เฉพาะที่มี stock
     * }
     * @return array รายการหนังสือ (รวม category_name)
     */
    public function findAll(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $where[] = "(b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)";
            $params = array_merge($params, ["%{$search}%", "%{$search}%", "%{$search}%"]);
        }

        if (!empty($filters['category_id'])) {
            $where[] = "b.category_id = ?";
            $params[] = $filters['category_id'];
        }

        // Support both 'available_only' and 'available' filter keys
        if ((isset($filters['available_only']) && $filters['available_only']) 
            || (isset($filters['available']) && $filters['available'])) {
            $where[] = "b.available > 0";
        }

        $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->pdo->prepare("
            SELECT b.*, c.name as category_name 
            FROM books b
            LEFT JOIN categories c ON b.category_id = c.id
            {$whereSQL}
            ORDER BY b.created_at DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * ดึงหนังสือตาม ID
     * 
     * @param int $id ID หนังสือ
     * @return array|null ข้อมูลหนังสือ (รวม category_name) หรือ null ถ้าไม่พบ
     */
    public function findById(int $id): ?array
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
     * ดึงหนังสือตาม ID หรือ ISBN (สำหรับ barcode scan)
     * 
     * @param string $identifier ID หรือ ISBN
     * @return array|null ข้อมูลพื้นฐาน (id, title, author, available)
     */
    public function findByIdOrIsbn(string $identifier): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, title, author, available 
            FROM books WHERE id = ? OR isbn = ?
        ");
        $stmt->execute([$identifier, $identifier]);
        return $stmt->fetch() ?: null;
    }

    /**
     * ดึงหนังสือที่ยังว่างอยู่
     */
    public function findAvailable(): array
    {
        return $this->pdo->query("
            SELECT * FROM books WHERE available > 0 ORDER BY title
        ")->fetchAll();
    }

    /**
     * ดึงหนังสือทั้งหมดสำหรับพิมพ์ barcode labels
     */
    public function findAllForLabels(): array
    {
        return $this->pdo->query("
            SELECT id, title, isbn FROM books ORDER BY id DESC
        ")->fetchAll();
    }

    /**
     * ค้นหาหนังสือตามชื่อและผู้แต่ง (สำหรับ import)
     */
    public function findByTitleAndAuthor(string $title, string $author): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id FROM books WHERE title = ? AND author = ?");
        $stmt->execute([$title, $author]);
        return $stmt->fetch() ?: null;
    }

    /**
     * เพิ่มจำนวน quantity และ available (สำหรับ import)
     */
    public function addQuantity(int $id, int $quantity): bool
    {
        $stmt = $this->pdo->prepare("UPDATE books SET quantity = quantity + ?, available = available + ? WHERE id = ?");
        return $stmt->execute([$quantity, $quantity, $id]);
    }

    /**
     * สร้างหนังสือใหม่
     * 
     * @param array $data {
     *     title: string,          // ชื่อหนังสือ (required)
     *     author: string,         // ผู้แต่ง (required)
     *     isbn?: string,          // ISBN
     *     category_id?: int,      // หมวดหมู่
     *     description?: string,   // รายละเอียด
     *     cover_image?: string,   // ชื่อไฟล์รูปปก
     *     quantity?: int          // จำนวนเล่ม (default: 1)
     * }
     * @return int ID ของหนังสือที่สร้าง
     * 
     * @sideeffect INSERT ลง books table (available = quantity)
     */
    public function create(array $data): int
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
            $quantity
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * อัปเดตข้อมูลหนังสือ
     * 
     * @param int   $id   ID หนังสือ
     * @param array $data ข้อมูลที่ต้องการอัปเดต (ต้องส่งครบทุก field)
     * @return bool true = สำเร็จ
     * 
     * @note cover_image จะอัปเดตเฉพาะเมื่อส่งค่ามา (ใช้ COALESCE)
     */
    public function update(int $id, array $data): bool
    {
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
            $data['quantity'],
            $data['available'],
            $id
        ]);
    }

    /**
     * ตรวจสอบว่า ISBN ซ้ำหรือไม่
     * 
     * @param string $isbn ISBN ที่ต้องการตรวจ
     * @param int|null $excludeId ID หนังสือที่ต้องการยกเว้น (สำหรับ edit mode)
     * @return bool true = ISBN ซ้ำ
     */
    public function isbnExists(string $isbn, ?int $excludeId = null): bool
    {
        $sql = "SELECT id FROM books WHERE isbn = ?";
        $params = [$isbn];
        
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() !== false;
    }

    /**
     * ลบหนังสือ
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM books WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * เพิ่ม/ลด จำนวนหนังสือที่ว่าง (available)
     * 
     * @param int $id     ID หนังสือ
     * @param int $change จำนวนที่เปลี่ยน (+1 = คืน, -1 = ยืม)
     * @return bool true = สำเร็จ
     * 
     * @note ใช้ SQL: available = available + change
     */
    public function updateAvailable(int $id, int $change): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE books SET available = available + ? WHERE id = ?
        ");
        return $stmt->execute([$change, $id]);
    }

    /**
     * ดึงหนังสือพร้อม lock row (สำหรับใช้ใน transaction)
     * 
     * @param int $id ID หนังสือ
     * @return array|null ข้อมูลหนังสือ (ถูก lock จน commit/rollback)
     * 
     * @security ใช้ FOR UPDATE ป้องกัน concurrent access
     * @note ต้องเรียกภายใน transaction เท่านั้น
     */
    public function findByIdForUpdate(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM books WHERE id = ? FOR UPDATE
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * เพิ่ม available + 1 (คืนหนังสือ)
     * 
     * @return bool true = สำเร็จ, false = available เท่ากับ quantity แล้ว
     * @security ใช้ conditional update ป้องกัน available เกิน quantity
     */
    public function incrementAvailable(int $id): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE books SET available = available + 1 
            WHERE id = ? AND available < quantity
        ");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * ลด available - 1 (ยืมหนังสือ)
     * 
     * @return bool true = สำเร็จ, false = stock ไม่พอ (available = 0)
     * @security ใช้ conditional update ป้องกัน available ติดลบ
     */
    public function decrementAvailable(int $id): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE books SET available = available - 1 
            WHERE id = ? AND available > 0
        ");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * นับจำนวนหนังสือ
     */
    public function count(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
    }

    /**
     * ดึงหนังสือที่ใกล้หมด stock
     * 
     * @param int $threshold จำนวน available ที่ถือว่า "ใกล้หมด" (default: 2)
     * @param int $limit จำนวนรายการที่ต้องการ
     * @return array รายการหนังสือที่ available <= threshold
     */
    public function findLowStock(int $threshold = 2, int $limit = 5): array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.id, b.title, b.author, b.quantity, b.available, c.name as category_name
            FROM books b
            LEFT JOIN categories c ON b.category_id = c.id
            WHERE b.available <= ? AND b.quantity > 0
            ORDER BY b.available ASC, b.title ASC
            LIMIT ?
        ");
        $stmt->execute([$threshold, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * ดึงสถิติหนังสือ (สำหรับ dashboard)
     * 
     * @return array {
     *     total: int,      // จำนวนเล่มทั้งหมด (SUM quantity)
     *     available: int,  // จำนวนเล่มที่ว่าง (SUM available)
     *     borrowed: int,   // จำนวนเล่มที่ถูกยืม (total - available)
     *     titles: int      // จำนวนรายการหนังสือ (COUNT rows)
     * }
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
}
