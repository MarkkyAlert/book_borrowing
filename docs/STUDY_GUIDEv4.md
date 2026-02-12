# Study Guide V4 — คู่มือศึกษาระบบยืมคืนหนังสือ

เอกสารนี้เขียนสำหรับ **เจ้าของโปรเจกต์** ที่ให้ AI เขียนโค้ดส่วนใหญ่  
เป้าหมาย: อ่านโค้ดเองได้ → ไล่ flow ได้ → debug ได้ → แก้/เพิ่มฟีเจอร์ได้โดยไม่ทำระบบพัง

> **ข้อกำหนด:** เอกสารนี้อ้างอิงจากโค้ดจริงทั้งหมด ไม่มีการเดาหรือแต่งเพิ่ม  
> ถ้ามีข้อมูลที่ไม่พบในโค้ดจะระบุว่า "ไม่พบในโค้ด"

---

## สารบัญ

1. [Project Map](#1-project-map)
2. [Request → Response Flow](#2-request--response-flow-ภาพรวม)
3. [Core Flows](#3-core-flows-เพื่อการศึกษา)
4. [Single Source of Truth Map](#4-single-source-of-truth-map)
5. [Debug Playbook](#5-debug-playbook)
6. [Modification Guide](#6-modification-guide-แก้ระบบแบบไม่พัง)
7. [Quick Reference](#7-quick-reference)
8. [สรุปท้ายเอกสาร](#8-สรุปท้ายเอกสาร)

---

## 1. Project Map

### 1.1 โครงสร้างโฟลเดอร์ทั้งหมด

```
book_borrowing/
│
├── bootstrap.php          ← ★ Core: โหลดทุกอย่าง (config + db + helpers + autoloader)
├── install.php            ← Setup wizard (ใช้ครั้งเดียว)
│
├── *.php (root)           ← [ENTRY POINT: Public] หน้าเว็บสำหรับทุกคน
│   ├── index.php          ← หน้าแรก (รายการหนังสือ)
│   ├── book.php           ← หน้ารายละเอียดหนังสือ
│   ├── login.php          ← เข้าสู่ระบบ
│   ├── register.php       ← สมัครสมาชิก
│   ├── profile.php        ← โปรไฟล์/เปลี่ยนรหัสผ่าน (ต้อง login)
│   ├── forgot_password.php← ลืมรหัสผ่าน
│   ├── reset_password.php ← รีเซ็ตรหัสผ่าน (ใช้ token)
│   └── logout.php         ← ออกจากระบบ
│
├── admin/                 ← [ENTRY POINT: Admin] หน้าจัดการ (ต้องเป็น staff/admin)
│   ├── index.php          ← Dashboard สถิติรวม
│   ├── books.php          ← รายการหนังสือ + ลบ
│   ├── book_form.php      ← เพิ่ม/แก้ไขหนังสือ
│   ├── borrows.php        ← รายการยืม/คืน + ปุ่มคืน
│   ├── borrow_form.php    ← บันทึกการยืม (มี Quick Scan)
│   ├── members.php        ← รายการสมาชิก
│   ├── member_form.php    ← เพิ่ม/แก้ไขสมาชิก
│   ├── reservations.php   ← จัดการการจอง (อนุมัติ/ยกเลิก)
│   ├── payments.php       ← รับชำระค่าปรับ
│   ├── categories.php     ← จัดการหมวดหมู่
│   ├── reports.php        ← รายงาน
│   ├── settings.php       ← ตั้งค่าระบบ (admin only)
│   ├── import_books.php   ← Import หนังสือจาก CSV
│   ├── import_members.php ← Import สมาชิกจาก CSV
│   ├── book_labels.php    ← พิมพ์ barcode labels
│   ├── member_card.php    ← พิมพ์บัตรสมาชิก
│   ├── export_pdf.php     ← ส่งออก PDF
│   ├── header.php         ← Admin header (UI)
│   └── footer.php         ← Admin footer (UI)
│
├── api/                   ← [ENTRY POINT: API] สำหรับ AJAX
│   ├── search_books.php   ← ค้นหาหนังสือ (GET → HTML partial)
│   ├── reserve_book.php   ← จองหนังสือ (POST → JSON)
│   └── add_member.php     ← เพิ่มสมาชิกด่วน (POST → JSON)
│
├── app/                   ← [APPLICATION LOGIC]
│   ├── Services/          ← Business Logic (rules, transactions)
│   │   ├── AuthService.php
│   │   ├── BookService.php
│   │   ├── BorrowService.php
│   │   ├── ReservationService.php
│   │   ├── MemberService.php
│   │   ├── ReportService.php
│   │   ├── DashboardService.php
│   │   └── HomeService.php
│   └── Repositories/      ← Data Access (SQL queries)
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
├── includes/              ← [SHARED] Config & Helper ใช้ร่วมทั้งระบบ
│   ├── config.php         ← ★ Constants ทั้งระบบ (อ่านจาก .env)
│   ├── db.php             ← ★ PDO Connection (Singleton)
│   ├── functions.php      ← ★ Helper functions (auth, CSRF, validation, format)
│   ├── header.php         ← HTML header (public pages)
│   ├── footer.php         ← HTML footer (public pages)
│   ├── book_grid.php      ← Component แสดงรายการหนังสือ
│   └── modal.js           ← JavaScript สำหรับ modal
│
├── database/              ← [DATABASE] SQL scripts
│   ├── schema.sql         ← CREATE TABLE ทั้งหมด
│   ├── sample_data.sql    ← ข้อมูลตัวอย่าง
│   └── migrations/        ← ALTER TABLE scripts
│
├── uploads/               ← [STORAGE] ไฟล์ที่ user upload
│   ├── .htaccess          ← ป้องกัน direct access
│   └── covers/            ← รูปปกหนังสือ
│
├── cron/                  ← [SCHEDULED] Jobs ที่รัน periodic
│   ├── expire_reservations.php
│   └── cleanup_tokens.php
│
├── tests/                 ← [TESTS]
├── logs/                  ← [LOGS]
├── docs/                  ← [DOCS] เอกสาร
├── css/style.css          ← Stylesheet (ส่วนใหญ่ใช้ Tailwind CDN)
├── .env                   ← Environment variables (ไม่ commit)
└── .env.example           ← Template สำหรับ .env
```

### 1.2 หน้าที่ของแต่ละ Layer

| Layer | ตำแหน่ง | หน้าที่ | ตัวอย่าง |
|-------|---------|--------|---------|
| **Entry Point** | `*.php`, `admin/*.php`, `api/*.php` | รับ HTTP request, ตรวจ auth/CSRF, เรียก Service, render output | `login.php`, `admin/borrows.php` |
| **Service** | `app/Services/*.php` | Business logic, transactions, validation ซับซ้อน | `BorrowService::createBorrow()` |
| **Repository** | `app/Repositories/*.php` | SQL queries (SELECT/INSERT/UPDATE/DELETE) | `BookRepository::findByIdForUpdate()` |
| **Helpers** | `includes/functions.php` | Utility functions ใช้ได้ทุก layer | `e()`, `validateCSRFToken()` |
| **Config** | `includes/config.php` + `.env` | ค่าคงที่ทั้งระบบ | `MAX_BORROW_BOOKS`, `FINE_PER_DAY` |
| **Database** | `includes/db.php` | PDO connection (Singleton) | `getDB()` |

### 1.3 ไฟล์ Entry Point สำคัญ (อ่านก่อน 10 ไฟล์)

| # | ไฟล์ | เหตุผล |
|---|------|--------|
| 1 | `bootstrap.php` | จุดเริ่มต้นของทุกหน้า — เข้าใจว่าระบบ setup ตัวเองอย่างไร: โหลด config → db → functions → autoloader → startSession() → cleanupIdempotencyKeys() |
| 2 | `includes/config.php` | ค่าคงที่ทั้งระบบ — `DEFAULT_BORROW_DAYS`, `MAX_BORROW_BOOKS`, `FINE_PER_DAY`, `APP_DEBUG` ทุกค่าอ่านจาก `.env` ผ่าน `env()` |
| 3 | `includes/functions.php` | Helper functions ทุกตัว — auth (`isLoggedIn`, `requireStaff`), CSRF (`generateCSRFToken`, `validateCSRFToken`), validation (`validateMemberData`, `validatePassword`), rate limiting (`checkRateLimit`), formatting (`formatDate`, `formatFine`) |
| 4 | `includes/db.php` | PDO Singleton — `getDB()` สร้าง connection ครั้งเดียว ใช้ซ้ำ; `EMULATE_PREPARES=false` บังคับ native prepared statement |
| 5 | `login.php` | ตัวอย่าง complete auth flow — CSRF check → rate limit → `AuthService::login()` → `session_regenerate_id()` → redirect ตาม role |
| 6 | `app/Services/BorrowService.php` | Business logic ที่ซับซ้อนที่สุด — `createBorrow()` (transaction + FOR UPDATE lock + quota check), `returnBook()` (คำนวณค่าปรับ + คืน stock), `calculateFine()` |
| 7 | `app/Services/ReservationService.php` | State machine จอง→อนุมัติ→ยกเลิก/หมดอายุ + stock management (หัก stock ตอนจอง) |
| 8 | `app/Repositories/BookRepository.php` | ตัวอย่าง Repository pattern — `findByIdForUpdate()` (row lock), `decrementAvailable()` (มี `WHERE available > 0` ป้องกัน stock ติดลบ) |
| 9 | `admin/borrow_form.php` | ตัวอย่าง admin page ที่ซับซ้อน — CSRF + idempotency key + AJAX scan + multi-book select |
| 10 | `api/reserve_book.php` | ตัวอย่าง API endpoint — auth → method → CSRF → validate → Service → JSON response |

---

## 2. Request → Response Flow (ภาพรวม)

### 2.1 Flow มาตรฐาน

```
Browser (User)
    │
    │ HTTP Request (GET/POST)
    ▼
┌─────────────────────────────────────────────────────────┐
│ Entry Point (*.php / admin/*.php / api/*.php)           │
│                                                         │
│  require_once bootstrap.php;   ← โหลดทุกอย่าง          │
│  requireStaff();               ← ตรวจสิทธิ์             │
│  validateCSRFToken();          ← ตรวจ CSRF (POST)       │
│  $input = (int) $_POST[...];  ← รับ + sanitize input   │
│                                                         │
│  ✓ รับ input, ตรวจ auth/CSRF, sanitize                  │
│  ✗ ห้ามเขียน SQL, ห้ามมี business logic ซับซ้อน        │
└───────────────────────┬─────────────────────────────────┘
                        │
                        │ $service->doSomething($input)
                        ▼
┌─────────────────────────────────────────────────────────┐
│ Service (app/Services/*.php)                            │
│                                                         │
│  $this->pdo->beginTransaction();                        │
│  $this->bookRepo->findByIdForUpdate($id);  ← lock row  │
│  // validate business rules (quota, availability)       │
│  $this->bookRepo->decrementAvailable($id);              │
│  $this->borrowRepo->create($data);                      │
│  $this->pdo->commit();                                  │
│                                                         │
│  ✓ Business rules, transactions, เรียก Repository       │
│  ✗ ห้ามเขียน SQL ตรง, ห้าม access $_SESSION/$_POST     │
└───────────────────────┬─────────────────────────────────┘
                        │
                        │ $repo->findById($id)
                        ▼
┌─────────────────────────────────────────────────────────┐
│ Repository (app/Repositories/*.php)                     │
│                                                         │
│  $stmt = $this->pdo->prepare("SELECT ... WHERE id = ?");│
│  $stmt->execute([$id]);                                 │
│  return $stmt->fetch() ?: null;                         │
│                                                         │
│  ✓ SQL queries, prepared statements, return arrays      │
│  ✗ ห้ามมี business logic, ห้ามเริ่ม transaction         │
└───────────────────────┬─────────────────────────────────┘
                        │
                        │ PDO query
                        ▼
┌─────────────────────────────────────────────────────────┐
│ Database (MySQL via PDO)                                │
└─────────────────────────────────────────────────────────┘
                        │
                        │ Result
                        ▼
┌─────────────────────────────────────────────────────────┐
│ Response                                                │
│  • Web Page: setFlash('success') → redirect()           │
│  • API: echo json_encode(['success' => true, ...])      │
└─────────────────────────────────────────────────────────┘
```

### 2.2 Boundary: สิ่งที่ "ห้ามทำ" ในแต่ละ Layer

| Layer | ✓ ควรทำ | ✗ ห้ามทำ |
|-------|---------|----------|
| **Entry Point** | รับ input, ตรวจ auth/CSRF, เรียก Service, render HTML/JSON | เขียน SQL, business logic ซับซ้อน (เช่น คำนวณค่าปรับ) |
| **Service** | Business rules, transactions (begin/commit/rollback), เรียก Repository | เขียน SQL ตรง, access `$_SESSION`/`$_POST`, echo/print |
| **Repository** | SQL queries (prepared statements), return arrays, row locking | Business logic, session access, begin/commit transaction |
| **Helpers** | Utility functions (format, validate, security) | Business logic, SQL queries |

### 2.3 Data Flow Rules (จากโค้ดจริง)

```php
// ✓ Entry Point ส่ง primitive values ให้ Service
$borrowService->createBorrow($userId, $bookIds, $borrowDays);

// ✗ ห้ามส่ง $_POST ตรง
$borrowService->createBorrow($_POST);  // ผิด

// ✓ Service return array หรือ throw Exception
return ['success' => true, 'borrowed' => $borrowedBooks];
throw new Exception('ผู้ยืมถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว');

// ✓ Repository return array หรือ null
return $stmt->fetch() ?: null;

// ✓ API endpoint ส่ง user_id จาก session เท่านั้น
$userId = $_SESSION['user_id'];  // ไม่ใช่ $_POST['user_id']
```

---

## 3. Core Flows (เพื่อการศึกษา)

### 3.1 Login (เข้าสู่ระบบ)

**Goal:** ให้ผู้ใช้ authenticate ด้วย email/password แล้วสร้าง session

**Entry Point:** `login.php` → `POST`

**Inputs + Validation:**

| Field | Required | Validation |
|-------|----------|------------|
| `email` | Yes | ไม่ว่าง (`empty()` check) |
| `password` | Yes | ไม่ว่าง (`empty()` check) |
| `csrf_token` | Yes | `validateCSRFToken()` |

**Authorization / Guards:**
- ไม่ต้อง login (หน้า public)
- ถ้า login อยู่แล้ว → redirect ไป `index.php`
- Rate limit: `checkRateLimit('login_' . md5($email))` → 5 ครั้ง / 15 นาที

**Steps การทำงาน:**
1. `require bootstrap.php` (โหลดทุกอย่าง)
2. ตรวจ `isLoggedIn()` → ถ้า true redirect ออก
3. รับ POST → `validateCSRFToken()`
4. ตรวจ `empty($email)`, `empty($password)`
5. `checkRateLimit('login_' . md5($email))` → ถ้าเกิน return error
6. `AuthService::login($email, $password)`:
   - `UserRepository::findByEmail($email)` → ถ้าไม่พบ return null
   - `password_verify($password, $user['password'])` → ถ้าไม่ตรง return null
7. ถ้าสำเร็จ: `resetRateLimit()` → `session_regenerate_id(true)` → เก็บ `$_SESSION[user_id, user_name, role]` → redirect ตาม role
8. ถ้าล้มเหลว: `incrementRateLimit()` → แสดง "อีเมลหรือรหัสผ่านไม่ถูกต้อง"

**DB Changes:** Read only (`users` table — SELECT by email)

**Output:**

| Case | Response |
|------|----------|
| Success (admin/staff) | 302 redirect → `/admin/` |
| Success (member) | 302 redirect → `/index.php` |
| Failure | แสดง error "อีเมลหรือรหัสผ่านไม่ถูกต้อง" |

**Common Failure Cases:**
- Wrong credentials → ข้อความเดียวกันทุกกรณี (ป้องกัน user enumeration)
- Rate limit exceeded → "ลองผิดหลายครั้งเกินไป กรุณารอ 15 นาที"

**จุดระวัง (Invariants):**
- **ห้ามแยก error message** ระหว่าง "email ไม่พบ" กับ "password ผิด" → ป้องกัน user enumeration attack
- **ห้ามลบ `session_regenerate_id(true)`** → ป้องกัน session fixation attack
- Rate limit key ใช้ `md5($email)` → เปลี่ยน key = reset counter

**Test Steps:**

| Test | Input | Expected |
|------|-------|----------|
| Happy path | email: admin@library.com, password: 123456 | redirect ไป `/admin/` |
| Wrong password | email: admin@library.com, password: wrong | "อีเมลหรือรหัสผ่านไม่ถูกต้อง" |
| Rate limit | ใส่ผิด 6 ครั้ง | "ลองผิดหลายครั้งเกินไป กรุณารอ 15 นาที" |
| Already logged in | เปิด /login.php ขณะ login อยู่ | redirect ไป /index.php |

---

### 3.2 Register (สมัครสมาชิก)

**Goal:** สร้าง account ใหม่เป็น member

**Entry Point:** `register.php` → `POST`

**Inputs + Validation:**

| Field | Required | Validation (function) |
|-------|----------|----------------------|
| `name` | Yes | `validateMemberData()` → ไม่ว่าง, ≤100 chars |
| `email` | Yes | `validateMemberData()` → ไม่ว่าง, `isValidEmail()`, unique |
| `phone` | No | `validateMemberData()` → `isValidPhone()` (9-10 digits) |
| `password` | Yes | `validateMemberData()` → `validatePassword()` (≥6 chars) |
| `confirm_password` | Yes | `$password !== $confirmPassword` |
| `csrf_token` | Yes | `validateCSRFToken()` |

**Authorization / Guards:**
- ไม่ต้อง login (หน้า public)
- Rate limit: `checkRateLimit('register')` → global key (ไม่ใช่ per-email)
- `incrementRateLimit()` เรียก **ก่อน** validation — ป้องกัน bypass ด้วย invalid data

**Steps การทำงาน:**
1. CSRF check
2. Rate limit check (global key "register")
3. `incrementRateLimit()` ก่อน validate
4. `validateMemberData()` (shared helper)
5. ตรวจ confirm_password ตรงกัน
6. `AuthService::register($data)`:
   - delegate → `MemberService::createMember($data)`
   - `validateMemberData()` อีกครั้ง
   - `emailExists()` check
   - `hashPassword($password)` → INSERT users (role='member')
7. สำเร็จ → redirect `/login.php` + flash "สมัครสมาชิกสำเร็จ"

**DB Changes:**

| Table | Operation |
|-------|-----------|
| `users` | INSERT (role='member' hardcoded) |

**จุดระวัง (Invariants):**
- **role='member' hardcoded** ใน `MemberService::createMember()` → ห้ามรับ role จาก user input
- **ต้อง hash password** ผ่าน `hashPassword()` เสมอ
- Rate limit ใช้ global key "register" ไม่ใช่ per-email (เพราะ attacker ใช้ email ใหม่ได้ทุกครั้ง)

**Test Steps:**

| Test | Input | Expected |
|------|-------|----------|
| Happy path | ข้อมูลถูกต้องครบ | redirect ไป /login.php + "สมัครสมาชิกสำเร็จ" |
| Duplicate email | ใช้ email ที่มีแล้ว | "อีเมลนี้ถูกใช้งานแล้ว" |
| Password mismatch | password ≠ confirm_password | "รหัสผ่านไม่ตรงกัน" |
| Short password | password = "123" | "รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร" |

---

### 3.3 Create Borrow (บันทึกการยืม)

**Goal:** Staff บันทึกการยืมหนังสือให้ member — หัก stock + สร้าง borrow record

**Entry Point:** `admin/borrow_form.php` → `POST`

**Inputs + Validation:**

| Field | Required | Validation |
|-------|----------|------------|
| `user_id` | Yes | > 0, must be member role |
| `book_ids[]` | Yes | array ไม่ว่าง, แต่ละ id > 0 |
| `borrow_days` | No | 1-30 (default: `DEFAULT_BORROW_DAYS` = 7) |
| `csrf_token` | Yes | `validateCSRFToken()` |

**Authorization / Guards:**
- `requireStaff()` — ต้องเป็น staff หรือ admin
- Idempotency key: `borrow_{userId}_{md5(bookIds)}` — ป้องกัน double submit

**Steps การทำงาน:**
1. `requireStaff()` → ตรวจสิทธิ์
2. `validateCSRFToken()` → ตรวจ CSRF
3. ตรวจ idempotency key ใน `$_SESSION['processed_actions']`
4. `BorrowService::createBorrow($userId, $bookIds, $borrowDays)`:
   - `beginTransaction()`
   - `userRepo->lockById($userId)` → lock user row
   - `borrowRepo->countActiveBorrowsForUpdate($userId)` → ตรวจ quota
   - ถ้า `currentBorrows >= MAX_BORROW_BOOKS` → throw Exception
   - Loop แต่ละ bookId:
     - `bookRepo->findByIdForUpdate($bookId)` → lock book
     - ตรวจ `$book['available'] > 0`
     - ตรวจ `borrowRepo->isAlreadyBorrowing()` → ไม่ยืมเล่มเดิมซ้ำ
     - `bookRepo->decrementAvailable($bookId)` → มี `WHERE available > 0`
     - `borrowRepo->create([user_id, book_id, borrow_date, due_date])`
   - `commit()`
5. บันทึก idempotency key
6. `setFlash('success')` → `redirect('borrows.php')`

**DB Changes:**

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `borrows` | INSERT | 1 row per book |
| `books` | UPDATE | `available = available - 1` per book |

- **Transaction:** ใช่ (`beginTransaction` / `commit` / `rollback`)
- **Row Locking:** ใช่ (`SELECT ... FOR UPDATE` บน users + books)

**Output:**
- สำเร็จทุกเล่ม → "บันทึกการยืมสำเร็จ X เล่ม | กำหนดคืน: dd/mm/yyyy"
- สำเร็จบางเล่ม → แจ้ง skip + เหตุผล
- ล้มเหลว → error message

**Common Failure Cases:**
- **Double submit:** idempotency key ป้องกัน → "รายการนี้ถูกบันทึกไปแล้ว"
- **Concurrent borrow:** FOR UPDATE lock → คนที่ 2 ต้องรอ
- **Quota exceeded:** throw Exception → rollback ทั้ง transaction
- **Stock หมดระหว่าง transaction:** book ถูก skip พร้อมเหตุผล "(ไม่มีเล่มว่าง)"

**จุดระวัง (Invariants):**
- `decrementAvailable()` มี `WHERE available > 0` → **ห้ามลบ condition นี้** (ป้องกัน stock ติดลบ)
- `MAX_BORROW_BOOKS` อยู่ใน `config.php` → แก้ที่เดียว
- Transaction ต้อง commit/rollback ครบทุก path → ถ้าพัง stock จะไม่ตรง

**Test Steps:**

| Test | Input | Expected |
|------|-------|----------|
| Happy path | member ยืม 0 เล่ม + book available > 0 | "บันทึกการยืมสำเร็จ" + stock ลด |
| Quota full | member ยืมครบ 3 เล่ม + ยืมเพิ่ม | "ผู้ยืมถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว" |
| Stock 0 | เลือก book ที่ available = 0 | book ถูก skip |
| Double submit | กด Submit 2 ครั้งติด | ครั้งที่ 2 → "รายการนี้ถูกบันทึกไปแล้ว" |
| Edge: ยืมเล่มเดิมซ้ำ | เลือก book ที่ user ยืมอยู่แล้ว | book ถูก skip "(ยืมอยู่แล้ว)" |

---

### 3.4 Return Book (คืนหนังสือ)

**Goal:** Staff บันทึกการคืนหนังสือ + คำนวณค่าปรับ + คืน stock

**Entry Point:** `admin/borrows.php` → `POST` (action=return)

**Inputs + Validation:**

| Field | Required | Validation |
|-------|----------|------------|
| `action` | Yes | = 'return' |
| `borrow_id` | Yes | > 0, status='borrowing' |
| `pay_now` | No | checkbox — ถ้ามี = รับชำระทันที |
| `csrf_token` | Yes | `validateCSRFToken()` |

**Authorization / Guards:**
- `requireStaff()`
- Idempotency key: `return_{borrowId}`

**Steps การทำงาน:**
1. CSRF check → idempotency check
2. `BorrowService::returnBook($borrowId, $payNow, $recordedBy)`:
   - `beginTransaction()`
   - `borrowRepo->findByIdForUpdate($borrowId)` → lock + ตรวจ status='borrowing'
   - `calculateFine($dueDate, today)`: `daysOverdue × FINE_PER_DAY`
   - `borrowRepo->markAsReturned($borrowId, $fineAmount)` → status='returned'
   - `bookRepo->incrementAvailable($bookId)` → คืน stock
   - ถ้า `$payNow && $fine > 0`: `paymentRepo->create()` → INSERT payment
   - `commit()`
3. บันทึก idempotency key → redirect

**DB Changes:**

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `borrows` | UPDATE | status='returned', return_date, fine_amount |
| `books` | UPDATE | `available = available + 1` |
| `payments` | INSERT | เฉพาะถ้า pay_now && fine > 0 |

**จุดระวัง (Invariants):**
- **`incrementAvailable()` ต้องเรียกเสมอ** → ไม่งั้น stock ไม่คืน
- สูตรค่าปรับอยู่ที่ `BorrowService::calculateFine()` → `FINE_PER_DAY` อยู่ใน config
- `payments` table มี UNIQUE constraint บน `borrow_id` → ป้องกันจ่ายซ้ำ

**Test Steps:**

| Test | Input | Expected |
|------|-------|----------|
| Happy path (ไม่มีค่าปรับ) | คืนก่อน due_date | "บันทึกการคืนหนังสือสำเร็จ" + stock +1 |
| มีค่าปรับ + จ่ายทันที | คืนหลัง due_date + ติ๊ก pay_now | "...ค่าปรับ X บาท [รับชำระเงินแล้ว]" + INSERT payment |
| มีค่าปรับ + ไม่จ่าย | คืนหลัง due_date + ไม่ติ๊ก | "...ค่าปรับ X บาท [ยังไม่จ่าย]" |
| คืนซ้ำ | กด Return บน borrow ที่ returned แล้ว | "ไม่พบรายการยืมหรือคืนหนังสือแล้ว" |

---

### 3.5 Create Reservation (จองหนังสือ)

**Goal:** Member จองหนังสือ — stock ถูกกันไว้ทันที (หักตอนจอง)

**Entry Point:** `api/reserve_book.php` → `POST` → JSON

**Inputs + Validation:**

| Field | Required | Validation |
|-------|----------|------------|
| `book_id` | Yes | > 0 |
| `csrf_token` | Yes | `validateCSRFToken()` |
| `user_id` | - | จาก `$_SESSION['user_id']` เท่านั้น (ห้ามรับจาก POST) |

**Authorization / Guards:**
- `isLoggedIn()` → 401 ถ้าไม่ login
- Method = POST → 405 ถ้าไม่ใช่
- CSRF token → 403 ถ้าไม่ตรง

**Steps การทำงาน:**
1. ตรวจ auth → method → CSRF → validate input
2. `ReservationService::createReservation($userId, $bookId)`:
   - `reservationRepo->markExpiredReservations()` (คืน stock จากที่หมดอายุก่อน)
   - `beginTransaction()`
   - `bookRepo->findByIdForUpdate($bookId)` → lock book
   - ตรวจ `$book['available'] > 0`
   - ตรวจ `reservationRepo->hasPending($userId, $bookId)` → ไม่จองซ้ำ
   - INSERT reservation (status='pending', `expires_at` = +2 days)
   - `bookRepo->decrementAvailable($bookId)` → หัก stock ทันที
   - `commit()`

**DB Changes:**

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `reservations` | INSERT | status='pending', expires_at |
| `books` | UPDATE | `available - 1` (กัน stock) |

**Output:**

| Case | HTTP | JSON |
|------|------|------|
| Success | 200 | `{"success": true, "message": "จองสำเร็จ! กรุณามารับหนังสือ..."}` |
| Not logged in | 401 | `{"success": false, "message": "กรุณาเข้าสู่ระบบก่อน..."}` |
| Already reserved | 400 | `{"success": false, "message": "คุณได้จองหนังสือเล่มนี้ไว้แล้ว"}` |
| Out of stock | 400 | `{"success": false, "message": "หนังสือหมด ไม่สามารถจองได้"}` |

**จุดระวัง (Invariants):**
- **Stock ถูกหักทันทีตอนจอง** → cancel/expire **ต้อง**คืน stock กลับด้วย `incrementAvailable()`
- `user_id` ต้องมาจาก session เท่านั้น → ห้ามรับจาก POST (ป้องกัน impersonation)
- `expires_at` default +2 days → อยู่ใน `ReservationService` param `$expireDays`

**Test Steps:**

| Test | Input | Expected |
|------|-------|----------|
| Happy path | login เป็น member + book available | `{"success": true}` + stock ลด |
| Not logged in | ไม่ login | HTTP 401 |
| จองซ้ำ | จอง book เดิมที่ pending อยู่ | "คุณได้จองหนังสือเล่มนี้ไว้แล้ว" |
| Stock 0 | book ที่ available = 0 | "หนังสือหมด ไม่สามารถจองได้" |

---

### 3.6 Fulfill Reservation (อนุมัติการจอง)

**Goal:** Staff อนุมัติการจอง → สร้าง borrow record อัตโนมัติ (ไม่หัก stock เพิ่ม)

**Entry Point:** `admin/reservations.php` → `POST` (action=approve)

**Steps การทำงาน:**
1. `requireStaff()` → CSRF check
2. `ReservationService::fulfillReservation($reservationId)`:
   - `beginTransaction()`
   - `reservationRepo->findPendingForUpdate($id)` → lock + ตรวจ status='pending'
   - ตรวจ `borrowRepo->isAlreadyBorrowing()` → ไม่ยืมซ้ำ
   - ตรวจ quota: `borrowRepo->countActiveBorrowsForUpdate()` < `MAX_BORROW_BOOKS`
   - INSERT borrow
   - `reservationRepo->updateStatusWithBorrow($id, 'fulfilled', $borrowId)`
   - `commit()`

**DB Changes:**

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `borrows` | INSERT | สร้าง borrow record ใหม่ |
| `reservations` | UPDATE | status='fulfilled', borrow_id |

> **สำคัญ:** ไม่ต้อง update `books.available` เพราะหักไปแล้วตอนจอง

**จุดระวัง:** ต้องตรวจ quota ก่อนสร้าง borrow → ไม่งั้นยืมเกินได้

---

### 3.7 Update Profile + Change Password

**Goal:** Member/User แก้ข้อมูลส่วนตัว (name, phone) และเปลี่ยนรหัสผ่าน

**Entry Point:** `profile.php` → `POST` (action=update_profile / change_password)

**update_profile Steps:**
1. `requireLogin()` → CSRF check
2. Validate: name ไม่ว่าง, ≤100 chars; phone format (ถ้ากรอก)
3. `AuthService::updateProfile($userId, [name, phone])`:
   - **email ไม่เปลี่ยน** (ดึงจาก DB แล้วใส่กลับ) → ป้องกัน account takeover
4. อัปเดต `$_SESSION['user_name']`

**change_password Steps:**
1. Rate limit: `checkRateLimit('password_change')`
2. `validatePassword($newPassword)` + ตรวจ match confirm
3. `AuthService::changePassword($userId, $current, $new)`:
   - ยืนยันรหัสเดิม (`password_verify`) → ป้องกัน session hijack เปลี่ยนรหัส
   - ตรวจรหัสใหม่ ≠ รหัสเดิม
   - `userRepo->updatePassword($userId, hashPassword($new))`

**จุดระวัง:**
- **Email เปลี่ยนไม่ได้** ผ่านหน้า profile (security by design)
- **ต้องยืนยันรหัสเดิม** ก่อนเปลี่ยน → แม้ session ถูกขโมย ก็เปลี่ยนรหัสไม่ได้

---

### 3.8 Delete Book (ลบหนังสือ)

**Goal:** Staff ลบหนังสือออกจากระบบ

**Entry Point:** `admin/books.php` → `POST` (action=delete)

**Steps การทำงาน:**
1. `requireStaff()` → CSRF check
2. `BookService::deleteBook($id)`:
   - `beginTransaction()`
   - `bookRepo->findByIdForUpdate($id)` → lock row
   - **Guard 1:** `isBeingBorrowed($id)` → `borrowRepo->countActiveByBook()` > 0 ?
   - **Guard 2:** `hasBorrowHistory($id)` → `borrowRepo->countByBook()` > 0 ?
   - **Guard 3:** `reservationRepo->countPendingByBook($id)` > 0 ?
   - ถ้าผ่านทั้ง 3 → `bookRepo->delete($id)` → `commit()`
   - **หลัง commit:** ลบไฟล์ cover_image (ถ้ามี)

**จุดระวัง:** ลบรูปหลัง commit เท่านั้น → ป้องกัน orphan file ถ้า DB rollback

---

## 4. Single Source of Truth Map

### 4.1 ตาราง Mapping

| Concern | Single Source | ไฟล์ | หมายเหตุ |
|---------|-------------|------|---------|
| **Auth check** | `isLoggedIn()`, `isStaff()`, `isAdmin()` | `includes/functions.php` | ตรวจจาก `$_SESSION` เท่านั้น |
| **Access guard (web)** | `requireLogin()`, `requireStaff()`, `requireAdmin()` | `includes/functions.php` | redirect ถ้าไม่มีสิทธิ์ |
| **Access guard (API)** | `requireStaffApi()`, `requireAdminApi()` | `includes/functions.php` | JSON 403 ถ้าไม่มีสิทธิ์ |
| **CSRF token** | `generateCSRFToken()`, `validateCSRFToken()` | `includes/functions.php` | Per-session token, `hash_equals()` |
| **Rate limiting** | `checkRateLimit()`, `incrementRateLimit()`, `resetRateLimit()` | `includes/functions.php` | DB-based (ตาราง `rate_limits`), key+IP |
| **Password hash** | `hashPassword()` | `includes/functions.php` | `password_hash(PASSWORD_DEFAULT)` |
| **Password validation** | `validatePassword()` | `includes/functions.php` | ≥ `MIN_PASSWORD_LENGTH` |
| **Member validation** | `validateMemberData()` | `includes/functions.php` | name, email, phone, password รวมจุดเดียว |
| **Email validation** | `isValidEmail()` | `includes/functions.php` | `FILTER_VALIDATE_EMAIL` |
| **Phone validation** | `isValidPhone()` | `includes/functions.php` | regex `^[0-9]{9,10}$` |
| **XSS protection** | `e()` | `includes/functions.php` | `htmlspecialchars(ENT_QUOTES, UTF-8)` |
| **Business rules** | Constants | `includes/config.php` (อ่านจาก `.env`) | `MAX_BORROW_BOOKS`, `FINE_PER_DAY` ฯลฯ |
| **Fine calculation** | `calculateFine()` | `app/Services/BorrowService.php` | `daysOverdue × FINE_PER_DAY` |
| **Stock management** | `decrementAvailable()`, `incrementAvailable()` | `app/Repositories/BookRepository.php` | มี WHERE guard ป้องกันติดลบ/เกิน |
| **SQL queries** | Repository methods | `app/Repositories/*.php` | ห้ามเขียน SQL ที่อื่น |
| **DB connection** | `getDB()` | `includes/db.php` | Singleton pattern |
| **Session** | `startSession()` | `includes/functions.php` | เรียกอัตโนมัติท้ายไฟล์ |
| **Idempotency** | `$_SESSION['processed_actions']` | Entry Points | key + timestamp, cleanup ใน `bootstrap.php` |

### 4.2 จุดที่พบ Validation ซ้ำ (พร้อมเหตุผล)

| จุดที่ซ้ำ | ตำแหน่ง | ทำไมซ้ำได้/จำเป็น |
|---------|---------|------------------|
| Member data validation | `register.php` + `MemberService::createMember()` | **ซ้ำได้:** ทั้งคู่เรียก `validateMemberData()` ซึ่งเป็น shared helper → เป็น single source จริงๆ |
| Book available check | UI (Select2 แสดงเฉพาะ available) + `BorrowService::borrowSingleBook()` | **จำเป็น:** UI กรองให้ UX ดี แต่ Service ต้องตรวจอีกครั้งภายใต้ lock เพราะ concurrent access |
| Email exists check | `register.php` (ผ่าน validateMemberData) + `MemberService::createMember()` | **จำเป็น:** Entry point เช็คเร็วๆ ก่อน Service ทำ authoritative check |
| Quota check | UI (แสดง "ยืมได้อีก X") + `BorrowService::createBorrow()` | **จำเป็น:** UI เป็น hint, Service ตรวจภายใต้ lock (FOR UPDATE) |

---

## 5. Debug Playbook

### 5.1 เปิด/ปิด Debug Mode

**เปิด debug:**
1. สร้างไฟล์ `.env` จาก `.env.example` (ถ้ายังไม่มี)
2. แก้: `APP_DEBUG=true`
3. ผลลัพธ์: error details แสดงบนหน้าเว็บ

**ปิด debug (production):**
- `APP_DEBUG=false` (หรือไม่มี `.env`)
- ผลลัพธ์: แสดง "ระบบขัดข้อง กรุณาติดต่อผู้ดูแลระบบ"

**ที่มาในโค้ด:**
- `includes/config.php` บรรทัด 82: `define('APP_DEBUG', env('APP_DEBUG', 'false') === 'true');`
- `bootstrap.php` บรรทัด 96-102: `if (APP_DEBUG) { error_reporting(E_ALL); ini_set('display_errors', '1'); }`
- `includes/db.php` บรรทัด 62-67: DB error ซ่อน/แสดงตาม `APP_DEBUG`

### 5.2 Log อยู่ที่ไหน

| ประเภท | ตำแหน่ง |
|--------|---------|
| PHP errors | XAMPP: `C:\xampp\apache\logs\error.log` |
| Custom error_log() | เขียนไปที่ Apache error log |
| DB Connection Error | `includes/db.php` → `error_log("DB Connection Error: " . ...)` |
| Application logs | `logs/` folder (มี `.gitignore` ไว้) |

### 5.3 วิธีไล่ Debug ตาม Error Type

#### HTTP 400 Bad Request
```
1. อ่าน response body → error message จะบอกว่าอะไรผิด
2. ตรวจ input validation ใน entry point file
3. ตรวจ Service → throw new Exception('...') ← ข้อความมาจากนี่
4. ดู API: json_encode(['success' => false, 'message' => $e->getMessage()])
```

#### HTTP 401 Unauthorized
```
1. ตรวจว่า user login อยู่ไหม → $_SESSION['user_id'] มีค่าไหม
2. ดู session timeout → SESSION_LIFETIME = 3600 (1 ชั่วโมง)
3. ตรวจ startSession() → inactivity timeout อาจ clear session
4. API endpoint: ตรวจ isLoggedIn() ที่บรรทัดแรก
```

#### HTTP 403 Forbidden
```
1. CSRF token → อาจหมดอายุ (เปิด tab ค้างนาน) → ลอง refresh หน้า
2. Role check → ตรวจ requireStaff() / requireAdmin() ที่ entry point
3. API: ตรวจ requireStaffApi() / requireAdminApi()
4. ดู $_SESSION['role'] ว่าตรงกับที่ check ไหม
```

#### HTTP 500 Internal Server Error
```
1. เปิด APP_DEBUG=true → ดู error ที่แสดงบนหน้าเว็บ
2. ดู Apache error log
3. สาเหตุที่พบบ่อย:
   a. PDOException → DB connection fail / SQL syntax error
   b. Transaction ไม่ commit/rollback → connection hang
   c. File permission → uploads/ หรือ logs/ ไม่มี write permission
   d. Missing require → file path ผิด
```

### 5.4 ตัวอย่าง Debug ด้วย curl

**1. ทดสอบ Login:**
```bash
curl -X POST "http://localhost/book_borrowing/login.php" \
  -d "email=admin@library.com&password=123456&csrf_token=NEED_REAL_TOKEN" \
  -c cookies.txt -L -v
```

**2. ทดสอบ Search Books API (GET — ไม่ต้อง login):**
```bash
curl "http://localhost/book_borrowing/api/search_books.php?search=php&category=1"
```

**3. ทดสอบ Reserve Book API (ต้อง login + CSRF):**
```bash
# Step 1: Login ก่อน
curl -X POST "http://localhost/book_borrowing/login.php" \
  -d "email=member@test.com&password=123456" \
  -c cookies.txt -L

# Step 2: ดึง CSRF token จาก session (ต้อง parse จาก HTML)
# Step 3: Reserve
curl -X POST "http://localhost/book_borrowing/api/reserve_book.php" \
  -d "book_id=1&csrf_token=REAL_TOKEN_FROM_SESSION" \
  -b cookies.txt
```

### 5.5 Debug Checklist (เวลาระบบพัง)

```
□ APP_DEBUG=true ใน .env?
□ Apache error log checked?
□ PHP syntax error? (ลอง php -l filename.php)
□ DB connection works? (ตรวจ .env → DB_HOST, DB_NAME, DB_USER, DB_PASS)
□ Session started? (startSession() เรียกจาก functions.php อัตโนมัติ)
□ CSRF token valid? (ลอง refresh หน้าก่อน submit)
□ User has correct role? ($_SESSION['role'])
□ Transaction commit/rollback ครบทุก path?
□ File permissions? (uploads/, logs/ ต้อง writable)
□ Rate limit ไม่ได้ block อยู่? (ตาราง rate_limits)
```

---

## 6. Modification Guide (แก้ระบบแบบไม่พัง)

### 6.1 แก้ Business Rule

| ต้องการแก้ | แก้ที่ | ตัวอย่าง |
|-----------|--------|---------|
| จำนวนวันยืม | `.env` → `DEFAULT_BORROW_DAYS` | `DEFAULT_BORROW_DAYS=14` |
| ยืมสูงสุดกี่เล่ม | `.env` → `MAX_BORROW_BOOKS` | `MAX_BORROW_BOOKS=5` |
| ค่าปรับต่อวัน | `.env` → `FINE_PER_DAY` | `FINE_PER_DAY=20` |
| สูตรค่าปรับ | `app/Services/BorrowService.php` → `calculateFine()` | เช่น เพิ่ม cap สูงสุด |
| อายุการจอง | `app/Services/ReservationService.php` → param `$expireDays` | default = 2 days |

### 6.2 แก้ Validation

| ต้องการแก้ | แก้ที่ | Function |
|-----------|--------|----------|
| Password ขั้นต่ำ | `.env` → `MIN_PASSWORD_LENGTH` | ใช้โดย `validatePassword()` |
| Email format | `includes/functions.php` | `isValidEmail()` |
| Phone format | `includes/functions.php` | `isValidPhone()` |
| Name max length | `includes/functions.php` | `validateName()` / `validateMaxLength()` |
| Member data ทั้งชุด | `includes/functions.php` | `validateMemberData()` |

### 6.3 แก้ Permission

| ต้องการแก้ | แก้ที่ | วิธี |
|-----------|--------|------|
| เปลี่ยน access level ของหน้า | Entry Point | เปลี่ยน `requireStaff()` ↔ `requireAdmin()` |
| เพิ่ม role ใหม่ | `database/schema.sql` | แก้ ENUM ของ `role` column |
| | `includes/functions.php` | เพิ่ม `isNewRole()` + `requireNewRole()` |
| ให้ member เข้าหน้า admin ได้ | **ห้ามทำ** | อาจทำให้ข้อมูลรั่ว — ควรสร้าง role แยก |

### 6.4 แก้ SQL

| ต้องการแก้ | แก้ที่ Repository |
|-----------|-------------------|
| Query หนังสือ | `app/Repositories/BookRepository.php` |
| Query การยืม | `app/Repositories/BorrowRepository.php` |
| Query user | `app/Repositories/UserRepository.php` |
| Query การจอง | `app/Repositories/ReservationRepository.php` |
| Query การชำระ | `app/Repositories/PaymentRepository.php` |
| Query รายงาน | `app/Repositories/ReportRepository.php` |
| Query หมวดหมู่ | `app/Repositories/CategoryRepository.php` |
| Query ตั้งค่า | `app/Repositories/SettingsRepository.php` |

**กฎ: SQL ต้องอยู่ใน Repository เท่านั้น + ใช้ Prepared Statements (`?` placeholder) เสมอ**

### 6.5 เพิ่ม Field ใหม่ (ตัวอย่าง: เพิ่ม `publisher` ในตาราง `books`)

```
□ 1. Database
   └── สร้างไฟล์ database/migrations/XXX_add_publisher_to_books.sql
       ALTER TABLE books ADD COLUMN publisher VARCHAR(100) DEFAULT NULL;
   └── รัน migration

□ 2. Repository
   └── app/Repositories/BookRepository.php
       □ แก้ create() → เพิ่ม publisher ใน INSERT
       □ แก้ update() → เพิ่ม publisher ใน UPDATE
       (findById, findAll ใช้ SELECT * อยู่แล้ว → ไม่ต้องแก้)

□ 3. Service (ถ้ามี validation)
   └── app/Services/BookService.php
       □ createBook(), updateBook() → ส่ง $data['publisher'] ต่อให้ repo

□ 4. Entry Point (Form)
   └── admin/book_form.php
       □ เพิ่ม <input name="publisher">
       □ รับค่า $_POST['publisher']
       □ ส่งให้ Service/Repository

□ 5. Entry Point (Display)
   └── admin/books.php, book.php
       □ แสดง <?= e($book['publisher']) ?>

□ 6. ทดสอบ
   □ Create: เพิ่มหนังสือใหม่พร้อม publisher
   □ Read: ดูรายการหนังสือ → เห็น publisher
   □ Update: แก้ไข publisher
   □ Delete: ลบหนังสือ → ไม่กระทบ field นี้
```

### 6.6 เพิ่ม API Endpoint ใหม่

```
□ 1. สร้างไฟล์ api/new_endpoint.php

□ 2. ใช้ Template:
```

```php
<?php
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json');

// 1. Auth
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 2. Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// 3. CSRF (POST only)
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

// 4. Validate input
$input = trim($_POST['input'] ?? '');
if (empty($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Input required']);
    exit;
}

// 5. Call Service
try {
    $pdo = getDB();
    $service = new SomeService($pdo);
    $result = $service->doSomething($input);
    echo json_encode(['success' => true, 'data' => $result]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
```

```
□ 3. ทดสอบ
   □ Happy path ด้วย curl
   □ ทดสอบ 401, 403, 405, 400 ทุก path
```

### 6.7 Decision Matrix: แก้ 1 จุดต้องแก้กี่ที่

| ต้องการ | DB Migration | Repository | Service | Entry Point | Config/.env |
|--------|:---:|:---:|:---:|:---:|:---:|
| เพิ่ม field | ✅ | ✅ | ถ้ามี logic | ✅ | - |
| เพิ่ม table | ✅ | ✅ ใหม่ | ✅ ใหม่ | ✅ ใหม่ | - |
| เปลี่ยน business rule | - | - | ✅ | - | ✅ ถ้าเป็น constant |
| เปลี่ยน validation | - | - | ถ้า complex | ✅ | ✅ ถ้าเป็น limit |
| เปลี่ยน permission | - | - | - | ✅ | - |
| เปลี่ยน UI | - | - | - | ✅ | - |
| เพิ่ม API | - | อาจต้อง | อาจต้อง | ✅ ใหม่ | - |

---

## 7. Quick Reference

### 7.1 Helper Functions สำคัญ

```php
// ===== Security =====
e($string)                           // Escape HTML (ป้องกัน XSS) — ใช้ทุกครั้งที่แสดงผล
generateCSRFToken()                  // สร้าง CSRF token (per-session)
validateCSRFToken($token)            // ตรวจ CSRF (hash_equals)
hashPassword($plain)                 // Hash password (PASSWORD_DEFAULT)

// ===== Auth =====
isLoggedIn()                         // ตรวจว่า login อยู่ไหม
isAdmin()                            // ตรวจ role = admin
isStaff()                            // ตรวจ role = admin หรือ staff
requireLogin()                       // บังคับ login (redirect ถ้าไม่)
requireStaff()                       // บังคับ staff+ (redirect ถ้าไม่)
requireAdmin()                       // บังคับ admin (redirect ถ้าไม่)
requireStaffApi()                    // บังคับ staff+ (JSON 403 ถ้าไม่)

// ===== Validation =====
validateMemberData($data, $isEdit)   // ตรวจ name, email, phone, password รวมจุดเดียว
validatePassword($pw, $allowEmpty)   // ตรวจ password (≥ MIN_PASSWORD_LENGTH)
isValidEmail($email)                 // FILTER_VALIDATE_EMAIL
isValidPhone($phone)                 // regex 9-10 digits
validateName($name, $maxLen)         // ไม่ว่าง + ≤ maxLen
validateMaxLength($val, $max, $name) // ตรวจความยาว

// ===== Rate Limiting =====
checkRateLimit($key, $max, $window)  // ตรวจว่าเกิน limit ไหม (DB-based)
incrementRateLimit($key)             // เพิ่ม counter
resetRateLimit($key)                 // reset counter (หลัง success)

// ===== Flash Messages =====
setFlash($type, $message)            // ตั้ง flash (success/error/warning/info)
getFlash()                           // ดึง flash + ลบ (ใช้ครั้งเดียว)
displayFlash()                       // แสดง flash เป็น HTML

// ===== Formatting =====
formatDate($date, $format)           // จัดรูปแบบวันที่ (default: d/m/Y)
formatFine($amount)                  // จัดรูปแบบค่าปรับ ("150 บาท")
daysDiff($date1, $date2)             // คำนวณจำนวนวัน

// ===== Navigation =====
redirect($url)                       // redirect + exit ทันที
```

### 7.2 Config Constants สำคัญ

```php
// Database
DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHARSET

// Application
APP_NAME          // "ระบบยืมคืนหนังสือ"
APP_URL           // "http://localhost/book_borrowing"
APP_DEBUG         // true/false

// Business Rules (แก้ใน .env)
DEFAULT_BORROW_DAYS      // 7  → วันยืมเริ่มต้น
MAX_BORROW_BOOKS         // 3  → ยืมสูงสุดต่อคน
FINE_PER_DAY             // 10 → ค่าปรับต่อวัน (บาท)

// Security (แก้ด้วยความระวัง)
MIN_PASSWORD_LENGTH      // 6
RATE_LIMIT_MAX_ATTEMPTS  // 5
RATE_LIMIT_WINDOW_MINUTES // 15
SESSION_LIFETIME         // 3600 (1 ชั่วโมง)
```

### 7.3 Invariants ระดับระบบ (ห้ามพัง)

| # | Invariant | ทำไมสำคัญ | ป้องกันอย่างไร |
|---|-----------|-----------|---------------|
| 1 | `books.available` ต้อง ≥ 0 เสมอ | stock ติดลบ = ยืมเกินจริง | `decrementAvailable()` มี `WHERE available > 0` |
| 2 | `books.available` ต้อง ≤ `books.quantity` | available เกิน quantity = ข้อมูลเพี้ยน | `incrementAvailable()` มี `WHERE available < quantity` |
| 3 | คืนหนังสือต้องคืน stock เสมอ | ไม่คืน stock = จำนวนว่างไม่ตรง | `returnBook()` เรียก `incrementAvailable()` ใน transaction |
| 4 | ยกเลิก/หมดอายุการจองต้องคืน stock เสมอ | จอง = หัก stock แล้ว | `cancelReservation()`, `expireOverdueReservations()` เรียก `incrementAvailable()` |
| 5 | Transaction ต้อง commit หรือ rollback ทุก path | ไม่จบ = connection hang + data inconsistent | try/catch + rollback ใน catch block ของทุก Service method |
| 6 | Password ต้อง hash ด้วย `hashPassword()` เสมอ | เก็บ plaintext = data breach | `hashPassword()` เป็น single source of truth |
| 7 | `user_id` ใน API ต้องมาจาก session เท่านั้น | รับจาก POST = impersonation attack | `$userId = $_SESSION['user_id']` ใน `api/reserve_book.php` |
| 8 | Error message login ห้ามแยก email/password | แยก = user enumeration | `AuthService::login()` return null ทั้งสองกรณี |

---

## 8. สรุปท้ายเอกสาร

### Flows ที่ครอบคลุมแล้ว

| # | Flow | ประเภท | Section |
|---|------|--------|---------|
| 1 | Login | Auth | 3.1 |
| 2 | Register | Auth | 3.2 |
| 3 | Create Borrow | Core (Transaction + Lock) | 3.3 |
| 4 | Return Book | Core (Transaction + Fine) | 3.4 |
| 5 | Create Reservation | API (Transaction + Lock) | 3.5 |
| 6 | Fulfill Reservation | Admin (State Transition) | 3.6 |
| 7 | Update Profile + Change Password | User Management | 3.7 |
| 8 | Delete Book | Admin CRUD (Guards) | 3.8 |

### ส่วนที่ยังไม่ได้ศึกษาเชิงลึก

| ส่วน | เหตุผล | ไฟล์ที่เกี่ยวข้อง |
|------|--------|-----------------|
| Forgot/Reset Password | Flow คล้าย auth ทั่วไป + token-based | `forgot_password.php`, `reset_password.php`, `AuthService::requestPasswordReset()` |
| Import CSV (Books/Members) | UI-heavy, logic ไม่ซับซ้อน | `admin/import_books.php`, `admin/import_members.php` |
| Reports | Read-only query aggregation | `admin/reports.php`, `ReportService.php`, `ReportRepository.php` |
| Settings | Simple CRUD ของ key-value | `admin/settings.php`, `SettingsRepository.php` |
| Categories | Simple CRUD ไม่มี transaction | `admin/categories.php`, `CategoryRepository.php` |
| Cron jobs | Standalone scripts | `cron/expire_reservations.php`, `cron/cleanup_tokens.php` |
| Cancel Reservation (by member) | คล้าย admin cancel แต่เพิ่ม owner check | `ReservationService::cancelReservation($id, $userId)` |
| Pay Fine (ภายหลัง) | `BorrowService::payFine()` — คล้าย returnBook แต่ง่ายกว่า | `admin/borrows.php` |

---

*เอกสารนี้สร้างจากโค้ดจริงในโปรเจกต์ทั้งหมด — ทุกไฟล์/ฟังก์ชัน/ค่าคงที่ที่กล่าวถึงมีอยู่จริงในระบบ*
