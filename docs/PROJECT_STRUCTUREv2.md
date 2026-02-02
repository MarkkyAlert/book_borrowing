# Project Structure V2 - โครงสร้างระบบยืมคืนหนังสือ

เอกสารนี้อธิบายโครงสร้างโปรเจกต์เพื่อให้เจ้าของโปรเจกต์เข้าใจและอ่านโค้ดต่อได้

---

## สารบัญ

1. [ภาพรวมโครงสร้าง](#1-ภาพรวมโครงสร้าง)
2. [บทบาทของแต่ละโฟลเดอร์](#2-บทบาทของแต่ละโฟลเดอร์)
3. [Entry Points สำคัญ](#3-entry-points-สำคัญ)
4. [Boundary ระหว่าง Layers](#4-boundary-ระหว่าง-layers)
5. [Request → Response Flow](#5-request--response-flow)
6. [File Naming Conventions](#6-file-naming-conventions)

---

## 1. ภาพรวมโครงสร้าง

```
book_borrowing/
│
├── *.php                    ← [PUBLIC] หน้าเว็บสำหรับ User ทั่วไป
│   ├── index.php            ← หน้าแรก (รายการหนังสือ)
│   ├── book.php             ← รายละเอียดหนังสือ
│   ├── login.php            ← เข้าสู่ระบบ
│   ├── register.php         ← สมัครสมาชิก
│   ├── profile.php          ← โปรไฟล์ member
│   ├── forgot_password.php  ← ลืมรหัสผ่าน
│   ├── reset_password.php   ← รีเซ็ตรหัสผ่าน
│   ├── logout.php           ← ออกจากระบบ
│   └── bootstrap.php        ← ★ Core: โหลด config/db/helpers ทั้งหมด
│
├── admin/                   ← [ADMIN] หน้าจัดการสำหรับ Staff/Admin
│   ├── index.php            ← Dashboard
│   ├── books.php            ← จัดการหนังสือ
│   ├── book_form.php        ← เพิ่ม/แก้ไขหนังสือ
│   ├── members.php          ← จัดการสมาชิก
│   ├── member_form.php      ← เพิ่ม/แก้ไขสมาชิก
│   ├── borrows.php          ← จัดการการยืม/คืน
│   ├── borrow_form.php      ← บันทึกการยืม
│   ├── reservations.php     ← จัดการการจอง
│   ├── payments.php         ← จัดการค่าปรับ
│   ├── categories.php       ← จัดการหมวดหมู่
│   ├── reports.php          ← รายงาน
│   ├── settings.php         ← ตั้งค่าระบบ
│   └── header.php/footer.php ← UI components
│
├── api/                     ← [API] Endpoints สำหรับ AJAX
│   ├── search_books.php     ← ค้นหาหนังสือ (GET, HTML response)
│   ├── reserve_book.php     ← จองหนังสือ (POST, JSON response)
│   └── add_member.php       ← เพิ่มสมาชิกด่วน (POST, JSON response)
│
├── app/                     ← [APPLICATION] Business Logic Layer
│   ├── Services/            ← Business Rules & Transactions
│   │   ├── AuthService.php
│   │   ├── BookService.php
│   │   ├── BorrowService.php
│   │   ├── ReservationService.php
│   │   ├── MemberService.php
│   │   ├── ReportService.php
│   │   ├── HomeService.php
│   │   └── DashboardService.php
│   │
│   └── Repositories/        ← Data Access Layer (SQL)
│       ├── BookRepository.php
│       ├── BorrowRepository.php
│       ├── UserRepository.php
│       ├── ReservationRepository.php
│       ├── CategoryRepository.php
│       ├── PaymentRepository.php
│       ├── ReportRepository.php
│       ├── SettingsRepository.php
│       └── PasswordResetRepository.php
│
├── includes/                ← [SHARED] Config & Helpers
│   ├── config.php           ← ★ ค่าคงที่ทั้งระบบ (อ่านจาก .env)
│   ├── db.php               ← ★ PDO Connection (Singleton)
│   ├── functions.php        ← ★ Helper Functions ทั้งหมด
│   ├── header.php           ← HTML header (public pages)
│   ├── footer.php           ← HTML footer (public pages)
│   ├── book_grid.php        ← Component: แสดงรายการหนังสือ
│   └── modal.js             ← JavaScript สำหรับ modal
│
├── database/                ← [DATABASE] Schema & Migrations
│   ├── schema.sql           ← โครงสร้างตารางทั้งหมด
│   ├── sample_data.sql      ← ข้อมูลตัวอย่าง
│   └── migrations/          ← Database migrations
│
├── uploads/                 ← [STORAGE] User Uploads
│   ├── .htaccess            ← ป้องกันเข้าถึงโดยตรง
│   └── covers/              ← รูปปกหนังสือ
│
├── cron/                    ← [CRON] Scheduled Tasks
│   ├── expire_reservations.php ← หมดอายุการจอง
│   └── cleanup_tokens.php   ← ลบ token หมดอายุ
│
├── tests/                   ← [TESTS] Test Files
├── logs/                    ← [LOGS] Application Logs
├── docs/                    ← [DOCS] Documentation
│
├── .env                     ← Environment Variables (ไม่ commit)
├── .env.example             ← Template สำหรับ .env
└── install.php              ← Setup wizard
```

---

## 2. บทบาทของแต่ละโฟลเดอร์

### 2.1 Root (/*.php) - Public Entry Points

| บทบาท | รับ HTTP request จาก user ทั่วไป |
|-------|--------------------------------|
| **ควรทำ** | รับ input, ตรวจ auth/CSRF, เรียก Service, render HTML |
| **ห้ามทำ** | เขียน SQL, Business logic ซับซ้อน |

**ไฟล์สำคัญ:**
- `bootstrap.php` - จุดเริ่มต้นของทุกหน้า (ต้อง require ก่อนอื่น)
- `login.php` - Authentication flow
- `index.php` - หน้าแรก + ค้นหาหนังสือ

### 2.2 admin/ - Admin Panel

| บทบาท | หน้าจัดการสำหรับ Staff และ Admin |
|-------|--------------------------------|
| **ควรทำ** | ป้องกันด้วย `requireStaff()`, จัดการ CRUD |
| **ห้ามทำ** | SQL โดยตรง, ข้าม auth check |

**ทุกไฟล์ต้องมี:**
```php
require_once __DIR__ . '/../bootstrap.php';
requireStaff(); // บังคับ staff/admin เท่านั้น
```

**ไฟล์สำคัญ:**
- `borrow_form.php` - ตัวอย่าง transaction + idempotency
- `books.php` - ตัวอย่าง CRUD listing + delete
- `index.php` - Dashboard รวมสถิติ

### 2.3 api/ - API Endpoints

| บทบาท | รับ AJAX requests, return JSON/HTML |
|-------|-------------------------------------|
| **ควรทำ** | ตรวจ method, auth, CSRF, return proper HTTP codes |
| **ห้ามทำ** | render full HTML page, redirect |

**Pattern ที่ใช้:**
```php
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json');

// 1. Auth check
// 2. Method check (GET/POST)
// 3. CSRF check (ถ้า POST)
// 4. Validate input
// 5. Call service
// 6. Return JSON
```

### 2.4 app/Services/ - Business Logic Layer

| บทบาท | จัดการ business rules, transactions, validation ซับซ้อน |
|-------|-------------------------------------------------------|
| **ควรทำ** | Validate rules, begin/commit transaction, เรียก Repository |
| **ห้ามทำ** | เขียน SQL โดยตรง, access $_SESSION/$_POST |

**Pattern:**
```php
class SomeService {
    private PDO $pdo;
    private SomeRepository $repo;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->repo = new SomeRepository($pdo);
    }
    
    public function doSomething(): array {
        $this->pdo->beginTransaction();
        try {
            // Business logic + Repository calls
            $this->pdo->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
```

### 2.5 app/Repositories/ - Data Access Layer

| บทบาท | SQL queries ทั้งหมด (SELECT/INSERT/UPDATE/DELETE) |
|-------|------------------------------------------------|
| **ควรทำ** | Prepared statements, return arrays |
| **ห้ามทำ** | Business logic, session access, echo/print |

**Pattern:**
```php
class SomeRepository {
    private PDO $pdo;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }
    
    public function findById(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM table WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    // Row locking สำหรับ concurrent access
    public function findByIdForUpdate(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM table WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
```

### 2.6 includes/ - Shared Components

| บทบาท | Config, DB connection, Helper functions |
|-------|----------------------------------------|
| **ควรทำ** | ฟังก์ชันที่ใช้ซ้ำได้ทั้งโปรเจกต์ |
| **ห้ามทำ** | Business logic เฉพาะ domain |

**ไฟล์หลัก:**

| ไฟล์ | หน้าที่ |
|------|--------|
| `config.php` | อ่าน `.env`, define constants |
| `db.php` | `getDB()` - PDO Singleton |
| `functions.php` | Auth, CSRF, validation, formatting helpers |

### 2.7 database/ - Database Schema

| บทบาท | SQL scripts สำหรับสร้าง/แก้ไขฐานข้อมูล |
|-------|--------------------------------------|

**ไฟล์:**
- `schema.sql` - CREATE TABLE statements ทั้งหมด
- `sample_data.sql` - ข้อมูลตัวอย่างสำหรับ test
- `migrations/*.sql` - ALTER TABLE statements

### 2.8 uploads/ - File Storage

| บทบาท | เก็บไฟล์ที่ user upload |
|-------|------------------------|

**Security:**
- `.htaccess` ป้องกัน direct access
- ชื่อไฟล์ถูกสร้างใหม่ด้วย `uniqid()` (ไม่ใช้ชื่อจาก user)
- ตรวจ MIME type จาก content (ไม่เชื่อ `$_FILES['type']`)

### 2.9 cron/ - Scheduled Tasks

| บทบาท | Jobs ที่รัน periodic |
|-------|---------------------|

**ไฟล์:**
- `expire_reservations.php` - เปลี่ยน status reservation ที่หมดอายุ
- `cleanup_tokens.php` - ลบ password reset tokens ที่หมดอายุ

---

## 3. Entry Points สำคัญ

**อ่าน 8 ไฟล์นี้ก่อน เรียงตามลำดับ:**

### 3.1 bootstrap.php (★★★ อันดับ 1)

**เหตุผล:** จุดเริ่มต้นของทุกหน้า - เข้าใจไฟล์นี้ = เข้าใจว่าระบบ setup ตัวเองอย่างไร

```php
// สิ่งที่ทำ:
define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/includes/config.php';   // ค่า config
require_once BASE_PATH . '/includes/db.php';       // DB connection
require_once BASE_PATH . '/includes/functions.php'; // Helpers

// Autoloader สำหรับ app/ classes
spl_autoload_register(function (string $class) {
    // Services\AuthService → app/Services/AuthService.php
});

// เริ่ม session
startSession();

// Cleanup idempotency keys เก่า
cleanupIdempotencyKeys();
```

### 3.2 includes/config.php (★★★ อันดับ 2)

**เหตุผล:** ค่าคงที่ทั้งระบบ - รู้ว่า business rules มาจากไหน

```php
// ตัวอย่างค่าที่ define:
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DEFAULT_BORROW_DAYS', (int) env('DEFAULT_BORROW_DAYS', 7));
define('MAX_BORROW_BOOKS', (int) env('MAX_BORROW_BOOKS', 3));
define('FINE_PER_DAY', (int) env('FINE_PER_DAY', 10));
define('APP_DEBUG', env('APP_DEBUG', 'false') === 'true');
```

### 3.3 includes/functions.php (★★★ อันดับ 3)

**เหตุผล:** Helper functions ทั้งหมด - Auth, CSRF, Validation อยู่ที่นี่

**Functions สำคัญ:**
| Function | หน้าที่ |
|----------|--------|
| `e($str)` | Escape HTML (ป้องกัน XSS) |
| `isLoggedIn()`, `isStaff()`, `isAdmin()` | ตรวจสถานะ login/role |
| `requireLogin()`, `requireStaff()`, `requireAdmin()` | บังคับ auth (redirect ถ้าไม่ผ่าน) |
| `generateCSRFToken()`, `validateCSRFToken()` | CSRF protection |
| `checkRateLimit()`, `incrementRateLimit()` | Rate limiting |
| `validatePassword()`, `isValidEmail()`, `isValidPhone()` | Validation |
| `setFlash()`, `getFlash()`, `displayFlash()` | Flash messages |
| `redirect()` | Redirect + exit |

### 3.4 includes/db.php (★★ อันดับ 4)

**เหตุผล:** DB connection - เข้าใจว่าทุก query ผ่านที่เดียว

```php
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, // Real prepared statements
        ]);
    }
    return $pdo; // Singleton - สร้างครั้งเดียว ใช้ซ้ำได้
}
```

### 3.5 login.php (★★ อันดับ 5)

**เหตุผล:** ตัวอย่าง complete flow - validation, rate limit, service call, session

```php
// Flow:
// 1. ตรวจว่า login อยู่แล้วไหม → redirect
// 2. รับ POST → validate input
// 3. checkRateLimit() → ป้องกัน brute force
// 4. AuthService::login() → ตรวจ credentials
// 5. สำเร็จ → session_regenerate_id() + set $_SESSION + redirect
// 6. ไม่สำเร็จ → incrementRateLimit() + แสดง error
```

### 3.6 app/Services/BorrowService.php (★★ อันดับ 6)

**เหตุผล:** Business logic ซับซ้อนที่สุด - transactions, locking, fine calculation

**Methods สำคัญ:**
- `createBorrow()` - ยืมหนังสือ (transaction + FOR UPDATE lock)
- `returnBook()` - คืนหนังสือ + คำนวณค่าปรับ
- `calculateFine()` - สูตรค่าปรับ

### 3.7 app/Repositories/BookRepository.php (★★ อันดับ 7)

**เหตุผล:** ตัวอย่าง Repository pattern - ดูว่า SQL ควรเขียนอย่างไร

**Methods สำคัญ:**
- `findById()`, `findAll()` - SELECT queries
- `create()`, `update()`, `delete()` - CUD operations
- `findByIdForUpdate()` - Row locking
- `decrementAvailable()` - Atomic update with condition

### 3.8 admin/borrow_form.php (★ อันดับ 8)

**เหตุผล:** ตัวอย่าง admin page ที่ซับซ้อน - CSRF, idempotency, multi-select

```php
// Features:
// 1. requireStaff() - auth guard
// 2. validateCSRFToken() - CSRF protection
// 3. Idempotency key - ป้องกัน double submit
// 4. Call BorrowService::createBorrow()
// 5. Flash message + redirect
```

---

## 4. Boundary ระหว่าง Layers

### 4.1 Layer Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                    Entry Point Layer                        │
│  *.php | admin/*.php | api/*.php                           │
│                                                             │
│  ✓ รับ HTTP request (GET/POST)                             │
│  ✓ ดึงข้อมูลจาก $_GET, $_POST, $_SESSION                   │
│  ✓ ตรวจ auth (requireLogin/requireStaff/requireAdmin)      │
│  ✓ ตรวจ CSRF (validateCSRFToken)                           │
│  ✓ Validate input เบื้องต้น (empty check, format)          │
│  ✓ เรียก Service                                           │
│  ✓ Render HTML หรือ return JSON                            │
│                                                             │
│  ✗ ห้ามเขียน SQL                                            │
│  ✗ ห้ามมี business logic ซับซ้อน                            │
└─────────────────────────────────────────────────────────────┘
                           │
                           │ $service->doSomething($input)
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                     Service Layer                           │
│  app/Services/*.php                                         │
│                                                             │
│  ✓ Business rules (quota check, fine calculation)          │
│  ✓ Transaction management (begin/commit/rollback)          │
│  ✓ Validation ซับซ้อน (cross-field, DB lookup)             │
│  ✓ Coordinate multiple repositories                         │
│  ✓ Throw exceptions เมื่อ business rule fail               │
│                                                             │
│  ✗ ห้ามเขียน SQL โดยตรง (ต้องผ่าน Repository)              │
│  ✗ ห้าม access $_SESSION, $_POST โดยตรง                    │
│  ✗ ห้าม echo/print (return data แทน)                       │
└─────────────────────────────────────────────────────────────┘
                           │
                           │ $repo->findById($id)
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                    Repository Layer                         │
│  app/Repositories/*.php                                     │
│                                                             │
│  ✓ SQL queries ทั้งหมด                                     │
│  ✓ Prepared statements (parameterized)                      │
│  ✓ Return arrays (ไม่ใช่ objects)                          │
│  ✓ Row locking (FOR UPDATE)                                 │
│                                                             │
│  ✗ ห้ามมี business logic                                    │
│  ✗ ห้าม begin/commit transaction (Service ทำ)              │
│  ✗ ห้าม access session                                      │
└─────────────────────────────────────────────────────────────┘
                           │
                           │ PDO query
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                      Database                               │
│  MySQL (via PDO)                                            │
└─────────────────────────────────────────────────────────────┘
```

### 4.2 Helper Layer (Cross-cutting)

```
┌─────────────────────────────────────────────────────────────┐
│                     Helper Layer                            │
│  includes/functions.php                                     │
│                                                             │
│  ✓ ใช้ได้จากทุก layer                                       │
│  ✓ Stateless functions (ไม่เก็บ state)                     │
│  ✓ Security helpers (e, CSRF, rate limit)                   │
│  ✓ Validation helpers (email, phone, password)              │
│  ✓ Formatting helpers (date, currency)                      │
│                                                             │
│  ✗ ห้ามมี business logic                                    │
│  ✗ ห้ามมี SQL                                               │
└─────────────────────────────────────────────────────────────┘
```

### 4.3 Responsibility Matrix

| ความรับผิดชอบ | Entry Point | Service | Repository | Helper |
|--------------|-------------|---------|------------|--------|
| รับ HTTP request | ✓ | | | |
| Auth check | ✓ | | | |
| CSRF check | ✓ | | | |
| Input sanitization | ✓ | | | |
| Business rules | | ✓ | | |
| Transactions | | ✓ | | |
| SQL queries | | | ✓ | |
| Row locking | | | ✓ | |
| Validation helpers | | | | ✓ |
| Security helpers | | | | ✓ |
| Render output | ✓ | | | |

### 4.4 Data Flow Rules

```
1. Entry Point ส่ง primitive values ให้ Service
   ✓ $service->createBorrow($userId, $bookIds, $borrowDays)
   ✗ $service->createBorrow($_POST)  // ห้ามส่ง $_POST ตรงๆ

2. Service return arrays หรือ throw Exception
   ✓ return ['success' => true, 'borrow_id' => 123];
   ✓ throw new Exception('หนังสือหมด');
   ✗ echo "สำเร็จ";  // ห้าม output

3. Repository return arrays หรือ null
   ✓ return $stmt->fetch() ?: null;
   ✓ return $stmt->fetchAll();
   ✗ return new Book($row);  // ไม่ใช้ ORM
```

---

## 5. Request → Response Flow

### 5.1 Web Page Flow (GET)

```
Browser                    login.php              functions.php          AuthService
   │                          │                        │                      │
   │ GET /login.php           │                        │                      │
   │─────────────────────────▶│                        │                      │
   │                          │                        │                      │
   │                          │ require bootstrap.php  │                      │
   │                          │────────────────────────▶                      │
   │                          │                        │                      │
   │                          │ isLoggedIn()?          │                      │
   │                          │────────────────────────▶                      │
   │                          │◀────────────────────────                      │
   │                          │ false                  │                      │
   │                          │                        │                      │
   │                          │ generateCSRFToken()    │                      │
   │                          │────────────────────────▶                      │
   │                          │◀────────────────────────                      │
   │                          │ <token>                │                      │
   │                          │                        │                      │
   │ ◀────────────────────────│                        │                      │
   │ HTML (form + csrf token) │                        │                      │
```

### 5.2 Form Submit Flow (POST)

```
Browser              login.php           functions.php        AuthService      UserRepo
   │                    │                     │                   │               │
   │ POST /login.php    │                     │                   │               │
   │ email, password    │                     │                   │               │
   │───────────────────▶│                     │                   │               │
   │                    │                     │                   │               │
   │                    │ validateCSRFToken() │                   │               │
   │                    │────────────────────▶│                   │               │
   │                    │◀────────────────────│                   │               │
   │                    │ true                │                   │               │
   │                    │                     │                   │               │
   │                    │ checkRateLimit()    │                   │               │
   │                    │────────────────────▶│                   │               │
   │                    │◀────────────────────│                   │               │
   │                    │ true (not exceeded) │                   │               │
   │                    │                     │                   │               │
   │                    │ login($email, $pw)  │                   │               │
   │                    │────────────────────────────────────────▶│               │
   │                    │                     │                   │               │
   │                    │                     │                   │ findByEmail() │
   │                    │                     │                   │──────────────▶│
   │                    │                     │                   │◀──────────────│
   │                    │                     │                   │ user row      │
   │                    │                     │                   │               │
   │                    │                     │                   │ verify pwd    │
   │                    │                     │                   │               │
   │                    │◀────────────────────────────────────────│               │
   │                    │ user data           │                   │               │
   │                    │                     │                   │               │
   │                    │ session_regenerate_id()                 │               │
   │                    │ $_SESSION['user_id'] = ...              │               │
   │                    │                     │                   │               │
   │ ◀──────────────────│                     │                   │               │
   │ 302 Redirect       │                     │                   │               │
```

### 5.3 API Flow (AJAX)

```
Browser (JS)      api/reserve_book.php    ReservationService    BookRepo
   │                     │                      │                  │
   │ POST /api/reserve   │                      │                  │
   │ book_id, csrf       │                      │                  │
   │────────────────────▶│                      │                  │
   │                     │                      │                  │
   │                     │ isLoggedIn()?        │                  │
   │                     │ ✓                    │                  │
   │                     │                      │                  │
   │                     │ validateCSRFToken()  │                  │
   │                     │ ✓                    │                  │
   │                     │                      │                  │
   │                     │ createReservation()  │                  │
   │                     │─────────────────────▶│                  │
   │                     │                      │                  │
   │                     │                      │ beginTransaction │
   │                     │                      │                  │
   │                     │                      │ findForUpdate()  │
   │                     │                      │─────────────────▶│
   │                     │                      │◀─────────────────│
   │                     │                      │                  │
   │                     │                      │ decrement()      │
   │                     │                      │─────────────────▶│
   │                     │                      │                  │
   │                     │                      │ commit           │
   │                     │                      │                  │
   │                     │◀─────────────────────│                  │
   │                     │ success              │                  │
   │                     │                      │                  │
   │ ◀───────────────────│                      │                  │
   │ JSON response       │                      │                  │
```

---

## 6. File Naming Conventions

### 6.1 Entry Points

| Pattern | ตัวอย่าง | หมายเหตุ |
|---------|---------|---------|
| `{noun}.php` | `book.php` | View single item |
| `{noun}s.php` | `admin/books.php` | List items |
| `{noun}_form.php` | `admin/book_form.php` | Create/Edit form |
| `{verb}_{noun}.php` | `api/search_books.php` | API action |

### 6.2 Services

| Pattern | ตัวอย่าง |
|---------|---------|
| `{Domain}Service.php` | `BookService.php`, `AuthService.php` |

**Methods:**
- `create{Entity}()` - สร้างใหม่
- `update{Entity}()` - แก้ไข
- `delete{Entity}()` - ลบ
- `get{Entity}()` / `find{Entity}()` - ดึงข้อมูล

### 6.3 Repositories

| Pattern | ตัวอย่าง |
|---------|---------|
| `{Entity}Repository.php` | `BookRepository.php`, `UserRepository.php` |

**Methods:**
- `findById($id)` - หาด้วย ID
- `findByIdForUpdate($id)` - หาด้วย ID + lock row
- `findAll($filters)` - หาทั้งหมด (with optional filters)
- `create($data)` - INSERT
- `update($id, $data)` - UPDATE
- `delete($id)` - DELETE
- `{verb}By{Field}($value)` - Custom query

---

## Quick Reference

### File Lookup Table

| ต้องการ | ดูที่ |
|--------|------|
| ค่า config (วันยืม, ค่าปรับ) | `includes/config.php`, `.env` |
| Auth functions | `includes/functions.php` |
| CSRF functions | `includes/functions.php` |
| Validation helpers | `includes/functions.php` |
| DB connection | `includes/db.php` |
| SQL queries | `app/Repositories/*.php` |
| Business logic | `app/Services/*.php` |
| Database schema | `database/schema.sql` |

### Layer Decision Table

| ต้องการทำ | ทำที่ Layer |
|-----------|-------------|
| รับ input จาก user | Entry Point |
| ตรวจว่า login ไหม | Entry Point (หรือ Service ถ้าต้องตรวจซ้ำ) |
| Validate format (email, phone) | Entry Point + Helper |
| Business rules (quota, availability) | Service |
| Calculate fine | Service |
| Execute SQL | Repository |
| Lock row (FOR UPDATE) | Repository |
| Format date/currency | Helper |

---

*เอกสารนี้สร้างจากโค้ดจริงในโปรเจกต์ทั้งหมด*
