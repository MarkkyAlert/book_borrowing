# Project Structure - โครงสร้างโปรเจกต์ระบบยืมคืนหนังสือ

เอกสารนี้อธิบายโครงสร้างโปรเจกต์เพื่อให้เจ้าของโปรเจกต์เข้าใจและอ่านโค้ดต่อได้

---

## 1. ภาพรวมโครงสร้างโฟลเดอร์

```
book_borrowing/
├── admin/              # หน้า Admin (Staff/Admin only)
├── api/                # API Endpoints (AJAX/Public)
├── app/                # Application Layer (Business Logic)
│   ├── Config/         # Configuration classes
│   ├── Helpers/        # Utility functions (namespaced)
│   ├── Repositories/   # Data Access Layer (SQL)
│   └── Services/       # Business Logic Layer
├── css/                # Stylesheets
├── database/           # SQL Schema & Migrations
├── docs/               # Documentation
├── includes/           # Shared PHP includes
├── tests/              # Test files
├── uploads/            # User uploads (covers)
│   └── covers/         # Book cover images
├── index.php           # Homepage (Public)
├── login.php           # Login page
├── register.php        # Registration page
├── profile.php         # User profile
├── book.php            # Book detail page
├── bootstrap.php       # Application bootstrap (Phase 2)
└── install.php         # Installation wizard
```

---

## 2. บทบาทของแต่ละโฟลเดอร์

### 2.1 Root Level (/) - Public Pages

| ไฟล์ | หน้าที่ |
|------|--------|
| `index.php` | หน้าแรก - แสดงรายการหนังสือ, ค้นหา, กรอง |
| `login.php` | หน้า Login |
| `register.php` | หน้าสมัครสมาชิก |
| `logout.php` | ออกจากระบบ (clear session) |
| `profile.php` | หน้าโปรไฟล์ผู้ใช้ (ต้อง login) |
| `book.php` | หน้ารายละเอียดหนังสือ |
| `forgot_password.php` | ขอรีเซ็ตรหัสผ่าน |
| `reset_password.php` | รีเซ็ตรหัสผ่านด้วย token |
| `install.php` | ติดตั้งระบบ (สร้าง database) |
| `bootstrap.php` | Bootstrap file (เตรียมไว้ Phase 2) |

**Pattern:** ไฟล์ root level ทำหน้าที่เป็น "Controller" - รับ request, เรียก business logic, render view

---

### 2.2 admin/ - หน้า Admin Panel

| ไฟล์ | หน้าที่ |
|------|--------|
| `index.php` | Dashboard - สถิติรวม, Charts |
| `books.php` | รายการหนังสือ + ลบหนังสือ |
| `book_form.php` | ฟอร์มเพิ่ม/แก้ไขหนังสือ |
| `borrows.php` | รายการยืม + คืนหนังสือ |
| `borrow_form.php` | ฟอร์มบันทึกการยืม |
| `members.php` | รายการสมาชิก + ลบสมาชิก |
| `member_form.php` | ฟอร์มเพิ่ม/แก้ไขสมาชิก |
| `categories.php` | จัดการหมวดหมู่ (CRUD ใน 1 ไฟล์) |
| `reservations.php` | จัดการการจอง (อนุมัติ/ยกเลิก) |
| `payments.php` | รายการชำระค่าปรับ |
| `reports.php` | รายงานต่างๆ + Export CSV |
| `settings.php` | ตั้งค่าระบบ |
| `import_books.php` | นำเข้าหนังสือจาก CSV |
| `import_members.php` | นำเข้าสมาชิกจาก CSV |
| `ajax_add_member.php` | AJAX: เพิ่มสมาชิกด่วน |
| `book_labels.php` | พิมพ์ label/barcode |
| `member_card.php` | พิมพ์บัตรสมาชิก |
| `export_pdf.php` | Export PDF |
| `header.php` | Admin header/nav |
| `footer.php` | Admin footer |

**Access Control:**
- ทุกไฟล์เรียก `requireStaff()` หรือ `requireAdmin()` ที่บรรทัดแรก
- `requireStaff()` = admin หรือ staff เข้าได้
- `requireAdmin()` = เฉพาะ admin (เช่น settings.php)

---

### 2.3 api/ - API Endpoints

| ไฟล์ | หน้าที่ |
|------|--------|
| `search_books.php` | AJAX: ค้นหาหนังสือ (return HTML partial) |
| `reserve_book.php` | AJAX: จองหนังสือ (return JSON) |

**Pattern:**
```
1. Validate input
2. เรียก Repository/Service
3. Return response (HTML หรือ JSON)
```

**หมายเหตุ:** `search_books.php` return HTML partial (ไม่ใช่ JSON) เพราะใช้กับ AJAX ที่ต้องการ replace innerHTML โดยตรง

---

### 2.4 includes/ - Shared PHP Files

| ไฟล์ | หน้าที่ |
|------|--------|
| `config.php` | **Configuration หลัก** - อ่าน .env, define constants |
| `db.php` | Database connection (PDO Singleton) |
| `functions.php` | **Helper functions หลัก** - auth, CSRF, validation, flash |
| `header.php` | Public header (HTML head, nav) |
| `footer.php` | Public footer |
| `book_grid.php` | Partial: แสดง grid หนังสือ (ใช้ซ้ำ) |

**สำคัญ:** `includes/functions.php` คือ "Single Source of Truth" สำหรับ:
- Authentication (`isLoggedIn`, `isAdmin`, `isStaff`)
- Authorization (`requireLogin`, `requireAdmin`, `requireStaff`)
- CSRF (`generateCSRFToken`, `validateCSRFToken`)
- Flash messages (`setFlash`, `getFlash`, `displayFlash`)
- Validation (`isValidEmail`, `isValidPhone`)
- Output (`e` สำหรับ XSS protection)

---

### 2.5 app/ - Application Layer

```
app/
├── Config/
│   └── settings.php    # Settings class (Phase 2)
├── Helpers/
│   └── functions.php   # Namespaced utility functions
├── Repositories/       # Data Access Layer
│   ├── BookRepository.php
│   ├── BorrowRepository.php
│   ├── CategoryRepository.php
│   └── UserRepository.php
└── Services/           # Business Logic Layer
    ├── BookService.php
    ├── BorrowService.php
    ├── MemberService.php
    ├── ReportService.php
    └── ReservationService.php
```

#### 2.5.1 Repositories/ - Data Access Layer

**หน้าที่:** อ่าน/เขียน Database เท่านั้น (CRUD operations)
**ห้าม:** Business logic, validation, authorization

| Repository | Tables |
|------------|--------|
| `BookRepository` | books |
| `BorrowRepository` | borrows |
| `CategoryRepository` | categories |
| `UserRepository` | users |

**Pattern:**
```php
class BookRepository {
    public function findAll(array $filters = []): array
    public function findById(int $id): ?array
    public function create(array $data): int
    public function update(int $id, array $data): bool
    public function delete(int $id): bool
    public function findByIdForUpdate(int $id): ?array  // FOR UPDATE lock
}
```

#### 2.5.2 Services/ - Business Logic Layer

**หน้าที่:** Business logic, validation, transaction management
**เรียก:** Repository สำหรับ database access

| Service | หน้าที่ |
|---------|--------|
| `BorrowService` | ยืม/คืนหนังสือ, คำนวณค่าปรับ |
| `ReservationService` | จองหนังสือ, ยกเลิกการจอง |
| `BookService` | Logic เกี่ยวกับหนังสือ |
| `MemberService` | Logic เกี่ยวกับสมาชิก |
| `ReportService` | สร้างรายงาน |

**Pattern:**
```php
class BorrowService {
    public function createBorrow(int $userId, array $bookIds): array
    public function returnBook(int $borrowId, bool $payNow = false): array
    public function calculateFine(string $dueDate): array
}
```

---

### 2.6 database/ - SQL Files

| ไฟล์/โฟลเดอร์ | หน้าที่ |
|--------------|--------|
| `schema.sql` | โครงสร้างตาราง (CREATE TABLE) |
| `sample_data.sql` | ข้อมูลตัวอย่าง |
| `create_password_resets.sql` | ตาราง password_resets |
| `migrations/` | SQL migrations |
| `migrations/migrate_fines_roles.sql` | เพิ่ม roles, payments |
| `migrations/migrate_quantity.sql` | เพิ่ม quantity/available |
| `migrations/migrate_reservations.sql` | เพิ่ม reservations |

---

### 2.7 uploads/ - User Uploads

```
uploads/
└── covers/             # Book cover images
    └── cover_*.png     # Format: cover_{timestamp}_{random}.ext
```

**Security:**
- ตรวจ MIME type ก่อน upload
- ใช้ชื่อไฟล์ที่สร้างใหม่ (ไม่ใช้ชื่อเดิมจาก user)

---

## 3. Entry Points สำคัญที่ควรอ่านก่อน

### 3.1 ไฟล์ Configuration & Setup

| ลำดับ | ไฟล์ | เหตุผลที่ควรอ่าน |
|-------|------|-----------------|
| 1 | `includes/config.php` | เข้าใจ constants ทั้งหมด (DB, borrow settings, app settings) |
| 2 | `includes/db.php` | เข้าใจ database connection (PDO options, Singleton) |
| 3 | `includes/functions.php` | เข้าใจ helper functions ที่ใช้ทั้งระบบ (auth, CSRF, validation) |

### 3.2 ไฟล์ Flow หลัก

| ลำดับ | ไฟล์ | เหตุผลที่ควรอ่าน |
|-------|------|-----------------|
| 4 | `login.php` | เข้าใจ authentication flow, session, rate limiting |
| 5 | `admin/borrow_form.php` | เข้าใจ core business flow (ยืมหนังสือ), CSRF, transaction |
| 6 | `app/Services/BorrowService.php` | เข้าใจ business logic layer, transaction, fine calculation |
| 7 | `app/Repositories/BookRepository.php` | เข้าใจ data access pattern |
| 8 | `api/reserve_book.php` | เข้าใจ API pattern (JSON response, error handling) |

---

## 4. Request → Response Flow

### 4.1 ภาพรวม Flow

```
┌─────────────────────────────────────────────────────────────────────┐
│                           BROWSER                                    │
│                         (HTTP Request)                               │
└─────────────────────────────────────────────────────────────────────┘
                                 │
                                 ▼
┌─────────────────────────────────────────────────────────────────────┐
│  1. ENTRY POINT (PHP File)                                          │
│     ─────────────────────────────────────────────────────────────   │
│     • login.php, admin/books.php, api/reserve_book.php              │
│     • Load: includes/functions.php, includes/db.php                 │
│     • Check: auth (requireLogin/requireStaff/requireAdmin)          │
│     • Check: CSRF token (POST requests)                             │
└─────────────────────────────────────────────────────────────────────┘
                                 │
                                 ▼
┌─────────────────────────────────────────────────────────────────────┐
│  2. CONTROLLER LOGIC (ใน PHP File เดียวกัน)                          │
│     ─────────────────────────────────────────────────────────────   │
│     • Validate input ($_GET, $_POST)                                │
│     • เรียก Service หรือ Repository                                  │
│     • Handle errors                                                  │
└─────────────────────────────────────────────────────────────────────┘
                                 │
                    ┌────────────┴────────────┐
                    ▼                         ▼
┌─────────────────────────────┐   ┌─────────────────────────────┐
│  3a. SERVICE LAYER          │   │  3b. REPOSITORY LAYER       │
│      (Business Logic)       │   │      (Simple CRUD)          │
│  ─────────────────────────  │   │  ─────────────────────────  │
│  • BorrowService            │   │  • BookRepository           │
│  • ReservationService       │   │  • UserRepository           │
│  • Transaction management   │   │  • Direct DB access         │
│  • Business rules           │   │  • No business logic        │
│  • Fine calculation         │   │                             │
└─────────────────────────────┘   └─────────────────────────────┘
                    │                         │
                    └────────────┬────────────┘
                                 ▼
┌─────────────────────────────────────────────────────────────────────┐
│  4. DATABASE (PDO)                                                   │
│     ─────────────────────────────────────────────────────────────   │
│     • MySQL / MariaDB                                                │
│     • Prepared statements                                            │
│     • Transactions (BEGIN / COMMIT / ROLLBACK)                       │
└─────────────────────────────────────────────────────────────────────┘
                                 │
                                 ▼
┌─────────────────────────────────────────────────────────────────────┐
│  5. RESPONSE                                                         │
│     ─────────────────────────────────────────────────────────────   │
│     • HTML Page (includes/header.php + content + includes/footer)   │
│     • HTML Partial (api/search_books.php)                           │
│     • JSON (api/reserve_book.php)                                   │
│     • Redirect + Flash message                                       │
└─────────────────────────────────────────────────────────────────────┘
```

### 4.2 ตัวอย่าง Flow: การยืมหนังสือ

```
1. Browser → POST /admin/borrow_form.php
   ├── Headers: Cookie (session_id)
   └── Body: csrf_token, user_id, book_ids[], borrow_days

2. borrow_form.php (Entry Point)
   ├── require includes/functions.php
   ├── require includes/db.php
   ├── requireStaff()           ← ตรวจสิทธิ์
   └── validateCSRFToken()      ← ตรวจ CSRF

3. borrow_form.php (Controller Logic)
   ├── Validate input
   ├── new BorrowService($pdo)
   └── $service->createBorrow($userId, $bookIds)

4. BorrowService::createBorrow() (Service Layer)
   ├── $pdo->beginTransaction()
   ├── SELECT ... FOR UPDATE    ← Lock rows
   ├── Check quota (MAX_BORROW_BOOKS)
   ├── Check availability
   ├── INSERT INTO borrows
   ├── UPDATE books SET available = available - 1
   └── $pdo->commit()

5. Response
   ├── setFlash('success', 'บันทึกการยืมสำเร็จ')
   └── redirect('borrows.php')
```

---

## 5. Boundary ระหว่าง Layers

### 5.1 แผนภาพ Layer

```
┌─────────────────────────────────────────────────────────────────┐
│                    PRESENTATION LAYER                            │
│  ─────────────────────────────────────────────────────────────  │
│  • PHP Files (*.php ที่ root และ admin/)                         │
│  • includes/header.php, footer.php                              │
│  • HTML output, form handling                                    │
│                                                                  │
│  ✓ รับ input จาก user                                            │
│  ✓ แสดงผล HTML                                                   │
│  ✓ Redirect & Flash messages                                     │
│  ✗ ห้ามมี business logic ซับซ้อน                                  │
│  ✗ ห้ามเขียน SQL โดยตรง (ยกเว้น simple queries)                  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    BUSINESS LOGIC LAYER                          │
│  ─────────────────────────────────────────────────────────────  │
│  • app/Services/*.php                                            │
│                                                                  │
│  ✓ Business rules (ยืมได้ไม่เกิน 3 เล่ม)                          │
│  ✓ Calculations (ค่าปรับ)                                        │
│  ✓ Transaction management                                        │
│  ✓ Concurrency control (FOR UPDATE)                             │
│  ✓ Validation ที่เป็น business rule                              │
│  ✗ ห้ามมี HTML                                                   │
│  ✗ ห้ามเข้าถึง $_GET, $_POST, $_SESSION โดยตรง                   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    DATA ACCESS LAYER                             │
│  ─────────────────────────────────────────────────────────────  │
│  • app/Repositories/*.php                                        │
│                                                                  │
│  ✓ CRUD operations                                               │
│  ✓ SQL queries                                                   │
│  ✓ Return raw data (arrays)                                      │
│  ✗ ห้ามมี business logic                                         │
│  ✗ ห้ามมี transaction (ยกเว้น findForUpdate)                     │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    DATABASE LAYER                                │
│  ─────────────────────────────────────────────────────────────  │
│  • includes/db.php (PDO connection)                              │
│  • MySQL / MariaDB                                               │
└─────────────────────────────────────────────────────────────────┘
```

### 5.2 ตำแหน่ง Logic ที่ถูกต้อง

| Logic Type | ที่ควรอยู่ | ตัวอย่าง |
|------------|-----------|----------|
| **Input Validation** | Controller (PHP file) | `empty($_POST['email'])` |
| **Format Validation** | `includes/functions.php` | `isValidEmail()`, `isValidPhone()` |
| **Business Validation** | Service | ยืมเกินโควต้าไหม? หนังสือว่างไหม? |
| **Authorization** | Controller + `functions.php` | `requireStaff()`, `isAdmin()` |
| **CSRF Check** | Controller | `validateCSRFToken()` |
| **Database Query** | Repository | `findAll()`, `create()` |
| **Transaction** | Service | `beginTransaction()`, `commit()` |
| **Fine Calculation** | Service | `calculateFine()` |

### 5.3 การเรียกข้าม Layer

```
✓ ถูกต้อง:
  Controller → Service → Repository → Database

✗ ผิด:
  Controller → Database (ข้าม Service/Repository)
  Service → $_POST (Service ไม่ควรเข้าถึง superglobals)
  Repository → business logic (Repository ทำแค่ CRUD)
```

---

## 6. สรุป Conventions ที่ใช้ในโปรเจกต์

### 6.1 File Naming

| ประเภท | Pattern | ตัวอย่าง |
|--------|---------|----------|
| Public page | lowercase.php | `login.php`, `book.php` |
| Admin page | lowercase.php | `books.php`, `borrow_form.php` |
| API | lowercase_underscore.php | `search_books.php` |
| Service | PascalCase + Service.php | `BorrowService.php` |
| Repository | PascalCase + Repository.php | `BookRepository.php` |

### 6.2 Function/Method Naming

| ประเภท | Pattern | ตัวอย่าง |
|--------|---------|----------|
| Helper | camelCase | `isLoggedIn()`, `generateCSRFToken()` |
| Repository | camelCase (CRUD verbs) | `findAll()`, `create()`, `update()` |
| Service | camelCase (action verbs) | `createBorrow()`, `returnBook()` |
| Check/Boolean | is/has prefix | `isAdmin()`, `hasBorrowHistory()` |

### 6.3 Response Patterns

| Scenario | Pattern |
|----------|---------|
| POST success | `setFlash('success', '...')` → `redirect()` |
| POST error | `setFlash('error', '...')` → `redirect()` (หรือ stay on form) |
| AJAX HTML | `echo` HTML partial |
| AJAX JSON | `json_encode(['success' => bool, 'message' => '...'])` |

---

## 7. ข้อสังเกตเกี่ยวกับโครงสร้างปัจจุบัน

### 7.1 Pattern ที่ใช้

1. **Page-based routing:** ไม่มี front controller, แต่ละ .php file คือ 1 route
2. **Mixed MVC:** Controller logic อยู่ใน PHP file, View อยู่ใน file เดียวกัน
3. **Service/Repository pattern:** แยก business logic ออกจาก data access
4. **Session-based auth:** ใช้ PHP session สำหรับ authentication

### 7.2 หมายเหตุ

- `bootstrap.php` เตรียมไว้สำหรับ Phase 2 (ยังไม่ถูกใช้งานจริง)
- `app/Helpers/functions.php` มี namespace `App\Helpers` แต่ `includes/functions.php` ไม่มี namespace (ใช้ global)
- บาง controller (เช่น `index.php`, `admin/index.php`) เขียน SQL โดยตรง แทนที่จะผ่าน Repository (ยอมรับได้สำหรับ read-only queries ที่ไม่ซับซ้อน)

---

*เอกสารนี้อ้างอิงจากโค้ดจริงในโปรเจกต์ เวอร์ชัน ณ วันที่สร้างเอกสาร*
