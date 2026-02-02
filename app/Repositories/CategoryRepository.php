<?php
/**
 * CategoryRepository - Database Access สำหรับหมวดหมู่
 * 
 * @package App\Repositories
 */

namespace App\Repositories;

use PDO;

class CategoryRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ดึงหมวดหมู่ทั้งหมด
     * 
     * @return array รายการหมวดหมู่ เรียงตามชื่อ
     */
    public function findAll(): array
    {
        return $this->pdo->query("
            SELECT * FROM categories ORDER BY name
        ")->fetchAll();
    }

    /**
     * ดึงหมวดหมู่พร้อมจำนวนหนังสือ
     * 
     * @return array รายการหมวดหมู่ พร้อม book_count
     */
    public function findAllWithBookCount(): array
    {
        return $this->pdo->query("
            SELECT c.*, COUNT(b.id) as book_count
            FROM categories c
            LEFT JOIN books b ON c.id = b.category_id
            GROUP BY c.id, c.name
            ORDER BY c.name
        ")->fetchAll();
    }

    /**
     * ดึงหมวดหมู่ตาม ID
     * 
     * @param int $id Category ID
     * @return array|null ข้อมูลหมวดหมู่ หรือ null ถ้าไม่พบ
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * ดึงหมวดหมู่ตามชื่อ
     * 
     * @param string $name ชื่อหมวดหมู่ (exact match)
     * @return array|null ข้อมูลหมวดหมู่ หรือ null ถ้าไม่พบ
     */
    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM categories WHERE name = ?");
        $stmt->execute([$name]);
        return $stmt->fetch() ?: null;
    }

    /**
     * สร้างหมวดหมู่ใหม่
     * 
     * @param string $name ชื่อหมวดหมู่
     * @return int ID ของหมวดหมู่ที่สร้าง
     * @sideeffect INSERT into categories table
     */
    public function create(string $name): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->execute([$name]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * อัปเดตหมวดหมู่
     * 
     * @param int $id Category ID
     * @param string $name ชื่อใหม่
     * @return bool true = สำเร็จ
     * @sideeffect UPDATE categories table
     */
    public function update(int $id, string $name): bool
    {
        $stmt = $this->pdo->prepare("UPDATE categories SET name = ? WHERE id = ?");
        return $stmt->execute([$name, $id]);
    }

    /**
     * ลบหมวดหมู่
     * 
     * @param int $id Category ID
     * @return bool true = สำเร็จ
     * @sideeffect DELETE from categories table
     * @throws PDOException ถ้ามีหนังสือในหมวดหมู่ (FK constraint)
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM categories WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * ตรวจสอบว่าชื่อซ้ำหรือไม่
     * 
     * @param string $name ชื่อที่ต้องการตรวจสอบ
     * @param int|null $excludeId ID ที่ต้องการยกเว้น (ใช้ตอน update)
     * @return bool true = มีอยู่แล้ว (ห้ามใช้)
     */
    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM categories WHERE name = ?";
        $params = [$name];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * ตรวจสอบว่ามีหนังสือในหมวดหมู่หรือไม่
     * 
     * @param int $categoryId Category ID
     * @return bool true = มีหนังสือ (ไม่ควรลบ)
     */
    public function hasBooks(int $categoryId): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM books WHERE category_id = ?");
        $stmt->execute([$categoryId]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * นับจำนวนหมวดหมู่
     * 
     * @return int จำนวนหมวดหมู่ทั้งหมด
     */
    public function count(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    }

    /**
     * ดึงสถิติหมวดหมู่ (สำหรับ chart) - เรียงตามจำนวนการยืม
     */
    public function getStatistics(int $limit = 6): array
    {
        $stmt = $this->pdo->prepare("
            SELECT c.name, COUNT(br.id) as borrow_count
            FROM categories c
            LEFT JOIN books b ON c.id = b.category_id
            LEFT JOIN borrows br ON b.id = br.book_id
            GROUP BY c.id, c.name
            ORDER BY borrow_count DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
}
