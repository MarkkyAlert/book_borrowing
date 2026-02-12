<?php
/**
 * BookRepository - Data Access Layer สำหรับหนังสือ
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * Repository นี้จัดการ CRUD + stock management สำหรับตาราง books
 * เป็นแค่ data access — business logic อยู่ใน BookService
 *
 * 📚 โครงสร้างตาราง books:
 * +--------------+--------------+----------------------------------------------+
 * | Column       | Type         | อธิบาย                                       |
 * +--------------+--------------+----------------------------------------------+
 * | id           | INT AUTO PK  | Primary Key                                  |
 * | title        | VARCHAR      | ชื่อหนังสือ                                  |
 * | author       | VARCHAR      | ผู้แต่ง                                      |
 * | isbn         | VARCHAR NULL | ISBN (ใช้สแกน barcode)                     |
 * | category_id  | INT FK NULL  | หมวดหมู่ → categories.id                  |
 * | description  | TEXT NULL    | รายละเอียด                                  |
 * | cover_image  | VARCHAR NULL | ชื่อไฟล์รูปปก                              |
 * | quantity     | INT          | จำนวนทั้งหมด                                |
 * | available    | INT          | จำนวนที่ว่างอยู่ (quantity - ถูกยืม)     |
 * | created_at   | DATETIME     | วันที่เพิ่ม                                  |
 * +--------------+--------------+----------------------------------------------+
 * สำคัญ: available ต้อง 0 ≤ available ≤ quantity เสมอ
 *
 * 📍 Entrypoints:
 * - BookService       → findById(), create(), update(), delete(), findByIdForUpdate(),
 *                       decrementAvailable(), incrementAvailable()
 * - BorrowService     → findByIdForUpdate(), decrementAvailable(), incrementAvailable()
 * - admin/books.php   → findAll() (แสดงรายการ)
 * - index.php         → findAll() (หน้าแรก)
 * - admin/book_labels  → findAllForLabels()
 * - admin/import_books → findByTitleAndAuthor(), create(), addQuantity()
 * - DashboardService  → count(), getStatistics(), findLowStock()
 *
 * 🛡️ Security Design:
 * - findByIdForUpdate() ใช้ FOR UPDATE (row lock) ป้องกัน race condition
 * - decrementAvailable() มี WHERE available > 0 ป้องกัน stock ติดลบ
 * - incrementAvailable() มี WHERE available < quantity ป้องกันเกิน stock
 *
 * ⚠️ ห้ามแก้:
 * - decrementAvailable() / incrementAvailable() ต้องเรียกใน transaction
 * - findByIdForUpdate() ต้องเรียกใน transaction
 *
 * @package App\Repositories
 *
 * ==========================================================================
 * 🗺️ Quick Map — เมธอดไหนใช้กับ flow ไหน
 * ==========================================================================
 *
 * 📝 CRUD หนังสือ (BookService):
 *   findById()              → ดึงหนังสือตาม ID
 *   create()                → สร้างหนังสือใหม่
 *   update()                → แก้ไขข้อมูลหนังสือ
 *   delete()                → ลบหนังสือ
 *   isbnExists()            → ตรวจ ISBN ซ้ำ
 *
 * 📖 ยืม/คืน (BorrowService):
 *   findByIdForUpdate()     → lock + ดึงหนังสือ
 *   decrementAvailable()    → ลด stock -1 (ยืม)
 *   incrementAvailable()    → เพิ่ม stock +1 (คืน)
 *
 * 📷 สแกน Barcode (admin/borrow_form.php):
 *   findByIdOrIsbn()        → ค้นหาด้วย ID หรือ ISBN
 *
 * 📝 แสดงรายการ (admin/books.php, index.php):
 *   findAll()               → ดึงทั้งหมด + filter + sort
 *   findAvailable()         → dropdown หนังสือที่ยังมี stock
 *
 * 📥 Import CSV (admin/import_books.php):
 *   findByTitleAndAuthor()  → ตรวจว่ามีอยู่แล้วหรือยัง
 *   addQuantity()           → เพิ่มจำนวนหนังสือที่มีอยู่แล้ว
 *
 * 🏷️ Barcode Labels (admin/book_labels.php):
 *   findAllForLabels()      → ดึง id + title + isbn สำหรับพิมพ์ label
 *
 * 📊 Dashboard (DashboardService):
 *   count()                 → จำนวนรายการหนังสือ
 *   getStatistics()         → สถิติภาพรวม (total/available/borrowed)
 *   findLowStock()          → หนังสือใกล้หมด stock
 *
 * 🔄 Stock Management (ReservationService):
 *   updateAvailable()       → เพิ่ม/ลด available โดยตรง (generic)
 *
 * ==========================================================================
 * ⚠️ Danger Zones — จุดเสี่ยงในไฟล์นี้
 * ==========================================================================
 *
 * 1. 🔴 findByIdForUpdate() ใช้ SELECT ... FOR UPDATE
 *    - ต้องเรียกใน transaction เท่านั้น ห้ามลบ FOR UPDATE!
 *
 * 2. 🔴 decrementAvailable() มี guard WHERE available > 0
 *    - ป้องกัน stock ติดลบ ระดับ DB ห้ามลบ guard!
 *
 * 3. 🔴 incrementAvailable() มี guard WHERE available < quantity
 *    - ป้องกัน available เกิน quantity ห้ามลบ guard!
 *
 * 4. 🟡 updateAvailable() ไม่มี guard
 *    - ถ้าส่งค่าผิด → available ติดลบหรือเกิน quantity ได้
 *    - แนะนำใช้ increment/decrement แทน
 *
 * 5. 🟡 findAll() ต่อ SQL string ด้วย $whereSQL + $orderBy
 *    - $orderBy ใช้ whitelist (match expression) → ปลอดภัย
 *    - $whereSQL ค่ามาจาก code ภายใน + user input bind ผ่าน ?
 *
 * 6. 🟢 หลายเมธอดใช้ query() ไม่ใช่ prepare()
 *    - findAvailable(), findAllForLabels(), count(), getStatistics()
 *    - ไม่มี user input → ปลอดภัย แต่ถ้าเพิ่ม parameter ต้องเปลี่ยน
 */

namespace App\Repositories;

use PDO;

class BookRepository
{
    // 🗄️ PDO connection — ใช้ร่วมกันทุกเมธอด (inject ผ่าน constructor)
    private PDO $pdo;

    // 🏗️ Constructor: รับ PDO จากภายนอก (Dependency Injection)
    // → ใช้ connection เดียวกับ BookService/BorrowService
    // → ทำให้ transaction + FOR UPDATE lock ทำงานได้ถูกต้อง
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงหนังสือทั้งหมดตาม filters (รวม category_name)
     * ==========================================================================
     * เมธอดหลักสำหรับแสดงรายการหนังสือทั้งหน้า admin และหน้า public
     *
     * 🔄 Flow: SELECT books LEFT JOIN categories + WHERE filters + ORDER BY sort
     *
     * 📥 Input:
     * @param array $filters {
     *     search?: string     — ค้นใน title, author, isbn (LIKE)
     *     category_id?: int   — กรองหมวดหมู่
     *     available_only?: bool — เฉพาะที่ยังมี stock
     *     status?: string     — 'available', 'out_of_stock', 'low_stock', 'borrowed'
     *     sort?: string       — 'newest' (default), 'oldest', 'az'
     * }
     *
     * 📤 Output:
     * @return array [{id, title, author, isbn, ..., category_name}, ...]
     *
     * 🧠 เหตุผลเชิงออกแบบ:
     * - LEFT JOIN categories เพราะหนังสือที่ไม่มีหมวดหมู่ก็ต้องแสดง
     * - status filter ใช้ match() expression (สะอาด อ่านง่าย)
     * - sort ใช้ whitelist — ไม่ใช้ user input ตรงใน ORDER BY
     *
     * 🛡️ Security: prepared statement + sort whitelist
     * ✅ Use case: admin/books.php, index.php (หน้าแรก)
     */
    public function findAll(array $filters = []): array
    {
        // 📦 เก็บเงื่อนไข WHERE แต่ละข้อไว้ใน array — จะนำมาประกอบเป็น SQL ทีหลัง
        $where = [];
        // 📦 เก็บค่า parameter สำหรับ bind ใน prepared statement (ป้องกัน SQL Injection)
        $params = [];

        // 🔍 Filter: ค้นหาคำ (search) — ค้นจากชื่อ, ผู้แต่ง, ISBN พร้อมกัน
        // ใช้ LIKE %...% = ค้นทุกตำแหน่งในข้อความ
        // ⚠️ ถ้า search = "PHP" → จะค้น title LIKE '%PHP%' OR author LIKE '%PHP%' OR isbn LIKE '%PHP%'
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $where[] = "(b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)";
            // 📌 ต้องใส่ 3 ค่า เพราะมี ? 3 ตัว (title, author, isbn)
            $params = array_merge($params, ["%{$search}%", "%{$search}%", "%{$search}%"]);
        }

        // 🏷️ Filter: หมวดหมู่ — กรองเฉพาะ category_id ที่เลือก
        if (!empty($filters['category_id'])) {
            $where[] = "b.category_id = ?";
            $params[] = $filters['category_id'];
        }

        // 📗 Filter: เฉพาะหนังสือที่มี stock (available > 0)
        // รองรับ 2 key เพราะหน้าต่างๆ ส่งมาคนละชื่อ
        // Support both 'available_only' and 'available' filter keys
        if ((isset($filters['available_only']) && $filters['available_only']) 
            || (isset($filters['available']) && $filters['available'])) {
            $where[] = "b.available > 0";
        }

        // 📊 Filter: สถานะหนังสือ — ใช้ match() expression (PHP 8.0+)
        // 🛡️ whitelist: เฉพาะค่าที่กำหนดเท่านั้น → default = ไม่ทำอะไร (ปลอดภัย)
        // Status filter
        if (!empty($filters['status'])) {
            match ($filters['status']) {
                'available'    => $where[] = "b.available > 0",           // ยังมี stock
                'out_of_stock' => $where[] = "b.available = 0",           // หมด stock
                'low_stock'    => $where[] = "b.available > 0 AND b.available <= 2", // ใกล้หมด
                'borrowed'     => $where[] = "b.available < b.quantity",  // มีคนยืมอยู่
                default        => null, // ค่าอื่น → ไม่เพิ่มเงื่อนไข (ปลอดภัย)
            };
        }

        // 🔗 ประกอบ WHERE clause จาก array → "WHERE cond1 AND cond2 AND ..."
        // ถ้าไม่มีเงื่อนไข → string ว่าง (= SELECT ทั้งหมด)
        $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // 🔃 Sort: เรียงลำดับ — ใช้ whitelist (match expression)
        // 🛡️ ป้องกัน SQL Injection: ไม่เอา user input ใส่ ORDER BY ตรงๆ
        // Sort
        $orderBy = match ($filters['sort'] ?? 'newest') {
            'oldest' => 'b.created_at ASC',   // เก่าสุดก่อน
            'az'     => 'b.title ASC',         // เรียง ก-ฮ / A-Z
            default  => 'b.created_at DESC',   // ใหม่สุดก่อน (default)
        };

        // 📝 SQL: SELECT หนังสือ + LEFT JOIN หมวดหมู่
        // LEFT JOIN = หนังสือที่ไม่มีหมวดหมู่ก็แสดง (category_name = null)
        // $whereSQL + $orderBy มาจาก whitelist → ปลอดภัย
        $stmt = $this->pdo->prepare("
            SELECT b.*, c.name as category_name 
            FROM books b
            LEFT JOIN categories c ON b.category_id = c.id
            {$whereSQL}
            ORDER BY {$orderBy}
        ");
        // 🚀 execute: ส่ง $params ไป bind กับ ? ใน WHERE clause
        $stmt->execute($params);
        // 📤 return: array ของหนังสือทั้งหมดที่ตรงเงื่อนไข
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงหนังสือตาม ID (รวม category_name)
     * ==========================================================================
     *
     * 📥 Input: @param int $id Book ID
     * 📤 Output: @return array|null ข้อมูลหนังสือ + category_name หรือ null
     * ✅ Use case: book.php (หน้ารายละเอียด), admin/book_form.php (โหลดข้อมูลแก้ไข)
     */
    public function findById(int $id): ?array
    {
        // 📝 SQL: ดึงข้อมูลหนังสือ + ชื่อหมวดหมู่
        // LEFT JOIN = ถ้าไม่มีหมวดหมู่ก็ยังคืนข้อมูลหนังสือ (category_name = null)
        // WHERE b.id = ? → ดึงเล่มเดียวตาม ID
        $stmt = $this->pdo->prepare("
            SELECT b.*, c.name as category_name 
            FROM books b
            LEFT JOIN categories c ON b.category_id = c.id
            WHERE b.id = ?
        ");
        // 🚀 bind $id กับ ? → ป้องกัน SQL Injection
        $stmt->execute([$id]);
        // 📤 fetch() = ดึง 1 แถว | ถ้าไม่เจอ → return null
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงหนังสือตาม ID หรือ ISBN (barcode scan)
     * ==========================================================================
     * รองรับทั้ง ID และ ISBN ใน query เดียว (OR)
     *
     * 📥 Input: @param string $identifier ID หรือ ISBN
     * 📤 Output: @return array|null {id, title, author, available}
     * ✅ Use case: admin/borrow_form.php → สแกน barcode หรือกรอก ID
     */
    public function findByIdOrIsbn(string $identifier): ?array
    {
        // 📝 SQL: ค้นด้วย ID หรือ ISBN ใน query เดียว (OR)
        // SELECT เฉพาะ 4 field ที่หน้ายืมต้องการ (เบา ไม่ดึงทั้งหมด)
        // 🧠 ทำไมรับเป็น string? เพราะ ISBN เป็นตัวอักษร แต่ ID เป็นเลข
        //    MySQL จะ cast ให้อัตโนมัติตอนเทียบ id = ?
        $stmt = $this->pdo->prepare("
            SELECT id, title, author, available 
            FROM books WHERE id = ? OR isbn = ?
        ");
        // 🚀 ส่ง $identifier ซ้ำ 2 ครั้ง → ลองจับคู่ทั้ง id และ isbn
        $stmt->execute([$identifier, $identifier]);
        // 📤 คืนข้อมูลหนังสือ หรือ null ถ้าไม่เจอ
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงหนังสือที่ยังมี stock (available > 0)
     * ==========================================================================
     *
     * 📤 Output: @return array[] รายการหนังสือที่ยังยืมได้ เรียงตามชื่อ
     * ✅ Use case: admin/borrow_form.php → dropdown เลือกหนังสือ
     */
    public function findAvailable(): array
    {
        // 📝 SQL: ดึงหนังสือที่ available > 0 เรียงตามชื่อ A-Z
        // 🧠 ใช้ query() แทน prepare() เพราะไม่มี user input (ปลอดภัย)
        // ⚠️ ถ้าเพิ่ม parameter ในอนาคต ต้องเปลี่ยนเป็น prepare()
        return $this->pdo->query("
            SELECT * FROM books WHERE available > 0 ORDER BY title
        ")->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงหนังสือทั้งหมดสำหรับพิมพ์ barcode labels
     * ==========================================================================
     * SELECT เฉพาะ id, title, isbn (เบาๆ สำหรับพิมพ์ label)
     *
     * 📤 Output: @return array[] [{id, title, isbn}, ...]
     * ✅ Use case: admin/book_labels.php → พิมพ์ barcode สติกเกอร์
     */
    public function findAllForLabels(): array
    {
        // 📝 SQL: ดึงเฉพาะ id, title, isbn (เบาๆ สำหรับพิมพ์ label)
        // 🧠 ไม่ดึง * เพราะหน้าพิมพ์ label ใช้แค่ 3 field
        // ORDER BY id DESC = ใหม่สุดก่อน (พิมพ์ label หนังสือที่เพิ่งเพิ่ม)
        return $this->pdo->query("
            SELECT id, title, isbn FROM books ORDER BY id DESC
        ")->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ค้นหาหนังสือตามชื่อ+ผู้แต่ง (exact match, CSV import merge)
     * ==========================================================================
     * ใช้ตรวจว่าหนังสือมีอยู่แล้วหรือยัง — ถ้ามีจะ addQuantity() แทน create()
     *
     * 📥 Input: @param string $title, @param string $author
     * 📤 Output: @return array|null {id} หรือ null
     * ✅ Use case: admin/import_books.php → CSV import → merge/create
     */
    public function findByTitleAndAuthor(string $title, string $author): ?array
    {
        // 📝 SQL: ค้นหา exact match ชื่อ+ผู้แต่ง → ดึงแค่ id
        // 🧠 ทำไม exact match? เพราะตอน import CSV ต้องตรวจว่ามีหนังสือนี้แล้วหรือยัง
        //    ถ้ามี → เรียก addQuantity() เพิ่มจำนวน
        //    ถ้าไม่มี → เรียก create() สร้างใหม่
        $stmt = $this->pdo->prepare("SELECT id FROM books WHERE title = ? AND author = ?");
        $stmt->execute([$title, $author]);
        // 📤 คืน {id} ถ้าเจอ หรือ null ถ้าไม่มี
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: เพิ่มจำนวน quantity + available พร้อมกัน (CSV import merge)
     * ==========================================================================
     * ใช้ตอน import CSV ถ้าหนังสือมีอยู่แล้ว — เพิ่มจำนวนแทนสร้างใหม่
     *
     * 📥 Input:
     * @param int $id       Book ID
     * @param int $quantity จำนวนที่เพิ่ม (ต้อง > 0)
     *
     * 📤 Output: @return bool true = สำเร็จ
     *
     * 🧠 เหตุผล: เพิ่มทั้ง quantity + available พร้อมกัน
     *   เพราะหนังสือใหม่ที่เพิ่มมายังไม่มีคนยืม → เพิ่มทั้งสองค่า
     * ✅ Use case: import_books.php → หนังสือมีอยู่ → addQuantity()
     */
    public function addQuantity(int $id, int $quantity): bool
    {
        // 📝 SQL: เพิ่มทั้ง quantity + available พร้อมกัน
        // 🧠 ทำไมเพิ่มทั้งคู่? เพราะหนังสือใหม่ที่เพิ่มมายังไม่มีคนยืม
        //    quantity = จำนวนทั้งหมด, available = จำนวนที่ว่าง
        //    เช่น มี 5 เล่ม เพิ่ม 3 → quantity = 8, available = 8 (ถ้าไม่มีคนยืม)
        // ⚠️ ถ้ามีคนยืมอยู่ available จะเพิ่มเกินจำนวนจริงที่ว่าง → ยังปลอดภัย
        //    เพราะ available + 3 ยังไม่เกิน quantity + 3
        $stmt = $this->pdo->prepare("UPDATE books SET quantity = quantity + ?, available = available + ? WHERE id = ?");
        // 🚀 bind: [$quantity, $quantity, $id] → ใส่ค่าเพิ่มทั้ง 2 field + ระบุ ID
        return $stmt->execute([$quantity, $quantity, $id]);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สร้างหนังสือใหม่ (available = quantity)
     * ==========================================================================
     *
     * 📥 Input:
     * @param array $data {title, author, isbn?, category_id?, description?,
     *                     cover_image?, quantity?(default:1)}
     *
     * 📤 Output: @return int Book ID ที่สร้าง
     *
     * 🧠 เหตุผล: available = quantity ตอนสร้าง (ยังไม่มีคนยืม)
     * ✅ Use case: BookService::createBook(), import_books.php
     */
    public function create(array $data): int
    {
        // 📝 SQL: INSERT หนังสือใหม่ทุก field
        // available = quantity เพราะตอนสร้างยังไม่มีคนยืม
        $stmt = $this->pdo->prepare("
            INSERT INTO books (title, author, isbn, category_id, description, cover_image, quantity, available)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        // 📌 ถ้าไม่ส่ง quantity มา → default = 1 (หนังสือ 1 เล่ม)
        $quantity = $data['quantity'] ?? 1;

        // 🚀 bind ค่าทั้งหมด — ใช้ ?? null สำหรับ field ที่ไม่บังคับ
        // 🧠 available = $quantity (เท่ากัน) เพราะยังไม่มีคนยืม
        $stmt->execute([
            $data['title'],              // ชื่อหนังสือ (บังคับ)
            $data['author'],             // ผู้แต่ง (บังคับ)
            $data['isbn'] ?? null,       // ISBN (ไม่บังคับ — ใช้สแกน barcode)
            $data['category_id'] ?? null,// หมวดหมู่ (ไม่บังคับ)
            $data['description'] ?? null,// รายละเอียด (ไม่บังคับ)
            $data['cover_image'] ?? null,// ชื่อไฟล์รูปปก (ไม่บังคับ)
            $quantity,                   // จำนวนทั้งหมด
            $quantity                    // จำนวนที่ว่าง = จำนวนทั้งหมด
        ]);

        // 📤 คืน ID ของหนังสือที่เพิ่งสร้าง (AUTO_INCREMENT)
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: อัปเดตข้อมูลหนังสือ
     * ==========================================================================
     *
     * 📥 Input:
     * @param int   $id   Book ID
     * @param array $data ข้อมูลทั้งหมด (ต้องส่งครบ)
     *
     * 📤 Output: @return bool true = สำเร็จ
     *
     * 🧠 เหตุผล:
     * - cover_image ใช้ COALESCE(?, cover_image) — ถ้าส่ง null จะเก็บรูปเดิม
     * ✅ Use case: BookService::updateBook()
     */
    public function update(int $id, array $data): bool
    {
        // 📝 SQL: UPDATE ทุก field ของหนังสือ
        // 🧠 COALESCE(?, cover_image) = ถ้าส่ง null → เก็บรูปเดิม (ไม่ลบรูปปก)
        //    ถ้าส่งชื่อไฟล์ใหม่ → ใช้รูปใหม่
        //    เทคนิคนี้ช่วยให้ admin แก้ข้อมูลโดยไม่ต้อง upload รูปซ้ำ
        // ⚠️ quantity + available ส่งมาจาก BookService ที่คำนวณให้แล้ว
        //    ห้ามส่ง available > quantity เด็ดขาด
        $stmt = $this->pdo->prepare("
            UPDATE books SET 
                title = ?, author = ?, isbn = ?, category_id = ?, 
                description = ?, cover_image = COALESCE(?, cover_image), 
                quantity = ?, available = ?
            WHERE id = ?
        ");

        // 🚀 bind ค่าทั้งหมด — ลำดับต้องตรงกับ ? ใน SQL
        return $stmt->execute([
            $data['title'],              // 1. ชื่อหนังสือ
            $data['author'],             // 2. ผู้แต่ง
            $data['isbn'] ?? null,       // 3. ISBN
            $data['category_id'] ?? null,// 4. หมวดหมู่
            $data['description'] ?? null,// 5. รายละเอียด
            $data['cover_image'] ?? null,// 6. รูปปก (null = เก็บรูปเดิม)
            $data['quantity'],           // 7. จำนวนทั้งหมด
            $data['available'],          // 8. จำนวนที่ว่าง
            $id                          // 9. WHERE id = ?
        ]);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ตรวจว่า ISBN ซ้ำหรือไม่
     * ==========================================================================
     * เหมือน emailExists() / nameExists() — $excludeId ป้องกันนับตัวเองตอน edit
     *
     * 📥 Input:
     * @param string   $isbn      ISBN ที่ตรวจ
     * @param int|null $excludeId ID ยกเว้น (ตอน edit)
     *
     * 📤 Output: @return bool true = ซ้ำ (ห้ามใช้)
     * ✅ Use case: BookService::createBook(), updateBook()
     */
    public function isbnExists(string $isbn, ?int $excludeId = null): bool
    {
        // 📝 SQL เริ่มต้น: ค้นหา ISBN ที่ตรงกัน
        $sql = "SELECT id FROM books WHERE isbn = ?";
        $params = [$isbn];
        
        // 🧠 $excludeId ใช้ตอน edit — ยกเว้นหนังสือตัวเองออกจากการตรวจซ้ำ
        //    เช่น หนังสือ ID=5 มี ISBN "123" → ตอนแก้ไขหนังสือ ID=5
        //    ต้องยกเว้น ID=5 ออก ไม่งั้นจะบอกว่า "ISBN ซ้ำ" กับตัวเอง
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        // 📤 fetch() !== false → เจอ = ISBN ซ้ำ (return true)
        //    fetch() === false → ไม่เจอ = ISBN ใช้ได้ (return false)
        return $stmt->fetch() !== false;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ลบหนังสือ
     * ==========================================================================
     *
     * 📥 Input: @param int $id Book ID
     * 📤 Output: @return bool true = สำเร็จ
     *
     * ⚠️ Edge case: ถ้ามีประวัติการยืม/จอง → FK constraint → PDOException
     *    ควรเรียกผ่าน BookService::deleteBook() ที่ตรวจก่อน
     * ✅ Use case: BookService::deleteBook()
     */
    public function delete(int $id): bool
    {
        // 📝 SQL: ลบหนังสือตาม ID
        // ⚠️ ถ้ามี FK reference (borrows/reservations อ้างอิงหนังสือนี้)
        //    → MySQL จะ throw PDOException → BookService จับ error แล้วแจ้ง admin
        // 🛡️ ควรเรียกผ่าน BookService::deleteBook() เสมอ
        //    เพราะ Service ตรวจ 3 เงื่อนไขก่อนลบ (มีคนยืม/จอง/ประวัติ)
        $stmt = $this->pdo->prepare("DELETE FROM books WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: เพิ่ม/ลด available โดยตรง (generic)
     * ==========================================================================
     * เมธอดทั่วไป — แนะนำใช้ incrementAvailable()/decrementAvailable() แทน
     * เพราะมี guard (available > 0 / available < quantity)
     *
     * 📥 Input:
     * @param int $id     Book ID
     * @param int $change +1 = คืน, -1 = ยืม
     *
     * 📤 Output: @return bool true = สำเร็จ
     *
     * ⚠️ คำเตือน: ไม่มี guard ป้องกันติดลบ/เกิน stock
     * ✅ Use case: ReservationService (ยกเลิกการจอง → +1)
     */
    public function updateAvailable(int $id, int $change): bool
    {
        // 📝 SQL: เพิ่ม/ลด available ตามค่า $change
        //    $change = +1 → เพิ่ม (คืน/ยกเลิกจอง)
        //    $change = -1 → ลด (ยืม/จอง)
        // ⚠️ ไม่มี guard! ต่างจาก increment/decrement ที่มี WHERE available > 0 / < quantity
        //    ถ้าส่งค่าผิด → available อาจติดลบหรือเกิน quantity ได้
        // 🧠 ใช้ใน ReservationService เป็นหลัก (ยกเลิก/หมดอายุการจอง)
        //    แนะนำใช้ incrementAvailable() / decrementAvailable() แทนถ้าทำได้
        $stmt = $this->pdo->prepare("
            UPDATE books SET available = available + ? WHERE id = ?
        ");
        // 🚀 bind: [$change, $id] → เพิ่ม/ลดค่า + ระบุ ID
        return $stmt->execute([$change, $id]);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: Lock หนังสือ + ดึงข้อมูล (SELECT FOR UPDATE)
     * ==========================================================================
     * lock row ของหนังสือระหว่าง transaction เพื่อป้องกัน 2 คนยืมพร้อมกัน
     *
     * 📥 Input: @param int $id Book ID
     * 📤 Output: @return array|null ข้อมูลทั้งหมด (ถูก lock)
     *
     * 🛡️ Security:
     * - FOR UPDATE = row-level lock — request อื่นต้องรอ
     * - ต้องเรียกใน transaction เท่านั้น
     *
     * ✅ Use case:
     * 1) BorrowService::createBorrow() → lock book → เช็ค available → decrement
     * 2) BorrowService::returnBook()   → lock book → increment
     */
    public function findByIdForUpdate(int $id): ?array
    {
        // 📝 SQL: SELECT * + FOR UPDATE = ดึงข้อมูล + ล็อกแถว
        // 🔴 FOR UPDATE = row-level lock:
        //    - request อื่นที่อยากอ่าน/แก้แถวนี้ต้อง "รอ" จน transaction นี้จบ
        //    - ป้องกัน 2 คนยืมหนังสือเล่มสุดท้ายพร้อมกัน (race condition)
        // ⚠️ ต้องเรียกใน transaction (beginTransaction...commit) เท่านั้น!
        //    ถ้าเรียกนอก transaction → lock ไม่ทำงาน = ไม่ป้องกัน race condition
        $stmt = $this->pdo->prepare("
            SELECT * FROM books WHERE id = ? FOR UPDATE
        ");
        // 🚀 bind $id → ล็อก + ดึงหนังสือเล่มนี้
        $stmt->execute([$id]);
        // 📤 คืนข้อมูลหนังสือ (ถูกล็อก) หรือ null ถ้าไม่เจอ
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: เพิ่ม available +1 (คืนหนังสือ / ยกเลิกการยืม)
     * ==========================================================================
     * มี guard: WHERE available < quantity — ป้องกันเกิน stock
     *
     * 📥 Input: @param int $id Book ID
     * 📤 Output: @return bool true = สำเร็จ, false = available = quantity แล้ว
     *
     * 🛡️ Security: conditional UPDATE ป้องกัน available > quantity (DB level)
     * ✅ Use case: BorrowService::returnBook(), BorrowService::cancelBorrow()
     */
    public function incrementAvailable(int $id): bool
    {
        // 📝 SQL: เพิ่ม available +1 แบบมี guard
        // 🛡️ WHERE available < quantity = ป้องกัน available เกิน quantity
        //    เช่น quantity = 5, available = 5 → UPDATE ไม่ทำงาน (0 rows affected)
        //    เพราะ 5 < 5 = false → WHERE ไม่ผ่าน
        // 🧠 guard นี้อยู่ระดับ DB — แม้ Service พลาดเรียกซ้ำ DB ก็ไม่ยอมทำ
        $stmt = $this->pdo->prepare("
            UPDATE books SET available = available + 1 
            WHERE id = ? AND available < quantity
        ");
        $stmt->execute([$id]);
        // 📤 rowCount() > 0 = อัปเดตสำเร็จ (1 แถวถูกแก้)
        //    rowCount() = 0 = available เท่า quantity แล้ว (ไม่แก้)
        return $stmt->rowCount() > 0;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ลด available -1 (ยืมหนังสือ / จองหนังสือ)
     * ==========================================================================
     * มี guard: WHERE available > 0 — ป้องกัน stock ติดลบ (DB level)
     *
     * 📥 Input: @param int $id Book ID
     * 📤 Output: @return bool true = สำเร็จ, false = available = 0 (หมด stock)
     *
     * 🛡️ Security: conditional UPDATE ป้องกัน available ติดลบ (DB level)
     * ⚠️ ห้ามแก้ WHERE available > 0 — เป็น critical guard
     * ✅ Use case: BorrowService::createBorrow(), ReservationService::createReservation()
     */
    public function decrementAvailable(int $id): bool
    {
        // 📝 SQL: ลด available -1 แบบมี guard
        // 🔴 WHERE available > 0 = ป้องกัน stock ติดลบ (critical guard!)
        //    เช่น available = 0 → UPDATE ไม่ทำงาน (0 rows affected)
        //    เพราะ 0 > 0 = false → WHERE ไม่ผ่าน
        // 🛡️ guard นี้เป็นด่านสุดท้าย — แม้ 2 คนกดยืมพร้อมกัน DB ก็ยอมให้แค่คนเดียว
        // ⚠️ ห้ามลบ WHERE available > 0 ออก! ถ้าลบ = stock ติดลบได้
        $stmt = $this->pdo->prepare("
            UPDATE books SET available = available - 1 
            WHERE id = ? AND available > 0
        ");
        $stmt->execute([$id]);
        // 📤 rowCount() > 0 = หัก stock สำเร็จ (1 แถวถูกแก้)
        //    rowCount() = 0 = หมด stock แล้ว (available = 0) → ยืมไม่ได้
        return $stmt->rowCount() > 0;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับจำนวนรายการหนังสือ (COUNT rows ไม่ใช่ SUM quantity)
     * ==========================================================================
     *
     * 📤 Output: @return int จำนวนรายการ (ไม่ใช่จำนวนเล่ม)
     * ✅ Use case: DashboardService
     */
    public function count(): int
    {
        // 📝 SQL: นับจำนวน "รายการ" หนังสือ (COUNT rows ไม่ใช่ SUM quantity)
        // เช่น มีหนังสือ 3 รายการ (PHP 2 เล่ม, Java 5 เล่ม, Python 1 เล่ม)
        //    count() = 3 (ไม่ใช่ 8 เล่ม)
        // 🧠 ใช้ query() แทน prepare() เพราะไม่มี user input
        return (int) $this->pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงหนังสือที่ใกล้หมด stock (สำหรับ dashboard alert)
     * ==========================================================================
     *
     * 📥 Input:
     * @param int $threshold available ≤ ค่านี้ถือว่าใกล้หมด (default: 2)
     * @param int $limit     จำนวนรายการ (default: 5)
     *
     * 📤 Output: @return array [{id, title, author, quantity, available, category_name}, ...]
     * ✅ Use case: admin/index.php → DashboardService → "หนังสือใกล้หมด"
     */
    public function findLowStock(int $threshold = 2, int $limit = 5): array
    {
        // 📝 SQL: ดึงหนังสือที่ stock ใกล้หมด
        // WHERE available <= $threshold = เหลือไม่เกิน $threshold เล่ม (default: 2)
        //   AND quantity > 0 = ไม่รวมหนังสือที่ quantity = 0 (หนังสือที่ยังไม่มี stock เลย)
        // ORDER BY available ASC = เหลือน้อยสุดขึ้นก่อน (หมด stock ก่อน)
        // LIMIT ? = จำกัดจำนวน (default: 5 รายการ)
        $stmt = $this->pdo->prepare("
            SELECT b.id, b.title, b.author, b.quantity, b.available, c.name as category_name
            FROM books b
            LEFT JOIN categories c ON b.category_id = c.id
            WHERE b.available <= ? AND b.quantity > 0
            ORDER BY b.available ASC, b.title ASC
            LIMIT ?
        ");
        // 🚀 bind: [$threshold, $limit] → เงื่อนไข + จำกัดจำนวน
        $stmt->execute([$threshold, $limit]);
        // 📤 คืน array ของหนังสือที่ใกล้หมด stock → ใช้แสดงใน Dashboard
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงสถิติหนังสือภาพรวม (สำหรับ dashboard)
     * ==========================================================================
     *
     * 📤 Output:
     * @return array {total, available, borrowed, titles}
     *         - total: SUM(quantity) จำนวนเล่มทั้งหมด
     *         - available: SUM(available) เล่มที่ว่าง
     *         - borrowed: total - available
     *         - titles: COUNT รายการ
     *
     * 🧠 เหตุผล: COALESCE(..., 0) ป้องกัน NULL กรณีไม่มีหนังสือ
     * ✅ Use case: admin/index.php → DashboardService
     */
    public function getStatistics(): array
    {
        // 📝 4 query แยกดึงสถิติคนละตัว
        // 🧠 COALESCE(..., 0) = ถ้าไม่มีหนังสือเลย SUM จะได้ NULL → COALESCE เปลี่ยนเป็น 0
        //    ป้องกัน Dashboard พังเพราะแสดงค่า null แทน 0
        // ⚠️ query 4 รอบ = 4 round trip ไป DB
        //    ระดับ template ไม่เป็นปัญหา แต่ถ้าหนังสือเยอะมาก อาจรวมเป็น 1 query ได้
        return [
            // total: จำนวน "เล่ม" ทั้งหมด (SUM quantity)
            'total' => (int) $this->pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM books")->fetchColumn(),
            // available: จำนวน "เล่ม" ที่ว่าง (SUM available)
            'available' => (int) $this->pdo->query("SELECT COALESCE(SUM(available), 0) FROM books")->fetchColumn(),
            // borrowed: จำนวน "เล่ม" ที่ถูกยืม/จอง (total - available)
            'borrowed' => (int) $this->pdo->query("SELECT COALESCE(SUM(quantity - available), 0) FROM books")->fetchColumn(),
            // titles: จำนวน "รายการ" หนังสือ (COUNT rows)
            'titles' => (int) $this->pdo->query("SELECT COUNT(*) FROM books")->fetchColumn(),
        ];
    }
}
