# Comment & Documentation Standards (คู่มือการคอมเมนต์)

> **เป้าหมาย**: สร้างมาตรฐานเดียวกันทั้งโปรเจกต์ เพื่อให้ทีมอ่าน-แก้ไข-ขยายต่อได้ง่าย

---

## 1. Header Comment (ส่วนหัวไฟล์)

### มาตรฐานสำหรับทุกไฟล์ PHP

```php
<?php
/**
 * [ชื่อไฟล์/หน้าที่] - [คำอธิบายสั้น 1 บรรทัด]
 * 
 * @package App\[Namespace ถ้ามี]
 */
```

### ตัวอย่างตามประเภทไฟล์

**Page Controller** (root/*.php, admin/*.php):
```php
<?php
/**
 * Login Page - เข้าสู่ระบบ
 */
```

**API Endpoint** (api/*.php):
```php
<?php
/**
 * API: Reserve Book
 * 
 * ⚠️ กติกา: ไฟล์นี้ทำหน้าที่ Controller เท่านั้น
 * - ตรวจ method / auth / validate input
 * - เรียก Service
 * - ส่ง JSON response
 * - ห้ามใส่ business logic
 * - ห้ามเขียน SQL โดยตรง
 */
```

**Service** (app/Services/*.php):
```php
<?php
/**
 * BorrowService - Business Logic สำหรับการยืม-คืนหนังสือ
 * 
 * @package App\Services
 */
```

**Repository** (app/Repositories/*.php):
```php
<?php
/**
 * BookRepository - Data Access Layer สำหรับหนังสือ
 * 
 * Repository นี้จัดการ CRUD operations สำหรับตาราง books
 * ไม่มี business logic - เป็นแค่ data access
 * 
 * @package App\Repositories
 */
```

---

## 2. PHPDoc สำหรับ Method

### กรณีที่ **ควรมี** PHPDoc

| กรณี | ความจำเป็น |
|------|-----------|
| Public method ที่มี parameter > 1 | ✅ ต้องมี |
| Method ที่ return array ซับซ้อน | ✅ ต้องมี (ระบุ structure) |
| Method ที่มี side effect (INSERT/UPDATE/DELETE) | ✅ ต้องมี @sideeffect |
| Method ที่เกี่ยวกับ security | ✅ ต้องมี @security |
| Method ที่ throw Exception | ✅ ต้องมี @throws |

### กรณีที่ **ไม่จำเป็น** ต้องมี PHPDoc

| กรณี | หมายเหตุ |
|------|---------|
| Getter/Setter ธรรมดา | ชื่อ method อธิบายเพียงพอ |
| Private method สั้นๆ ชัดเจน | ถ้า < 5 บรรทัด และชื่อชัด |
| Constructor ที่แค่ assign properties | ไม่ต้องมี |

### รูปแบบ PHPDoc มาตรฐาน

```php
/**
 * [คำอธิบายสั้น 1 บรรทัด]
 * 
 * [คำอธิบายเพิ่มเติม ถ้าจำเป็น]
 * 
 * @param int   $userId  ID ผู้ใช้
 * @param array $data    {
 *     name: string,     // ชื่อ (required)
 *     email?: string    // อีเมล (optional)
 * }
 * 
 * @return array { success: bool, message: string }
 * 
 * @throws Exception เมื่อ [เงื่อนไขที่ throw]
 * 
 * @sideeffect INSERT ลง `borrows` table
 * @security ใช้ FOR UPDATE lock ป้องกัน race condition
 */
```

---

## 3. ภาษาที่ใช้

### กฎเกณฑ์

| ส่วน | ภาษา | ตัวอย่าง |
|------|------|---------|
| คำอธิบาย flow/logic | ไทย | `// ตรวจซ้ำก่อน - ป้องกันจอง 2 ครั้ง` |
| ชื่อตัวแปร/method/class | อังกฤษ | `$borrowService`, `createBorrow()` |
| Error message ถึง user | ไทย | `'กรุณากรอกอีเมล'` |
| Technical terms | อังกฤษ | CSRF, session, token, transaction |
| PHPDoc tags | อังกฤษ | `@param`, `@return`, `@throws` |
| Security/Architecture notes | อังกฤษ (prefix) + ไทย | `[SECURITY] ป้องกัน brute force` |

---

## 4. กติกา 10 ข้อ (ควรทำ / ห้ามทำ)

### ✅ ควรคอมเมนต์

1. **Why, not What** - อธิบาย "ทำไม" ไม่ใช่ "ทำอะไร"
   ```php
   // ✅ GOOD: ใช้ FOR UPDATE ป้องกัน 2 คนจองเล่มสุดท้ายพร้อมกัน
   // ❌ BAD: Lock row
   ```

2. **Security decisions** - ทุกจุดที่เกี่ยวกับ security
   ```php
   // [SECURITY] ไม่บอกว่า email หรือ password ผิด - ป้องกัน user enumeration
   ```

3. **Business rules** - กฎที่ลูกค้ากำหนด
   ```php
   // [RULE] ยืมได้สูงสุด 3 เล่ม, ค่าปรับ 10 บาท/วัน
   ```

4. **State transitions** - การเปลี่ยน status
   ```php
   // State: pending → fulfilled
   ```

5. **Non-obvious behavior** - พฤติกรรมที่คาดไม่ถึง
   ```php
   // หัก stock ทันทีตอนจอง (ไม่ใช่ตอนรับของ) - ป้องกันจองเกิน
   ```

### ❌ ห้ามคอมเมนต์

6. **Syntax explanation** - อธิบาย syntax พื้นฐาน
   ```php
   // ❌ BAD: วนลูป array
   foreach ($items as $item) { ... }
   ```

7. **Duplicate of code** - ซ้ำกับสิ่งที่โค้ดบอกอยู่แล้ว
   ```php
   // ❌ BAD: Get user by ID
   function getUserById($id) { ... }
   ```

8. **Outdated comments** - คอมเมนต์ที่ไม่ตรงกับโค้ด
   ```php
   // ❌ BAD: เรียก UserService (แต่จริงๆ เรียก UserRepository)
   ```

9. **TODO without context** - TODO ลอยๆ ไม่มีเจ้าของ/กำหนด
   ```php
   // ❌ BAD: TODO: fix this later
   // ✅ GOOD: TODO(@john, 2024-02): Add email notification
   ```

10. **Commented-out code** - โค้ดที่ comment ไว้
    ```php
    // ❌ BAD: // $oldMethod(); - ลบออก ใช้ git ดูประวัติแทน
    ```

---

## 5. Inline Comment Prefixes (มาตรฐาน)

| Prefix | ใช้เมื่อ | ตัวอย่าง |
|--------|---------|---------|
| `[SECURITY]` | เรื่อง security | `[SECURITY] CSRF ป้องกัน cross-site request` |
| `[AUTH]` | Authentication/Authorization | `[AUTH] ต้อง login เท่านั้น` |
| `[RULE]` | Business rule จากลูกค้า | `[RULE] ยืมได้ไม่เกิน 3 เล่ม` |
| `[NOTE]` | หมายเหตุสำคัญ | `[NOTE] ทำ POST ก่อน fetch` |
| `[DB]` | Database operation สำคัญ | `[DB] Transaction ต้อง atomic` |
| `[DEPRECATED]` | จะถูกลบในอนาคต | `[DEPRECATED] ใช้ newMethod() แทน` |

---

## 6. Good vs Bad Examples (จากโค้ดจริง)

### Example 1: Security Comment

```php
// ✅ GOOD (login.php:30-32)
// [SECURITY] Rate limiting ป้องกัน brute force attack
// ใช้ md5(email) เป็น key เพื่อนับแยกตาม email (ไม่ใช่ IP - เพราะ IP อาจ shared)
// Limit: 5 attempts / 15 นาที ต่อ email

// ❌ BAD
// Check rate limit
```

### Example 2: PHPDoc for Complex Return

```php
// ✅ GOOD (BorrowService.php:49-55)
/**
 * @return array {
 *     success: bool,           // true ถ้ายืมได้อย่างน้อย 1 เล่ม
 *     borrowed: string[],      // รายชื่อหนังสือที่ยืมสำเร็จ
 *     skipped: string[],       // รายชื่อหนังสือที่ข้าม พร้อมเหตุผล
 *     due_date: string,        // วันกำหนดคืน (Y-m-d)
 *     message: string          // ข้อความสรุปผล
 * }
 */

// ❌ BAD
/** @return array */
```

### Example 3: Side Effect Declaration

```php
// ✅ GOOD (functions.php:46-47)
/**
 * @sideeffect เขียนลง $_SESSION['flash']
 */
function setFlash(string $type, string $message): void

// ❌ BAD - ไม่บอกว่าเขียน session
function setFlash(string $type, string $message): void
```

### Example 4: API Controller Rules

```php
// ✅ GOOD (api/reserve_book.php:5-11)
/**
 * ⚠️ กติกา: ไฟล์นี้ทำหน้าที่ Controller เท่านั้น
 * - ตรวจ method / auth / validate input
 * - เรียก Service
 * - ส่ง JSON response
 * - ห้ามใส่ business logic
 * - ห้ามเขียน SQL โดยตรง
 */

// ❌ BAD - ไม่บอกกฎ/ขอบเขตหน้าที่
/**
 * Reserve book API
 */
```

### Example 5: Throws Declaration

```php
// ✅ GOOD (BookService.php:143)
/**
 * ลบหนังสือ
 * 
 * @throws Exception ถ้าหนังสือกำลังถูกยืมหรือมีประวัติการยืม
 */
public function deleteBook(int $id): bool

// ❌ BAD - ไม่บอกว่า throw ได้
/**
 * ลบหนังสือ
 */
public function deleteBook(int $id): bool
```

---

## 7. File-Specific Rules

### admin/*.php (Page Controllers)
- Header comment: `/** [Page Name] - [คำอธิบาย] */`
- ไม่จำเป็นต้องมี `@package`
- Comment สำคัญ: CSRF check, Auth check, State transitions

### api/*.php (JSON Endpoints)
- **ต้องมี** กติกา block ที่ header
- Comment ทุกขั้นตอน: Auth → Method → Validate → Service → Response

### app/Services/*.php
- **ต้องมี** PHPDoc ครบทุก public method
- ระบุ @throws, @sideeffect, @security
- **ห้าม** มี raw SQL (ต้องผ่าน Repository)

### app/Repositories/*.php
- **ต้องมี** PHPDoc สำหรับ method ที่มี complex query
- ระบุ @sideeffect สำหรับ INSERT/UPDATE/DELETE
- **ห้าม** มี business logic

### includes/functions.php
- **ต้องมี** PHPDoc ครบทุก function (เป็น reference สำหรับทั้งโปรเจกต์)
- ใส่ @example เมื่อการใช้งานไม่ชัดเจน

---

## 8. Checklist ก่อน Commit

- [ ] Header comment ครบตามประเภทไฟล์
- [ ] PHPDoc ครบสำหรับ public methods ที่ซับซ้อน
- [ ] ไม่มี TODO ลอยๆ (ต้องมี owner, date)
- [ ] ไม่มี commented-out code
- [ ] Security-related code มี `[SECURITY]` prefix
- [ ] คอมเมนต์ตรงกับโค้ดจริง (ไม่ outdated)
- [ ] ไม่มีคอมเมนต์ซ้ำซ้อนกับชื่อ function/variable

---

*Last updated: 2026-02-01*
