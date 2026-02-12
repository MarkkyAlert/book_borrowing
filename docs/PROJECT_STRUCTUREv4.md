# PROJECT STRUCTURE v4

> เอกสารอธิบายโครงสร้างโปรเจกต์ **ระบบยืมคืนหนังสือ** เพื่อให้เจ้าของโปรเจกต์เข้าใจและอ่านโค้ดต่อได้
> อ้างอิงจากโค้ดจริงทั้งหมด — ไม่มีส่วนที่เดาหรือแต่งขึ้น

---

## 1. ภาพรวมสถาปัตยกรรม

```
Browser Request
      │
      ▼
┌─────────────────────────────────────────────────┐
│  Entry Points (Controllers / API Handlers)      │
│  *.php (root), admin/*.php, api/*.php           │
│  ─ รับ request, ตรวจสิทธิ์, validate input      │
│  ─ เรียก Service, render HTML หรือ JSON         │
└────────────────────┬────────────────────────────┘
                     │ เรียกผ่าน method call
                     ▼
┌─────────────────────────────────────────────────┐
│  Services  (app/Services/)                      │
│  ─ Business logic, validation rules             │
│  ─ จัดการ transaction, คำนวณค่าปรับ, concurrency│
│  ─ เรียก Repository 1+ ตัว                      │
└────────────────────┬────────────────────────────┘
                     │ เรียกผ่าน method call
                     ▼
┌─────────────────────────────────────────────────┐
│  Repositories  (app/Repositories/)              │
│  ─ SQL queries (prepared statements)            │
│  ─ CRUD, row locking (FOR UPDATE), aggregation  │
│  ─ ไม่มี business logic                         │
└────────────────────┬────────────────────────────┘
                     │ PDO
                     ▼
┌─────────────────────────────────────────────────┐
│  MySQL Database                                 │
│  tables: users, books, categories, borrows,     │
│  reservations, payments, password_resets,        │
│  settings, rate_limits                           │
└─────────────────────────────────────────────────┘
```

**กฎเหล็ก:** ห้ามข้ามเลเยอร์ — Controller/API ห้ามเขียน SQL โดยตรง, Repository ห้ามมี business logic

---

## 2. โครงสร้างโฟลเดอร์

```
book_borrowing/
│
├── *.php (root)            # หน้าเว็บ public (ไม่ต้อง login หรือ login ทุก role)
├── admin/                  # หน้า admin/staff (ต้อง login + ตรวจ role)
├── api/                    # JSON/HTML API endpoints (AJAX)
├── app/                    # Business logic layer (ห้ามเข้าถึงจาก web โดยตรง)
│   ├── Services/           #   Business logic + orchestration
│   └── Repositories/       #   Database access (SQL queries)
├── includes/               # Infrastructure: config, DB, helpers, templates
├── database/               # SQL schema + migrations + sample data
├── cron/                   # Scheduled tasks (CLI only)
├── css/                    # Custom stylesheet
├── uploads/                # User-uploaded files (book covers)
├── logs/                   # Application logs (cron, errors)
├── tests/                  # Test scripts + audit reports
├── docs/                   # Documentation
├── bootstrap.php           # จุดเริ่มต้น — ทุกหน้า require ไฟล์นี้
├── install.php             # Database installer (ใช้ครั้งเดียว)
├── .env                    # Environment config (ไม่ commit)
├── .env.example            # Template สำหรับสร้าง .env
└── .htaccess               # Security rules (ป้องกันเข้าถึงไฟล์สำคัญ)
```

---

## 3. บทบาทของแต่ละโฟลเดอร์

### 3.1 Root `*.php` — หน้าเว็บ Public

| ไฟล์ | บทบาท | สิทธิ์ |
|------|--------|--------|
| `index.php` | หน้าแรก — แสดงรายการหนังสือ + filter + sidebar หมวดหมู่ | ไม่ต้อง login |
| `book.php` | รายละเอียดหนังสือ + ปุ่มจอง (AJAX → `api/reserve_book.php`) | ไม่ต้อง login (จองต้อง login) |
| `login.php` | เข้าสู่ระบบ — rate limit, CSRF, session regeneration | ไม่ต้อง login |
| `register.php` | สมัครสมาชิก — validate + hash password | ไม่ต้อง login |
| `logout.php` | ออกจากระบบ — destroy session | ต้อง login |
| `profile.php` | โปรไฟล์ — แก้ข้อมูล, เปลี่ยนรหัสผ่าน, ดูประวัติยืม | ต้อง login |
| `my_borrows.php` | ประวัติการยืมของ user ที่ login | ต้อง login |
| `my_reservations.php` | ประวัติการจองของ user ที่ login | ต้อง login |
| `forgot_password.php` | ขอ reset password (สร้าง token) | ไม่ต้อง login |
| `reset_password.php` | ตั้งรหัสผ่านใหม่ (ตรวจ token) | ไม่ต้อง login |
| `install.php` | ติดตั้ง database — ใช้ครั้งเดียว (มี lock file `.installed`) | ไม่ต้อง login |

**Pattern:** ทุกไฟล์เริ่มด้วย `require_once __DIR__ . '/bootstrap.php'` แล้วสร้าง Service instance → เรียก method → render HTML

---

### 3.2 `admin/` — หน้า Admin/Staff

| ไฟล์ | บทบาท | สิทธิ์ |
|------|--------|--------|
| `index.php` | Dashboard — summary cards, charts, alerts, overdue lists | staff+ |
| `books.php` | รายการหนังสือ + filter | staff+ |
| `book_form.php` | เพิ่ม/แก้ไขหนังสือ (CRUD form) | staff+ |
| `borrows.php` | รายการยืม + ปุ่มคืนหนังสือ | staff+ |
| `borrow_form.php` | บันทึกการยืม (รองรับ multi-book, AJAX scan) | staff+ |
| `reservations.php` | รายการจอง + ปุ่มอนุมัติ/ยกเลิก | staff+ |
| `members.php` | รายการสมาชิก + filter | staff+ |
| `member_form.php` | เพิ่ม/แก้ไขสมาชิก | staff+ |
| `categories.php` | จัดการหมวดหมู่ (CRUD inline) | staff+ |
| `payments.php` | ค้างชำระ + ประวัติชำระค่าปรับ | staff+ |
| `reports.php` | รายงาน 6 ประเภท + CSV export | admin only |
| `export_pdf.php` | Print-friendly HTML → browser print dialog → PDF | admin only |
| `settings.php` | ตั้งค่าระบบ (org_name, card_color) | admin only |
| `import_books.php` | นำเข้าหนังสือจาก CSV | staff+ |
| `import_members.php` | นำเข้าสมาชิกจาก CSV | staff+ |
| `book_labels.php` | พิมพ์ฉลากหนังสือ | staff+ |
| `member_card.php` | พิมพ์บัตรสมาชิก | staff+ |
| `header.php` | Layout template: `<head>`, navbar, sidebar | — |
| `footer.php` | Layout template: ปิด `</main>`, scripts | — |

**Pattern:** ทุกไฟล์เริ่มด้วย `require bootstrap.php` → `requireStaff()` หรือ `requireAdmin()` → สร้าง Service → POST handler (ถ้ามี) → GET data → `require header.php` → HTML → `require footer.php`

**PRG Pattern:** ทุกหน้าที่มี POST จะทำ POST action **ก่อน** fetch data (เพื่อให้ data เป็น version ล่าสุด) แล้ว redirect กลับ (Post/Redirect/Get)

---

### 3.3 `api/` — AJAX Endpoints

| ไฟล์ | Method | บทบาท | สิทธิ์ |
|------|--------|--------|--------|
| `reserve_book.php` | POST | จองหนังสือ → JSON | ต้อง login |
| `cancel_reservation.php` | POST | ยกเลิกการจอง → redirect | ต้อง login |
| `search_books.php` | GET | ค้นหาหนังสือ → HTML partial | ไม่ต้อง login |
| `add_member.php` | POST | Quick add สมาชิก → JSON | staff+ |
| `member_history.php` | GET | ประวัติยืมของสมาชิก → JSON | staff+ |

**Pattern:** ทุกไฟล์ทำหน้าที่ **Controller** เท่านั้น:
1. ตรวจ HTTP method
2. ตรวจ auth + CSRF
3. Validate input
4. เรียก Service หรือ Repository
5. ส่ง JSON หรือ HTML response

---

### 3.4 `app/Services/` — Business Logic

| Service | บทบาท | Repositories ที่ใช้ |
|---------|--------|---------------------|
| `AuthService` | Login, register, forgot/reset password, change password, update profile | UserRepository, PasswordResetRepository |
| `BookService` | CRUD หนังสือ, integrity checks (ห้ามลบถ้ามีคนยืม/จอง) | BookRepository, BorrowRepository, ReservationRepository |
| `BorrowService` | สร้างการยืม (multi-book), คืนหนังสือ (คำนวณค่าปรับ), ชำระค่าปรับ | BookRepository, BorrowRepository, UserRepository, PaymentRepository |
| `ReservationService` | สร้าง/ยกเลิก/อนุมัติการจอง, expire reservations | BookRepository, ReservationRepository, BorrowRepository |
| `MemberService` | CRUD สมาชิก, import, integrity checks | UserRepository, BorrowRepository, ReservationRepository |
| `DashboardService` | Aggregate สถิติสำหรับ admin dashboard (read-only) | Book/Borrow/User/Category/Reservation/Payment/ReportRepository |
| `HomeService` | ข้อมูลหน้าแรก: books + categories + stats (read-only) | BookRepository, CategoryRepository, UserRepository |
| `ReportService` | Aggregate สถิติสำหรับ reports (read-only) | ReportRepository, BorrowRepository, BookRepository, UserRepository |

**กฎ:** Service รับ `PDO` ทาง constructor → สร้าง Repository instances ภายใน → จัดการ transaction ที่ระดับนี้

---

### 3.5 `app/Repositories/` — Database Access

| Repository | ตารางหลัก | หน้าที่เด่น |
|------------|-----------|------------|
| `UserRepository` | `users` | CRUD, findByEmail, findAllMembers, countByRole |
| `BookRepository` | `books` | CRUD, findAll (filter/sort), increment/decrementAvailable, findByIdForUpdate (row lock) |
| `BorrowRepository` | `borrows` | สร้างการยืม, คืนหนังสือ, countActiveBorrows (FOR UPDATE), findOverdue |
| `ReservationRepository` | `reservations` | สร้าง/ยกเลิก/อนุมัติ (state guards), expireOverdue (lazy expiration), countPending |
| `CategoryRepository` | `categories` | CRUD, countBooks per category |
| `PaymentRepository` | `payments` | บันทึกชำระ (UNIQUE borrow_id), รายการค้างชำระ |
| `ReportRepository` | `borrows` + joins | Read-only queries สำหรับ reports/statistics |
| `SettingsRepository` | `settings` | get/set (upsert), getAll |
| `PasswordResetRepository` | `password_resets` | สร้าง/ตรวจ/ใช้ token, deleteExpired |

**กฎ:** ทุก query ใช้ prepared statements — ห้าม string concatenation สำหรับ user input

**Concurrency Control:** method ที่ต้องป้องกัน race condition ใช้ `SELECT ... FOR UPDATE` ภายใน transaction (เช่น `decrementAvailable`, `returnBook`, `countActiveBorrows`)

---

### 3.6 `includes/` — Infrastructure

| ไฟล์ | บทบาท |
|------|--------|
| `config.php` | อ่าน `.env` → define PHP constants (DB, app, borrow, security, session) |
| `db.php` | PDO connection (Singleton) — `getDB()` + `getDBWithoutDatabase()` สำหรับ install |
| `functions.php` | Helper functions: XSS escape (`e()`), redirect, flash messages, access control (`requireLogin/Staff/Admin`), CSRF, rate limiting, validation, session management |
| `header.php` | Public layout template: `<head>`, navbar (เปลี่ยนตาม login status) |
| `footer.php` | Public layout template: ปิด HTML, load scripts |
| `book_grid.php` | Reusable partial: grid แสดงหนังสือ (ใช้ทั้ง index.php และ search_books API) |
| `modal.js` | Custom modal dialog (แทน native confirm/alert) — Promise-based API |
| `report_helper.php` | Single Source of Truth: mapping report type → {data, headers, filename, title} |

**ลำดับ require (ห้ามเปลี่ยน):**
```
bootstrap.php
  └→ config.php      (1) constants ทั้งหมด
  └→ db.php          (2) PDO connection
  └→ functions.php   (3) helpers + auto-start session
  └→ autoloader      (4) spl_autoload_register
  └→ error reporting (5) ตาม APP_DEBUG
```

---

### 3.7 `database/` — Schema & Migrations

| ไฟล์ | บทบาท |
|------|--------|
| `schema.sql` | Full schema: 8 tables (users, categories, books, borrows, reservations, payments, password_resets, settings, rate_limits) |
| `sample_data.sql` | ข้อมูลตัวอย่างสำหรับ development |
| `migrations/001_*.sql` | เพิ่ม `borrow_id` ใน reservations |
| `migrations/002_*.sql` | เพิ่ม UNIQUE constraint บน payments.borrow_id |
| `migrations/003_*.sql` | เพิ่ม CHECK constraint บน books.available |

---

### 3.8 `cron/` — Scheduled Tasks

| ไฟล์ | บทบาท | ความถี่แนะนำ |
|------|--------|-------------|
| `expire_reservations.php` | Expire การจองที่หมดอายุ + คืน stock | ทุก 15 นาที |
| `cleanup_tokens.php` | ลบ password reset tokens ที่หมดอายุ | วันละครั้ง (ตี 3) |

**หมายเหตุ:** ทั้งสองไฟล์ป้องกัน web access (`php_sapi_name() !== 'cli'`) — เรียกได้เฉพาะจาก CLI

**Fallback:** `admin/index.php` (dashboard) เรียก `expireOverdueReservations()` ทุกครั้งที่โหลด — ถ้า cron ไม่ทำงาน ระบบยังจัดการ expired reservations ได้เมื่อ admin เข้า dashboard

---

### 3.9 โฟลเดอร์อื่น

| โฟลเดอร์ | บทบาท |
|-----------|--------|
| `uploads/` | เก็บรูปปกหนังสือ (cover_image) — gitignored |
| `logs/` | เก็บ log files (cron.log) — gitignored |
| `css/` | `style.css` — custom styles เพิ่มเติมจาก Tailwind CDN |
| `tests/` | Test scripts (integration, reservation, fines, barcode) + audit reports |
| `docs/` | เอกสารประกอบโปรเจกต์ |

---

## 4. Security Layers

```
.htaccess (root)
  ├─ ป้องกันเข้าถึง .env, .sql, .md, .log, .installed
  └─ ปิด directory listing

app/.htaccess
  └─ Require all denied (ห้ามเข้าถึง Services/Repositories จาก web)

includes/.htaccess
  └─ ป้องกัน .php (อนุญาตเฉพาะ .js, .css)

bootstrap.php
  └─ basename check ป้องกันเข้าถึงโดยตรง
```

**Security ในโค้ด:**

| มาตรการ | ตำแหน่ง | รายละเอียด |
|---------|---------|------------|
| XSS Prevention | `e()` ใน functions.php | `htmlspecialchars()` ทุกครั้งที่แสดง user data |
| SQL Injection | ทุก Repository | Prepared statements + `EMULATE_PREPARES=false` |
| CSRF | `generateCSRFToken()` / `validateCSRFToken()` | Per-session token, `hash_equals()` (timing-safe) |
| Session Security | `startSession()` | HttpOnly, SameSite=Lax, Secure (HTTPS), inactivity timeout |
| Rate Limiting | `checkRateLimit()` | DB-based, keyed by IP, best-effort (DB fail → allow) |
| Password Hashing | `hashPassword()` | `password_hash(PASSWORD_DEFAULT)` — Single Source of Truth |
| User Enumeration | `AuthService::login()` | ข้อความ error เดียวกันไม่ว่า email ผิดหรือ password ผิด |
| Access Control | `requireLogin/Staff/Admin()` | Role จาก DB ตอน login (ไม่ใช่ user input) |

---

## 5. เส้นทาง Request → Response (ตัวอย่าง)

### 5.1 ยืมหนังสือ (Staff)

```
admin/borrow_form.php
  │
  ├─ [GET] แสดง form
  │   └→ BookRepository::findAvailable()
  │   └→ UserRepository::findAllMembers()
  │   └→ require header.php → HTML form → require footer.php
  │
  └─ [POST] submit form
      └→ validateCSRFToken()
      └→ BorrowService::createBorrow(userId, bookIds)
          └→ BEGIN TRANSACTION
          └→ BorrowRepository::countActiveBorrows(userId)   ← FOR UPDATE
          └→ ตรวจ quota (MAX_BORROW_BOOKS)
          └→ loop bookIds:
          │   └→ BookRepository::findByIdForUpdate(bookId)  ← FOR UPDATE
          │   └→ ตรวจ available > 0
          │   └→ BorrowRepository::create(borrow)
          │   └→ BookRepository::decrementAvailable(bookId)
          └→ COMMIT
      └→ setFlash('success', '...')
      └→ redirect('borrows.php')
```

### 5.2 จองหนังสือ (Member via AJAX)

```
book.php
  └→ [Click ปุ่มจอง]
      └→ JavaScript fetch → POST api/reserve_book.php
          └→ ตรวจ login + method + CSRF + input
          └→ ReservationService::createReservation(userId, bookId)
              └→ BEGIN TRANSACTION
              └→ ReservationRepository::expireOverdueByBook()  ← lazy expiration
              └→ ตรวจซ้ำ (pending reservation อยู่แล้ว?)
              └→ ตรวจ available > 0
              └→ BookRepository::decrementAvailable()          ← หัก stock ทันที
              └→ ReservationRepository::create()
              └→ COMMIT
          └→ JSON { success: true, message: '...' }
      └→ modalSuccess('...')
```

### 5.3 คืนหนังสือ + คำนวณค่าปรับ

```
admin/borrows.php
  └→ [POST action=return]
      └→ validateCSRFToken()
      └→ BorrowService::returnBook(borrowId)
          └→ BEGIN TRANSACTION
          └→ BorrowRepository::findByIdForUpdate(borrowId)    ← FOR UPDATE
          └→ ตรวจ status = 'borrowing'
          └→ คำนวณค่าปรับ: (วันนี้ - due_date) × FINE_PER_DAY
          └→ BorrowRepository::updateReturn(borrowId, fine)
          └→ BookRepository::incrementAvailable(bookId)       ← คืน stock
          └→ COMMIT
      └→ setFlash('success', '...')
      └→ redirect('borrows.php')
```

### 5.4 ดู Dashboard (Staff)

```
admin/index.php
  └→ requireStaff()
  └→ ReservationService::expireOverdueReservations()  ← fallback for cron
  └→ DashboardService::getCardStats()                 ← read-only aggregate
  └→ DashboardService::getRecentBorrows()
  └→ DashboardService::getOverdueList()
  └→ DashboardService::getMonthlyStats()
  └→ DashboardService::getCategoryStats()
  └→ ... (render HTML with Chart.js)
```

---

## 6. Entry Points ที่ควรอ่านก่อน

อ่านตามลำดับนี้เพื่อเข้าใจโครงสร้างเร็วที่สุด:

| # | ไฟล์ | เหตุผลที่ต้องอ่าน |
|---|------|------------------|
| 1 | **`bootstrap.php`** | จุดเริ่มต้นของทุก request — เห็น require chain, autoloader, error handling |
| 2 | **`includes/config.php`** | Constants ทั้งระบบ — เข้าใจค่า default และวิธีอ่าน .env |
| 3 | **`includes/functions.php`** | Helper ทั้งหมด — เข้าใจ security layer (e, CSRF, rate limit, access control) |
| 4 | **`includes/db.php`** | PDO singleton — เข้าใจ DB connection + options (EMULATE_PREPARES=false) |
| 5 | **`admin/borrows.php`** | ตัวอย่าง controller pattern ที่สมบูรณ์ — POST handler + PRG + Service call |
| 6 | **`app/Services/BorrowService.php`** | ตัวอย่าง Service ที่ซับซ้อนที่สุด — transaction, row lock, fine calculation |
| 7 | **`app/Repositories/BookRepository.php`** | ตัวอย่าง Repository — prepared statements, concurrency control, stock management |
| 8 | **`api/reserve_book.php`** | ตัวอย่าง API endpoint — auth + CSRF + input validation + Service call + JSON response |

---

## 7. Boundary ระหว่างเลเยอร์

### Controller / API Handler → Service

| ฝั่ง Controller ทำ | ฝั่ง Controller **ห้าม**ทำ |
|---------------------|--------------------------|
| ตรวจ HTTP method | เขียน SQL |
| ตรวจ auth (requireStaff, isLoggedIn) | คำนวณค่าปรับ |
| ตรวจ CSRF token | จัดการ transaction |
| Validate input (type cast, empty check) | เช็ค business rules (เช่น quota) |
| เรียก Service method | เรียก Repository โดยตรง* |
| Render HTML หรือ JSON response | — |

> *ข้อยกเว้น: read-only queries ง่ายๆ (เช่น `search_books.php` เรียก `BookRepository::findAll()` โดยตรง เพราะไม่มี business logic)

### Service → Repository

| ฝั่ง Service ทำ | ฝั่ง Service **ห้าม**ทำ |
|-----------------|------------------------|
| เริ่ม/จบ transaction | เขียน SQL |
| ตรวจ business rules (quota, stock, state) | แสดง HTML |
| เรียก Repository methods | อ่าน `$_POST`, `$_GET`, `$_SESSION` |
| จัดการ error (throw / return error array) | Redirect หรือ set flash message |
| Orchestrate หลาย Repository | — |

### Repository → Database

| ฝั่ง Repository ทำ | ฝั่ง Repository **ห้าม**ทำ |
|--------------------|--------------------------|
| เขียน SQL (prepared statements) | ตรวจ business rules |
| Row locking (`FOR UPDATE`) | จัดการ transaction (ยกเว้น lazy expiration)* |
| Return data (array / bool / int) | เรียก Service อื่น |
| State transition guards (SQL WHERE) | อ่าน `$_SESSION` |

> *ข้อยกเว้น: `ReservationRepository::expireOverdueByBook()` จัดการ transaction ภายในเอง เพราะเป็น lazy expiration ที่ต้อง atomic

---

## 8. Database Tables — ER Overview

```
users ─────┬──< borrows >──── books ──── categories
           │       │              │
           │       │              │
           │   payments       reservations
           │   (1:1 borrow)   (pending→fulfilled→expired/cancelled)
           │
           ├──< password_resets
           │
           └──< rate_limits (by IP)

settings (key-value, standalone)
```

**Constraints สำคัญ:**
- `books.available >= 0` (CHECK constraint)
- `books.quantity >= books.available` (CHECK constraint)
- `payments.borrow_id` UNIQUE (ชำระได้ครั้งเดียวต่อ borrow)
- `reservations.borrow_id` FK → borrows (เฉพาะ fulfilled)

---

## 9. Configuration Flow

```
.env.example  ──(copy)──>  .env  ──(read by)──>  config.php  ──(define)──>  PHP Constants
                                                                               │
                                                     ┌────────────────────────┘
                                                     ▼
                                              ใช้ทั่วระบบ:
                                              DB_HOST, DB_NAME, DB_USER, DB_PASS
                                              APP_NAME, APP_URL
                                              DEFAULT_BORROW_DAYS, MAX_BORROW_BOOKS, FINE_PER_DAY
                                              MIN_PASSWORD_LENGTH, RATE_LIMIT_*, SESSION_LIFETIME
                                              APP_DEBUG
```

**ค่าที่ลูกค้ามักต้องการแก้ (แก้ใน .env):**
- `DEFAULT_BORROW_DAYS` — จำนวนวันยืม (default: 7)
- `MAX_BORROW_BOOKS` — ยืมได้สูงสุดกี่เล่ม (default: 3)
- `FINE_PER_DAY` — ค่าปรับต่อวัน (default: 10 บาท)

**ค่าที่ admin ปรับได้ผ่านหน้าเว็บ (เก็บใน DB ตาราง settings):**
- `org_name` — ชื่อหน่วยงาน
- `card_color_*` — สีบัตรสมาชิก

---

## 10. Concurrency Control

ระบบใช้ **database-level locking** ป้องกัน race condition ในจุดวิกฤต:

| สถานการณ์ | วิธีป้องกัน |
|-----------|------------|
| 2 คนยืมหนังสือเล่มเดียวกันพร้อมกัน | `BookRepository::findByIdForUpdate()` → lock row → ตรวจ available > 0 |
| 1 คนยืมเกิน quota | `BorrowRepository::countActiveBorrows()` → `FOR UPDATE` → ตรวจ < MAX_BORROW_BOOKS |
| คืนหนังสือซ้ำ (double-click) | `BorrowRepository::findByIdForUpdate()` → ตรวจ status = 'borrowing' |
| ชำระค่าปรับซ้ำ | `payments.borrow_id` UNIQUE constraint |
| Submit form ซ้ำ (refresh) | Idempotency key (session-based, 5 นาที) |
| CSRF attack | Per-session token + `hash_equals()` |

---

## 11. Template / Layout System

```
[Public Pages]
  require includes/header.php    ← <head>, navbar (เปลี่ยนตาม login/role)
    ... page content ...
  require includes/footer.php    ← scripts, modal.js

[Admin Pages]
  require admin/header.php       ← <head>, top navbar, sidebar menu
    ... page content ...
  require admin/footer.php       ← scripts, modal.js
```

ทั้งสอง layout ใช้:
- **Tailwind CSS** (CDN) — styling หลัก
- **Bootstrap Icons** (CDN) — icons
- **Google Fonts Sarabun** — font ไทย
- **Chart.js** (CDN, เฉพาะ dashboard) — กราฟสถิติ
- **`includes/modal.js`** — custom modal แทน native confirm/alert

---

*สร้างจากโค้ดจริง — ไม่มีส่วนที่เดาหรือแต่งขึ้น*
