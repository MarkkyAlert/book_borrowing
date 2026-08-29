<?php
/**
 * CategoryRepository - Database Access สำหรับหมวดหมู่
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * Repository นี้จัดการ CRUD สำหรับตาราง categories (หมวดหมู่หนังสือ)
 *
 * 📚 โครงสร้างตาราง categories:
 * +--------+--------------+-------------------------+
 * | Column | Type         | อธิบาย                    |
 * +--------+--------------+-------------------------+
 * | id     | INT AUTO PK  | Primary Key             |
 * | name   | VARCHAR UNQ  | ชื่อหมวดหมู่ (unique) |
 * +--------+--------------+-------------------------+
 * ความสัมพันธ์: books.category_id → FK ไปที่ categories.id
 *
 * 📍 Entrypoints:
 * - admin/categories.php → findAllWithBookCount(), create(), update(), delete(),
 *                          nameExists(), hasBooks(), findById()
 * - admin/book_form.php  → findAll() (แสดง dropdown)
 * - admin/import_books.php → findByName(), create() (สร้างหมวดหมู่ใหม่จาก CSV)
 * - index.php (public)   → findAll() (แสดง sidebar filter)
 * - admin/index.php      → getStatistics() (แสดง chart)
 *
 * 🏗️ สถาปัตยกรรม:
 * Controller → Repository โดยตรง (ไม่ผ่าน Service)
 * เพราะ category เป็น CRUD ง่ายๆ ไม่มี business logic ซับซ้อน
 *
 * @package App\Repositories
 */

namespace App\Repositories;

use PDO;

class CategoryRepository
{
    // 🗄️ PDO connection — inject ผ่าน constructor ใช้ร่วมกันทุกเมธอด
    private PDO $pdo;

    // 🏗️ Constructor: รับ PDO จากภายนอก (Dependency Injection)
    // → ใช้ connection เดียวกับ Controller ที่เรียก
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงหมวดหมู่ทั้งหมด (เรียงตามชื่อ)
     * ==========================================================================
     *
     * 🔄 Flow: SELECT * FROM categories ORDER BY name
     *
     * 📤 Output:
     * @return array รายการหมวดหมู่ [{id, name}, ...]
     *         - ใช้ใน: dropdown เลือกหมวดหมู่ (book_form), sidebar filter (index.php)
     *
     * ⚠️ Edge case: ไม่มีหมวดหมู่ → คืน []
     * ✅ Use case: admin/book_form.php → แสดง <select> หมวดหมู่
     */
    public function findAll(): array
    {
        // 📝 SQL: ดึงหมวดหมู่ทั้งหมดเรียงตามชื่อ A-Z / ก-ฮ
        // 🧠 ใช้ query() เพราะไม่มี user input (ปลอดภัย)
        // ⚠️ ถ้าเพิ่ม parameter ในอนาคต ต้องเปลี่ยนเป็น prepare()
        return $this->pdo->query("
            SELECT * FROM categories ORDER BY name, id
        ")->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงหมวดหมู่พร้อมจำนวนหนังสือในแต่ละหมวด
     * ==========================================================================
     *
     * 🔄 Flow: SELECT c.*, COUNT(b.id) FROM categories LEFT JOIN books GROUP BY c.id
     *
     * 📤 Output:
     * @return array [{id, name, book_count}, ...]
     *         - ใช้ใน: admin/categories.php แสดงตารางหมวดหมู่ + จำนวนหนังสือ
     *
     * 🧠 เหตุผลเชิงออกแบบ:
     * - LEFT JOIN ไม่ใช่ INNER JOIN เพราะหมวดหมู่ที่ไม่มีหนังสือก็ต้องแสดง (book_count=0)
     * - GROUP BY c.id, c.name เพื่อ MySQL strict mode compatibility
     *
     * ✅ Use case: admin/categories.php GET → แสดงตารางหมวดหมู่พร้อมจำนวนหนังสือ
     */
    public function findAllWithBookCount(): array
    {
        // 📝 SQL: ดึงหมวดหมู่ + นับจำนวนหนังสือในแต่ละหมวด
        // 🧠 LEFT JOIN = หมวดหมู่ที่ไม่มีหนังสือก็แสดง (book_count = 0)
        //    ถ้าใช้ INNER JOIN → หมวดที่ไม่มีหนังสือจะหายไป
        // 📌 GROUP BY c.id, c.name = MySQL strict mode ต้องระบุทุก column ที่ไม่ได้ aggregate
        // 📤 คืน [{id, name, book_count}, ...] เรียงตามชื่อ
        return $this->pdo->query("
            SELECT c.*, COUNT(b.id) as book_count
            FROM categories c
            LEFT JOIN books b ON c.id = b.category_id
            GROUP BY c.id, c.name
            ORDER BY c.name, c.id ASC
        ")->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงหมวดหมู่ตาม ID
     * ==========================================================================
     *
     * 📥 Input:
     * @param int $id Category ID - มาจาก admin/categories.php?edit=ID
     *
     * 📤 Output:
     * @return array|null {id, name} หรือ null
     *         - ใช้ใน: admin/categories.php โหลดข้อมูลเข้า form แก้ไข
     *
     * ⚠️ Edge case: id ไม่มีอยู่ → null
     * ✅ Use case: admin/categories.php?edit=5 → โหลดชื่อเดิมเข้า form
     */
    public function findById(int $id): ?array
    {
        // 📝 SQL: ดึงหมวดหมู่ตาม ID — ใช้ prepared statement ป้องกัน SQL Injection
        $stmt = $this->pdo->prepare("SELECT * FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        // 📤 คืน {id, name} หรือ null ถ้าไม่เจอ
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงหมวดหมู่ตามชื่อ (exact match)
     * ==========================================================================
     *
     * 📥 Input:
     * @param string $name ชื่อหมวดหมู่ เช่น "Fantasy"
     *                     - มาจาก: admin/import_books.php (ค้นหาหมวดหมู่จาก CSV)
     *
     * 📤 Output:
     * @return array|null {id, name} หรือ null
     *         - ใช้ใน: import_books.php — ถ้าเจอใช้ id, ถ้าไม่เจอสร้างใหม่
     *
     * ✅ Use case: import_books.php → findByName('Fantasy') → ได้ id → ใช้เป็น category_id
     */
    public function findByName(string $name): ?array
    {
        // 📝 SQL: ค้นหา exact match ชื่อหมวดหมู่
        // 🧠 ใช้ตอน import CSV → ถ้าเจอใช้ id เดิม ถ้าไม่เจอสร้างใหม่
        $stmt = $this->pdo->prepare("SELECT * FROM categories WHERE name = ?");
        $stmt->execute([$name]);
        // 📤 คืน {id, name} หรือ null ถ้าไม่มีชื่อนี้
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สร้างหมวดหมู่ใหม่
     * ==========================================================================
     *
     * 📥 Input:
     * @param string $name ชื่อหมวดหมู่ - มาจาก admin/categories.php, import_books.php
     *
     * 📤 Output:
     * @return int ID ของหมวดหมู่ที่สร้าง
     *
     * 🛡️ Security: prepared statement
     * ⚠️ Edge case: ชื่อซ้ำ → UNIQUE constraint violation → PDOException
     *    Controller ต้องเช็ค nameExists() ก่อนเรียก
     *
     * ✅ Use case:
     * 1) admin/categories.php POST action=add
     * 2) import_books.php → ถ้าหมวดหมู่ไม่มี → สร้างใหม่อัตโนมัติ
     */
    public function create(string $name): int
    {
        // 📝 SQL: INSERT หมวดหมู่ใหม่ (มีแค่ field เดียวคือ name)
        // ⚠️ ถ้าชื่อซ้ำ → UNIQUE constraint → PDOException
        //    Controller ต้องเช็ค nameExists() ก่อนเรียก
        $stmt = $this->pdo->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->execute([$name]);
        // 📤 คืน ID ที่สร้าง (AUTO_INCREMENT)
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: อัปเดตชื่อหมวดหมู่
     * ==========================================================================
     *
     * 📥 Input:
     * @param int $id Category ID
     * @param string $name ชื่อใหม่ - มาจาก admin/categories.php POST action=edit
     *
     * 📤 Output:
     * @return bool true = สำเร็จ
     *
     * ⚠️ Edge case: ชื่อซ้ำกับหมวดหมู่อื่น → UNIQUE violation
     *    Controller ต้องเช็ค nameExists($name, $excludeId) ก่อน
     *
     * ✅ Use case: admin/categories.php POST action=edit
     */
    public function update(int $id, string $name): bool
    {
        // 📝 SQL: เปลี่ยนชื่อหมวดหมู่
        // ⚠️ ถ้าชื่อซ้ำกับหมวดอื่น → UNIQUE violation
        //    Controller ต้องเช็ค nameExists($name, $excludeId) ก่อน
        $stmt = $this->pdo->prepare("UPDATE categories SET name = ? WHERE id = ?");
        // 📌 ลำดับ bind: [$name, $id] ตรงกับ ? ใน SQL (SET name=? WHERE id=?)
        return $stmt->execute([$name, $id]);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ลบหมวดหมู่
     * ==========================================================================
     *
     * 📥 Input:
     * @param int $id Category ID - มาจาก admin/categories.php POST action=delete
     *
     * 📤 Output:
     * @return bool true = สำเร็จ
     *
     * ⚠️ Edge cases ที่สำคัญ:
     * - ถ้ามีหนังสือในหมวดหมู่ → FK constraint error (PDOException)
     *   Controller ต้องเช็ค hasBooks() ก่อนเรียก delete()
     * - id ไม่มีอยู่ → DELETE 0 rows แต่ไม่ error
     *
     * ✅ Use case: admin/categories.php POST action=delete
     *   ก่อนเรียก: hasBooks() → false → delete()
     */
    public function delete(int $id): bool
    {
        // 📝 SQL: ลบหมวดหมู่ตาม ID
        // ⚠️ ถ้ามีหนังสือในหมวดนี้ → FK constraint → PDOException
        //    Controller ต้องเช็ค hasBooks() ก่อนเรียก delete()
        // 🛡️ ควรเรียกลำดับ: hasBooks() → false → delete()
        $stmt = $this->pdo->prepare("DELETE FROM categories WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ตรวจสอบว่าชื่อซ้ำหรือไม่
     * ==========================================================================
     * ใช้ตรวจก่อน create/update เพื่อป้องกันชื่อซ้ำ
     *
     * 📥 Input:
     * @param string   $name      ชื่อที่ต้องการตรวจ
     * @param int|null $excludeId ID ที่ยกเว้น (ใช้ตอน update — ไม่นับตัวเอง)
     *
     * 📤 Output:
     * @return bool true = ชื่อซ้ำแล้ว (ห้ามใช้)
     *
     * 🧠 เหตุผลเชิงออกแบบ:
     * - ทำไมมี $excludeId? เพราะตอน update ชื่อเดิมยังอยู่ — ไม่ถือว่าซ้ำ
     *   ถ้าไม่ยกเว้น → update ชื่อเดิมจะ error "ชื่อซ้ำ" ทั้งที่ไม่ได้แก้อะไร
     *
     * ✅ Use case:
     * 1) สร้าง: nameExists('Fiction')      → true = ห้ามสร้าง
     * 2) แก้ไข: nameExists('Fiction', 5) → false = ไม่ซ้ำ (เพราะ id=5 คือตัวเอง)
     */
    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        // 📝 SQL เริ่มต้น: นับจำนวนหมวดหมู่ที่ชื่อตรงกัน
        $sql = "SELECT COUNT(*) FROM categories WHERE name = ?";
        $params = [$name];

        // 🧠 $excludeId = ยกเว้น ID ตัวเอง (ใช้ตอน update)
        //    เช่น แก้ไขหมวด ID=5 ชื่อ "Fiction" → ต้องยกเว้น ID=5
        //    ไม่งั้นจะบอก "ชื่อซ้ำ" ทั้งที่เป็นชื่อเดิมของตัวเอง
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        // 📤 > 0 = ชื่อซ้ำ (true), = 0 = ใช้ได้ (false)
        return $stmt->fetchColumn() > 0;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ตรวจว่าหมวดหมู่มีหนังสือหรือไม่ (guard ก่อนลบ)
     * ==========================================================================
     * ใช้เป็น "guard" ก่อนลบหมวดหมู่ — ถ้ายังมีหนังสืออยู่ ห้ามลบ
     *
     * 📥 Input:
     * @param int $categoryId Category ID
     *
     * 📤 Output:
     * @return bool true = มีหนังสือ (ไม่ควรลบ), false = ลบได้
     *
     * 🧠 เหตุผลเชิงออกแบบ:
     * - ทำไมไม่ใช้ FK constraint โดยตรง? เพราะต้องการแสดง error message ที่อ่านรู้เรื่องให้ user
     *   ไม่ใช่แค่ดัก PDOException
     *
     * ✅ Use case: admin/categories.php POST action=delete:
     *   if (hasBooks($id)) { error('ลบไม่ได้ มีหนังสืออยู่'); }
     */
    public function hasBooks(int $categoryId): bool
    {
        // 📝 SQL: นับหนังสือที่อยู่ในหมวดหมู่นี้
        // 🧠 ใช้เป็น "guard" ก่อนลบ — ถ้ามีหนังสือ → ห้ามลบ (แจ้ง error อ่านรู้เรื่อง)
        //    ดีกว่าปล่อยให้ FK constraint error (PDOException ไม่สื่อ)
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM books WHERE category_id = ?");
        $stmt->execute([$categoryId]);
        // 📤 > 0 = มีหนังสือ (ห้ามลบ), = 0 = ลบได้
        return $stmt->fetchColumn() > 0;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับจำนวนหมวดหมู่ทั้งหมด
     * ==========================================================================
     *
     * 📤 Output:
     * @return int จำนวนหมวดหมู่
     *         - ใช้ใน: DashboardService แสดงสถิติบน dashboard
     *
     * ✅ Use case: admin/index.php → DashboardService → count()
     */
    public function count(): int
    {
        // 📝 SQL: นับจำนวนหมวดหมู่ทั้งหมด
        // 🧠 ใช้ query() เพราะไม่มี user input
        return (int) $this->pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงสถิติหมวดหมู่ (Top N ที่ยืมเยอะที่สุด สำหรับ chart)
     * ==========================================================================
     *
     * 🔄 Flow: categories LEFT JOIN books LEFT JOIN borrows → GROUP BY → ORDER BY borrow_count DESC
     *
     * 📥 Input:
     * @param int $limit จำนวนหมวดหมู่สูงสุด (default: 6)
     *                   - มาจาก: DashboardService
     *
     * 📤 Output:
     * @return array [{name, borrow_count}, ...] เรียงจากยืมเยอะมากสุด
     *         - ใช้ใน: admin dashboard chart (กราฟวงกลมหมวดหมู่ยอดนิยม)
     *
     * 🧠 เหตุผลเชิงออกแบบ:
     * - LEFT JOIN 2 ชั้น เพราะหมวดหมู่ที่ไม่มีหนังสือ/การยืมก็ต้องแสดง (count=0)
     *
     * ✅ Use case: admin/index.php → DashboardService → getStatistics(6)
     */
    public function getStatistics(int $limit = 6): array
    {
        // 📝 SQL: ดึง Top N หมวดหมู่ที่ถูกยืมเยอะสุด
        // 🧠 LEFT JOIN 2 ชั้น:
        //    categories → books → borrows
        //    LEFT JOIN เพราะหมวดที่ไม่มีหนังสือ/การยืมก็ต้องแสดง (count=0)
        // GROUP BY c.id, c.name → MySQL strict mode
        // ORDER BY borrow_count DESC → ยืมเยอะสุดขึ้นก่อน
        // LIMIT ? → จำกัดจำนวน (default: 6 สำหรับ pie chart)
        $stmt = $this->pdo->prepare("
            SELECT c.name, COUNT(br.id) as borrow_count
            FROM categories c
            LEFT JOIN books b ON c.id = b.category_id
            LEFT JOIN borrows br ON b.id = br.book_id
            GROUP BY c.id, c.name
            ORDER BY borrow_count DESC, c.id ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        // 📤 คืน [{name, borrow_count}, ...] → ใช้วาด chart ใน Dashboard
        return $stmt->fetchAll();
    }
}
