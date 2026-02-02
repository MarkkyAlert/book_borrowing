# Project Structure - ระบบยืมคืนหนังสือ

เอกสารนี้อธิบายโครงสร้างโปรเจกต์เพื่อให้เจ้าของโปรเจกต์เข้าใจและอ่านโค้ดต่อได้

---

## 1. ภาพรวมโครงสร้างโฟลเดอร์

```
book_borrowing/
│
├── *.php                    # Public Entry Points (หน้าเว็บสาธารณะ)
│   ├── index.php            # หน้าแรก - แสดงรายการหนังสือ
│   ├── login.php            # เข้าสู่ระบบ
│   ├── logout.php           # ออกจากระบบ
│   ├── register.php         # สมัครสมาชิก
│   ├── book.php             # รายละเอียดหนังสือ
│   ├── profile.php          # โปรไฟล์ผู้ใช้
│   ├── forgot_password.php  # ลืมรหัสผ่าน
│   ├── reset_password.php   # รีเซ็ตรหัสผ่าน
│   ├── install.php          # ติดตั้งระบบ (ใช้ครั้งแรก)
│   └── bootstrap.php        # ⭐ จุดเริ่มต้นทุก request
│
├── admin/                   # Admin Panel (Staff/Admin only)
│   ├── index.php            # Dashboard
│   ├── books.php            # จัดการหนังสือ
│   ├── book_form.php        # เพิ่ม/แก้ไขหนังสือ
│   ├── members.php          # จัดการสมาชิก
│   ├── member_form.php      # เพิ่ม/แก้ไขสมาชิก
│   ├── borrows.php          # จัดการการยืม
│   ├── borrow_form.php      # บันทึกการยืม
│   ├── reservations.php     # จัดการการจอง
│   ├── payments.php         # จัดการการชำระเงิน
│   ├── categories.php       # จัดการหมวดหมู่
│   ├── reports.php          # รายงาน
│   ├── settings.php         # ตั้งค่าระบบ (Admin only)
│   ├── import_*.php         # นำเข้าข้อมูล
│   ├── export_*.php         # ส่งออกข้อมูล
│   ├── header.php           # Header template (admin)
│   └── footer.php           # Footer template (admin)
│
├── api/                     # JSON API Endpoints
│   ├── search_books.php     # ค้นหาหนังสือ (public)
│   ├── reserve_book.php     # จองหนังสือ (member+)
│   └── add_member.php       # เพิ่มสมาชิก (staff+)
│
├── app/                     # Application Logic Layer
│   ├── Services/            # ⭐ Business Logic
│   │   ├── AuthService.php
│   │   ├── BookService.php
│   │   ├── BorrowService.php
│   │   ├── ReservationService.php
│   │   ├── MemberService.php
│   │   ├── DashboardService.php
│   │   ├── ReportService.php
│   │   └── HomeService.php
│   │
│   └── Repositories/        # ⭐ Database Access (SQL)
│       ├── BookRepository.php
│       ├── BorrowRepository.php
│       ├── UserRepository.php
│       ├── ReservationRepository.php
│       ├── CategoryRepository.php
│       ├── PaymentRepository.php
│       ├── SettingsRepository.php
│       ├── PasswordResetRepository.php
│       └── ReportRepository.php
│
├── includes/                # Shared Components
│   ├── config.php           # ⭐ ค่าคงที่ทั้งระบบ
│   ├── db.php               # ⭐ PDO Connection (Singleton)
│   ├── functions.php        # ⭐ Helper functions ทั้งหมด
│   ├── header.php           # Header template (public)
│   ├── footer.php           # Footer template (public)
│   ├── book_grid.php        # Book grid component
│   └── modal.js             # Modal JavaScript
│
├── database/                # Database Files
│   ├── schema.sql           # โครงสร้างตาราง
│   ├── sample_data.sql      # ข้อมูลตัวอย่าง
│   └── migrations/          # Migration files
│
├── uploads/                 # User Uploads
│   ├── .htaccess            # Security (deny direct access)
│   └── covers/              # รูปปกหนังสือ
│
├── cron/                    # Scheduled Tasks
│   ├── expire_reservations.php  # หมดอายุการจอง
│   └── cleanup_tokens.php       # ลบ token หมดอายุ
│
├── logs/                    # Log Files
├── css/                     # Stylesheets
├── tests/                   # Test Files
├── docs/                    # Documentation
│
├── .env.example             # Environment template
├── .env                     # Environment variables (ไม่ commit)
└── .gitignore
```

---

## 2. บทบาทของแต่ละโฟลเดอร์

### 2.1 Root PHP Files (Public Entry Points)

| ไฟล์ | บทบาท | ใครเข้าถึงได้ |
|------|-------|--------------|
| `index.php` | หน้าแรก - แสดงหนังสือทั้งหมด, filter, search | ทุกคน |
| `book.php` | รายละเอียดหนังสือ, ปุ่มจอง | ทุกคน (จองได้เฉพาะ member) |
| `login.php` | ฟอร์มเข้าสู่ระบบ + rate limiting | ทุกคน |
| `register.php` | ฟอร์มสมัครสมาชิก + validation | ทุกคน |
| `profile.php` | ดู/แก้ไขโปรไฟล์, ประวัติยืม | member+ |
| `logout.php` | ทำลาย session | member+ |
| `forgot_password.php` | ขอ reset password | ทุกคน |
| `reset_password.php` | ตั้งรหัสผ่านใหม่ | ทุกคน (ต้องมี token) |
| `install.php` | ติดตั้งระบบครั้งแรก | ทุกคน (ใช้ครั้งเดียว) |
| `bootstrap.php` | **โหลดทุก request** - config, db, helpers, autoloader | - |

### 2.2 admin/ (Admin Panel)

**Access:** ต้อง login + role = staff หรือ admin

| ไฟล์ | บทบาท |
|------|-------|
| `index.php` | Dashboard - สถิติ, รายการเร่งด่วน |
| `books.php` | รายการหนังสือ + filter + ลบ |
| `book_form.php` | เพิ่ม/แก้ไขหนังสือ + upload cover |
| `members.php` | รายการสมาชิก + filter |
| `member_form.php` | เพิ่ม/แก้ไขสมาชิก |
| `borrows.php` | รายการยืม + คืนหนังสือ + รับค่าปรับ |
| `borrow_form.php` | บันทึกการยืม (scan/เลือกหนังสือ) |
| `reservations.php` | รายการจอง + อนุมัติ/ยกเลิก |
| `payments.php` | รายการชำระค่าปรับ |
| `categories.php` | จัดการหมวดหมู่หนังสือ |
| `reports.php` | รายงานสถิติต่างๆ |
| `settings.php` | ตั้งค่าระบบ (**Admin only**) |
| `import_books.php` | นำเข้าหนังสือจาก CSV |
| `import_members.php` | นำเข้าสมาชิกจาก CSV |
| `export_pdf.php` | ส่งออก PDF |
| `book_labels.php` | พิมพ์ป้ายหนังสือ |
| `member_card.php` | พิมพ์บัตรสมาชิก |

### 2.3 api/ (JSON API Endpoints)

**Response:** JSON เท่านั้น (`Content-Type: application/json`)

| ไฟล์ | Method | บทบาท | Auth |
|------|--------|-------|------|
| `search_books.php` | GET | ค้นหาหนังสือ (AJAX autocomplete) | ไม่ต้อง |
| `reserve_book.php` | POST | จองหนังสือ | member+ |
| `add_member.php` | POST | Quick add สมาชิก | staff+ |

### 2.4 app/Services/ (Business Logic Layer)

**บทบาท:** จัดการ business logic, transactions, validation rules

| Service | ความรับผิดชอบ |
|---------|--------------|
| `AuthService` | Login, register, password reset, เปลี่ยนรหัสผ่าน |
| `BookService` | CRUD หนังสือ, ตรวจ ISBN ซ้ำ, จัดการ cover |
| `BorrowService` | ⭐ ยืม/คืน/คำนวณค่าปรับ - core ของระบบ |
| `ReservationService` | จอง/อนุมัติ/ยกเลิก/หมดอายุ |
| `MemberService` | CRUD สมาชิก |
| `DashboardService` | รวบรวมข้อมูล dashboard |
| `ReportService` | สร้างรายงาน |
| `HomeService` | ข้อมูลหน้าแรก (หนังสือใหม่, ยอดนิยม) |

### 2.5 app/Repositories/ (Data Access Layer)

**บทบาท:** SQL queries เท่านั้น - ไม่มี business logic

| Repository | ตาราง | หมายเหตุ |
|------------|-------|---------|
| `BookRepository` | `books` | มี `findByIdForUpdate()` สำหรับ locking |
| `BorrowRepository` | `borrows` | มี `findByIdForUpdate()`, `countActiveBorrowsForUpdate()` |
| `UserRepository` | `users` | มี `findByEmail()` สำหรับ login |
| `ReservationRepository` | `reservations` | มี `findPendingForUpdate()` |
| `CategoryRepository` | `categories` | รวม `findAllWithBookCount()` |
| `PaymentRepository` | `payments` | บันทึกการชำระค่าปรับ |
| `SettingsRepository` | `settings` | key-value settings |
| `PasswordResetRepository` | `password_resets` | tokens สำหรับ reset password |
| `ReportRepository` | หลายตาราง | queries สำหรับ reports |

### 2.6 includes/ (Shared Components)

| ไฟล์ | บทบาท |
|------|-------|
| `config.php` | ค่าคงที่ทั้งระบบ (อ่านจาก `.env`) |
| `db.php` | PDO connection (Singleton pattern) |
| `functions.php` | ⭐ Helper functions ทุกประเภท |
| `header.php` | HTML header (public pages) |
| `footer.php` | HTML footer (public pages) |
| `book_grid.php` | Book card component |
| `modal.js` | JavaScript สำหรับ modals |

### 2.7 โฟลเดอร์อื่นๆ

| โฟลเดอร์ | บทบาท |
|---------|-------|
| `database/` | SQL schema, migrations, sample data |
| `uploads/` | ไฟล์ที่ user upload (มี .htaccess ป้องกัน) |
| `cron/` | Scripts ที่รันตามเวลา (via cron job) |
| `logs/` | Log files |
| `css/` | Stylesheets |
| `tests/` | Test files |
| `docs/` | Documentation |

---

## 3. Entry Points สำคัญที่ควรอ่านก่อน

### ลำดับแนะนำ (5-8 ไฟล์)

| ลำดับ | ไฟล์ | เหตุผลที่ต้องอ่าน |
|-------|------|------------------|
| **1** | `bootstrap.php` | **จุดเริ่มต้นทุก request** - เข้าใจว่าระบบโหลดอะไรบ้าง |
| **2** | `includes/config.php` | **ค่าคงที่ทั้งหมด** - business rules, limits, timeouts |
| **3** | `includes/functions.php` | **Helper ทั้งหมด** - auth, CSRF, validation, rate limit |
| **4** | `includes/db.php` | **DB connection** - Singleton pattern, PDO config |
| **5** | `login.php` | **ตัวอย่าง auth flow** - rate limit, session, redirect |
| **6** | `app/Services/BorrowService.php` | **Core business logic** - ยืม/คืน/ค่าปรับ |
| **7** | `admin/borrows.php` | **ตัวอย่าง admin page** - CSRF, idempotency, state change |
| **8** | `api/reserve_book.php` | **ตัวอย่าง API** - JSON response, auth check |

### รายละเอียดแต่ละไฟล์

#### 1. bootstrap.php
```
ทุกหน้าเริ่มต้นที่นี่:
- require 'includes/config.php'   → ค่าคงที่
- require 'includes/db.php'       → getDB() function
- require 'includes/functions.php' → helpers
- spl_autoload_register()         → autoload app/ classes
```

#### 2. includes/config.php
```
ค่าที่ต้องรู้:
- DEFAULT_BORROW_DAYS = 7
- MAX_BORROW_BOOKS = 3
- FINE_PER_DAY = 10
- RATE_LIMIT_MAX_ATTEMPTS = 5
- SESSION_LIFETIME = 3600
```

#### 3. includes/functions.php
```
Functions สำคัญ:
Security:     e(), generateCSRFToken(), validateCSRFToken()
Auth:         isLoggedIn(), isStaff(), isAdmin()
Access:       requireLogin(), requireStaff(), requireAdmin()
Rate Limit:   checkRateLimit(), incrementRateLimit()
Validation:   isValidEmail(), isValidPhone(), validatePassword()
UI:           setFlash(), getFlash(), redirect()
```

#### 4. login.php
```
Flow ที่ควรเข้าใจ:
1. Rate limit check
2. Input validation
3. AuthService::login()
4. Session regeneration (ป้องกัน fixation)
5. Role-based redirect
```

#### 5. app/Services/BorrowService.php
```
Methods หลัก:
- createBorrow()   → ยืมหนังสือ (transaction + locking)
- returnBook()     → คืน + คำนวณค่าปรับ
- calculateFine()  → สูตรค่าปรับ
- payFine()        → รับค่าปรับ
```

#### 6. admin/borrows.php
```
Pattern ที่ควรเรียนรู้:
1. requireStaff()           → access control
2. validateCSRFToken()      → CSRF protection
3. idempotency check        → ป้องกัน double-submit
4. Service method call      → business logic
5. setFlash() + redirect()  → user feedback
```

---

## 4. Boundary ระหว่าง Layers

### 4.1 Layer Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                     Entry Points                                │
│  *.php (root)  │  admin/*.php  │  api/*.php                    │
│                                                                 │
│  ✓ รับ HTTP Request                                             │
│  ✓ Auth Check (requireLogin, requireStaff, requireAdmin)       │
│  ✓ CSRF Check (validateCSRFToken)                              │
│  ✓ Rate Limit (checkRateLimit)                                 │
│  ✓ Input Sanitization (trim, intval, etc.)                     │
│  ✓ Basic Validation                                            │
│  ✗ ห้ามเขียน SQL                                               │
│  ✗ ห้ามมี business logic ซับซ้อน                               │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼ Validated Data
┌─────────────────────────────────────────────────────────────────┐
│                     Service Layer                               │
│                   app/Services/*.php                            │
│                                                                 │
│  ✓ Business Logic & Rules                                      │
│  ✓ Transaction Management (begin/commit/rollback)              │
│  ✓ Complex Validation (quota check, availability)              │
│  ✓ Coordinate multiple Repository calls                        │
│  ✓ Throw Exception on failure                                  │
│  ✗ ห้ามเขียน SQL โดยตรง                                        │
│  ✗ ห้ามเข้าถึง $_GET, $_POST, $_SESSION                        │
│  ✗ ห้าม echo/output                                            │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼ Repository Method Calls
┌─────────────────────────────────────────────────────────────────┐
│                    Repository Layer                             │
│                 app/Repositories/*.php                          │
│                                                                 │
│  ✓ SQL Queries (SELECT, INSERT, UPDATE, DELETE)                │
│  ✓ Prepared Statements (? placeholders)                        │
│  ✓ Row Locking (FOR UPDATE)                                    │
│  ✓ Return arrays                                               │
│  ✗ ห้ามมี business logic                                       │
│  ✗ ห้าม validate                                               │
│  ✗ ห้ามจัดการ transaction (ปล่อยให้ Service จัดการ)             │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼ PDO Queries
┌─────────────────────────────────────────────────────────────────┐
│                      Database (MySQL)                           │
│                                                                 │
│  Tables: users, books, categories, borrows, reservations,      │
│          payments, password_resets, settings                   │
└─────────────────────────────────────────────────────────────────┘
```

### 4.2 Helper Functions Layer

```
┌─────────────────────────────────────────────────────────────────┐
│                    includes/functions.php                       │
│                                                                 │
│  ✓ Utility functions (e, redirect, formatDate)                 │
│  ✓ Auth helpers (isLoggedIn, isStaff, requireLogin)            │
│  ✓ Security helpers (generateCSRFToken, validateCSRFToken)     │
│  ✓ Validation helpers (isValidEmail, validatePassword)         │
│  ✓ Rate limiting (checkRateLimit, incrementRateLimit)          │
│  ✓ Flash messages (setFlash, getFlash)                         │
│  ✗ ห้ามเขียน SQL (ยกเว้น getCurrentUser ที่ cache ใน session)  │
│  ✗ ห้ามมี business logic                                       │
└─────────────────────────────────────────────────────────────────┘
```

### 4.3 ตัวอย่าง Request Flow

**Flow: สมาชิกจองหนังสือ**

```
1. Browser POST → api/reserve_book.php
   │
   ├─ require bootstrap.php (โหลด config, db, functions)
   ├─ isLoggedIn() check
   ├─ validateCSRFToken() check
   ├─ $bookId = intval($_POST['book_id'])
   │
   ▼
2. ReservationService::createReservation($userId, $bookId)
   │
   ├─ $this->pdo->beginTransaction()
   ├─ $book = $this->bookRepo->findByIdForUpdate($bookId)  ← lock
   ├─ ตรวจ $book['available'] > 0
   ├─ ตรวจ pending reservation ซ้ำ
   ├─ $this->bookRepo->decrementAvailable($bookId)
   ├─ $this->reservationRepo->create([...])
   ├─ $this->pdo->commit()
   │
   ▼
3. JSON Response
   echo json_encode(['success' => true, 'message' => '...'])
```

### 4.4 กฎสำคัญ

| Layer | ควรทำ | ห้ามทำ |
|-------|-------|--------|
| **Entry Point** | Auth, CSRF, input sanitization, เรียก Service | SQL, complex logic |
| **Service** | Business logic, transactions, เรียก Repository | SQL ตรงๆ, $_POST, output |
| **Repository** | SQL queries, prepared statements | Business logic, validation |
| **Helpers** | Utility functions, formatting | SQL, business logic |

---

## 5. Database Tables

| ตาราง | บทบาท | Foreign Keys |
|-------|-------|--------------|
| `users` | ผู้ใช้ทุก role (admin, staff, member) | - |
| `books` | หนังสือในห้องสมุด | → categories.id |
| `categories` | หมวดหมู่หนังสือ | - |
| `borrows` | บันทึกการยืม | → users.id, books.id |
| `reservations` | บันทึกการจอง | → users.id, books.id, borrows.id |
| `payments` | บันทึกการชำระค่าปรับ | → borrows.id |
| `password_resets` | Tokens สำหรับ reset password | → users.id |
| `settings` | ค่าตั้งค่าระบบ (key-value) | - |

---

## 6. User Roles

| Role | Access Level | หน้าที่เข้าถึงได้ |
|------|-------------|-----------------|
| `member` | ต่ำสุด | หน้าสาธารณะ, profile, จองหนังสือ |
| `staff` | กลาง | ทุกอย่างของ member + admin panel (ยกเว้น settings) |
| `admin` | สูงสุด | ทุกอย่าง + settings |

**Access Control Functions:**
```php
isLoggedIn()    // ตรวจว่า login อยู่ไหม
isStaff()       // role = staff หรือ admin
isAdmin()       // role = admin เท่านั้น

requireLogin()  // บังคับ login
requireStaff()  // บังคับ staff+
requireAdmin()  // บังคับ admin
```

---

## 7. Security Layers

| Layer | Implementation | ตำแหน่ง |
|-------|----------------|---------|
| **XSS Prevention** | `e()` function (htmlspecialchars) | includes/functions.php |
| **SQL Injection** | Prepared Statements (?) | Repositories |
| **CSRF Protection** | Token per session | includes/functions.php |
| **Rate Limiting** | Session-based counter | includes/functions.php |
| **Session Fixation** | `session_regenerate_id()` | login.php |
| **File Upload** | MIME whitelist, finfo check | admin/book_form.php |
| **Password Storage** | `password_hash()` / `password_verify()` | AuthService |
| **Row Locking** | `FOR UPDATE` in transactions | Repositories |

---

## 8. Configuration

### 8.1 Environment Variables (.env)

```ini
# Database
DB_HOST=localhost
DB_NAME=book_borrowing
DB_USER=root
DB_PASS=

# Application
APP_NAME=ระบบยืมคืนหนังสือ
APP_URL=http://localhost/book_borrowing
APP_DEBUG=false

# Business Rules
DEFAULT_BORROW_DAYS=7
MAX_BORROW_BOOKS=3
FINE_PER_DAY=10

# Security
MIN_PASSWORD_LENGTH=6
RATE_LIMIT_MAX_ATTEMPTS=5
RATE_LIMIT_WINDOW_MINUTES=15
SESSION_LIFETIME=3600
```

### 8.2 Constants (includes/config.php)

Constants ถูก define จาก `.env` หรือใช้ค่า default:
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `APP_NAME`, `APP_URL`, `APP_DEBUG`
- `DEFAULT_BORROW_DAYS`, `MAX_BORROW_BOOKS`, `FINE_PER_DAY`
- `MIN_PASSWORD_LENGTH`, `RATE_LIMIT_*`, `SESSION_LIFETIME`

---

## 9. Quick Reference

### 9.1 เพิ่มหน้า Admin ใหม่

```php
<?php
require_once __DIR__ . '/../bootstrap.php';
requireStaff();  // หรือ requireAdmin()

$pageTitle = 'ชื่อหน้า';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid token');
        redirect($_SERVER['PHP_SELF']);
    }
    
    // Process...
    $service = new SomeService(getDB());
    $service->doSomething($data);
    
    setFlash('success', 'สำเร็จ');
    redirect($_SERVER['PHP_SELF']);
}

// GET data
$service = new SomeService(getDB());
$items = $service->getAll();

require_once 'header.php';
?>
<!-- HTML -->
<?php require_once 'footer.php'; ?>
```

### 9.2 เพิ่ม API Endpoint ใหม่

```php
<?php
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

// Auth check
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// CSRF check
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

// Process
try {
    $service = new SomeService(getDB());
    $result = $service->doSomething($_POST['input']);
    echo json_encode(['success' => true, 'data' => $result]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
```

---

*เอกสารนี้สร้างจากโครงสร้างโค้ดจริง ไม่มีการเดาหรือแต่งเพิ่ม*
