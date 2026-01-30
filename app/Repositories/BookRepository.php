<?php
/**
 * BookRepository - Database Access สำหรับหนังสือ
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
     * ดึงหนังสือทั้งหมด
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

        if (isset($filters['available_only']) && $filters['available_only']) {
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
     * ดึงหนังสือตาม ID หรือ ISBN
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
     * สร้างหนังสือใหม่
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
     * อัปเดตหนังสือ
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
     * ลบหนังสือ
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM books WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * อัปเดต available count
     */
    public function updateAvailable(int $id, int $change): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE books SET available = available + ? WHERE id = ?
        ");
        return $stmt->execute([$change, $id]);
    }

    /**
     * Lock row สำหรับ transaction
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
     * นับจำนวนหนังสือ
     */
    public function count(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
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
}
