# Study Guide V3 - คู่มือศึกษาระบบยืมคืนหนังสือ

เอกสารนี้สำหรับ **เจ้าของโปรเจกต์** ที่ต้องการ:
- เข้าใจโครงสร้างระบบโดยรวม
- ไล่ flow การทำงานได้จริง
- Debug ได้เมื่อระบบพัง
- แก้หรือเพิ่มฟีเจอร์โดยไม่ทำระบบอื่นพัง

**บริบทระบบ:** PHP page-based + API / ขนาดเล็ก–กลาง / โค้ดส่วนใหญ่เขียนโดย AI

---

## สารบัญ

1. [Project Map](#1-project-map)
2. [Request → Response Flow](#2-request--response-flow)
3. [Core Flows](#3-core-flows)
4. [Single Source of Truth Map](#4-single-source-of-truth-map)
5. [Debug Playbook](#5-debug-playbook)
6. [Modification Guide](#6-modification-guide)
7. [Quick Reference](#7-quick-reference)

---

## 1. Project Map

### 1.1 โครงสร้างโฟลเดอร์

```
book_borrowing/
│
├── *.php                    [ENTRY POINT - PUBLIC]
│   ├── bootstrap.php        ★ Core loader (ทุกหน้าต้อง require)
│   ├── index.php            หน้าแรก + ค้นหาหนังสือ
│   ├── book.php             รายละเอียดหนังสือ + ปุ่มจอง
│   ├── login.php            เข้าสู่ระบบ
│   ├── register.php         สมัครสมาชิก
│   ├── profile.php          โปรไฟล์ + เปลี่ยนรหัสผ่าน
│   ├── my_borrows.php       ประวัติการยืมของฉัน
│   ├── my_reservations.php  ประวัติการจองของฉัน
│   ├── forgot_password.php  ลืมรหัสผ่าน (สร้าง token)
│   ├── reset_password.php   รีเซ็ตรหัสผ่าน (ตรวจ token)
│   ├── logout.php           ออกจากระบบ
│   └── install.php          ★ Setup wizard (ใช้ครั้งเดียว)
│
├── admin/                   [ENTRY POINT - ADMIN/STAFF]
│   ├── index.php            Dashboard (cards, charts, alerts)
│   ├── books.php            รายการหนังสือ + ลบ
│   ├── book_form.php        เพิ่ม/แก้ไขหนังสือ + upload cover
│   ├── borrows.php          รายการยืม + ปุ่มคืน
│   ├── borrow_form.php      บันทึกการยืม (multi-book)
│   ├── reservations.php     จอง + อนุมัติ/ยกเลิก
│   ├── members.php          รายการสมาชิก
│   ├── member_form.php      เพิ่ม/แก้ไขสมาชิก
│   ├── categories.php       หมวดหมู่ (inline CRUD)
│   ├── payments.php         ค้างชำระ + ประวัติชำระ
│   ├── reports.php          รายงาน 6 ประเภท + CSV [admin only]
│   ├── export_pdf.php       Print-friendly → PDF [admin only]
│   ├── settings.php         ตั้งค่าระบบ [admin only]
│   ├── import_books.php     นำเข้าหนังสือ CSV
│   ├── import_members.php   นำเข้าสมาชิก CSV
│   ├── book_labels.php      พิมพ์ฉลาก
│   ├── member_card.php      พิมพ์บัตรสมาชิก
│   ├── header.php / footer.php  Layout templates
│
├── api/                     [ENTRY POINT - AJAX]
│   ├── search_books.php     GET  → HTML partial
│   ├── reserve_book.php     POST → JSON
│   ├── cancel_reservation.php POST → redirect
│   ├── add_member.php       POST → JSON
│   └── member_history.php   GET  → JSON
│
├── app/                     [ห้ามเข้าจาก web — .htaccess deny]
│   ├── Services/            Business logic
│   │   ├── AuthService.php, BookService.php, BorrowService.php
│   │   ├── ReservationService.php, MemberService.php
│   │   └── DashboardService.php, HomeService.php, ReportService.php
│   └── Repositories/        SQL queries
│       ├── BookRepository.php, BorrowRepository.php, UserRepository.php
│       ├── ReservationRepository.php, CategoryRepository.php
│       ├── PaymentRepository.php, ReportRepository.php
│       └── SettingsRepository.php, PasswordResetRepository.php
│
├── includes/                [INFRASTRUCTURE]
│   ├── config.php           ★ Constants จาก .env
│   ├── db.php               ★ PDO singleton
│   ├── functions.php        ★ Helpers (auth, CSRF, validation)
│   ├── report_helper.php    Report type mapping (SSoT)
│   ├── header.php/footer.php  Public layout
│   ├── book_grid.php        Reusable grid component
│   └── modal.js             Custom modal (Promise-based)
│
├── database/
│   ├── schema.sql           Full schema (8+ tables)
│   ├── sample_data.sql      Dev data
│   └── migrations/          001–003 ALTER scripts
│
├── cron/                    [CLI only — ป้องกัน web access]
│   ├── expire_reservations.php   คืน stock จองหมดอายุ
│   └── cleanup_tokens.php        ลบ tokens หมดอายุ
│
├── css/, uploads/, logs/, tests/, docs/
├── .env, .env.example, .htaccess
```

### 1.2 หน้าที่ของแต่ละ Layer

| Layer | โฟลเดอร์ | ทำ | ห้ามทำ |
|-------|----------|----|--------|
| **Entry Point** | `/*.php`, `admin/`, `api/` | รับ request, auth, CSRF, sanitize input, เรียก Service, render | เขียน SQL, business logic ซับซ้อน |
| **Service** | `app/Services/` | Business logic, transactions, coordinate repos | SQL, access $_POST/$_SESSION, echo |
| **Repository** | `app/Repositories/` | SQL (prepared statements), row locking | Business logic, transaction mgmt* |
| **Helpers** | `includes/functions.php` | Auth, CSRF, validation, formatting | Business logic, SQL |
| **Config** | `includes/config.php` | อ่าน .env → define constants | Logic ใดๆ |

> *ข้อยกเว้น: `ReservationRepository::markExpiredReservations()` จัดการ TX เอง (lazy expiration)

### 1.3 Entry Points สำคัญ (12 ไฟล์ที่ควรอ่านก่อน)

| # | ไฟล์ | เหตุผล |
|---|------|--------|
| 1 | `bootstrap.php` | จุดเริ่มต้นทุกหน้า — require chain, autoloader, error handling |
| 2 | `includes/config.php` | ค่าคงที่ทั้งระบบ — business rules, security params |
| 3 | `includes/functions.php` | Helper ทั้งหมด — auth, CSRF, validation, rate limit |
| 4 | `includes/db.php` | PDO singleton, EMULATE_PREPARES=false |
| 5 | `login.php` | ตัวอย่าง auth flow — rate limit, session regeneration |
| 6 | `register.php` | ตัวอย่าง form validation — CSRF, global rate limit, dual validation |
| 7 | `admin/borrows.php` | ตัวอย่าง admin controller — POST handler + PRG + Service |
| 8 | `admin/borrow_form.php` | ตัวอย่าง multi-book — CSRF, idempotency |
| 9 | `app/Services/BorrowService.php` | Business logic ซับซ้อนที่สุด — TX, lock, fine calc |
| 10 | `app/Services/ReservationService.php` | State machine — pending/fulfilled/cancelled/expired |
| 11 | `app/Repositories/BookRepository.php` | ตัวอย่าง Repository — prepared stmt, FOR UPDATE |
| 12 | `api/reserve_book.php` | ตัวอย่าง API — auth + CSRF + JSON response |

---

## 2. Request → Response Flow

### 2.1 Flow มาตรฐาน

```
┌──────────────────────────────────────────────────────────┐
│ 1. BROWSER → GET/POST /admin/borrows.php                 │
└───────────────────────┬──────────────────────────────────┘
                        ▼
┌──────────────────────────────────────────────────────────┐
│ 2. ENTRY POINT                                           │
│    require bootstrap.php → config → db → functions       │
│    requireStaff() → validateCSRFToken() → sanitize input │
│    $service->returnBook(...)                             │
└───────────────────────┬──────────────────────────────────┘
                        ▼
┌──────────────────────────────────────────────────────────┐
│ 3. SERVICE (BorrowService::returnBook)                   │
│    beginTransaction() → lock row → calculateFine()       │
│    markAsReturned() → incrementAvailable() → commit()    │
└───────────────────────┬──────────────────────────────────┘
                        ▼
┌──────────────────────────────────────────────────────────┐
│ 4. REPOSITORY → prepared statements → MySQL              │
│    borrows UPDATE, books UPDATE, payments INSERT         │
└───────────────────────┬──────────────────────────────────┘
                        ▼
┌──────────────────────────────────────────────────────────┐
│ 5. RESPONSE                                              │
│    Web: setFlash() → redirect()                          │
│    API: echo json_encode([...])                          │
└──────────────────────────────────────────────────────────┘
```

### 2.2 Bootstrap Require Chain (ลำดับห้ามเปลี่ยน)

```
bootstrap.php
  └→ includes/config.php      (1) define constants จาก .env
  └→ includes/db.php          (2) PDO singleton
  └→ includes/functions.php   (3) helpers + startSession()
  └→ spl_autoload_register    (4) autoload app/Services + Repositories
  └→ error_reporting          (5) ตาม APP_DEBUG
  └→ cleanupIdempotencyKeys() (6) ลบ keys เก่า > 5 นาที
```

### 2.3 Boundary — สิ่งที่ห้ามทำในแต่ละ Layer

| Layer | ห้ามทำ | เหตุผล |
|-------|--------|--------|
| Entry Point | SQL โดยตรง | แยก concern |
| Entry Point | Business logic ซับซ้อน | ย้ายไป Service |
| Service | SQL โดยตรง | ใช้ Repository |
| Service | Access $_POST/$_SESSION | รับเป็น param |
| Service | echo/redirect | return data แทน |
| Repository | Business logic | แค่ query data |
| Repository | Transaction management* | Service เปิด/ปิด TX |
| Helper | SQL / Business logic | แค่ utility |

#### ตัวอย่าง Boundary ในโค้ด

**Entry Point — ทำ:**
```php
$userId = (int) $_POST['user_id'];       // sanitize
validateCSRFToken($_POST['csrf_token']); // CSRF
requireStaff();                           // auth guard
```

**Service — ทำ:**
```php
$pdo->beginTransaction();
if ($borrowCount >= MAX_BORROW_BOOKS) throw new Exception('เกินโควต้า');
$this->bookRepo->decrementAvailable($bookId);
$pdo->commit();
```

**Repository — ทำ:**
```php
$stmt = $this->pdo->prepare("SELECT * FROM books WHERE id = ?");
$stmt->execute([$id]);
return $stmt->fetch() ?: null;
```

---

## 3. Core Flows

### 3.1 Flow: Login (เข้าสู่ระบบ)

#### Goal
ผู้ใช้ authenticate ด้วย email/password แล้วสร้าง session

#### Entry Point
`login.php` | Method: `POST`

#### Inputs + Validation

| Input | Validation |
|-------|------------|
| `email` | ไม่ว่าง |
| `password` | ไม่ว่าง |

#### Authorization / CSRF / Guards
- ไม่ต้อง auth (หน้า public)
- `validateCSRFToken()` ตรวจทุก POST
- Rate limit: `checkRateLimit('login_' . md5($email))` — 5 ครั้ง / 15 นาที

#### Steps การทำงาน

```
1. ตรวจว่า login อยู่แล้ว → redirect ตาม role
2. รับ $_POST['email'], $_POST['password']
3. Validate: ทั้งคู่ต้องไม่ว่าง
4. checkRateLimit() → ถ้าเกิน → error
5. AuthService::login($email, $password)
   └→ UserRepository::findByEmail($email)
   └→ password_verify() → return user | null
6. สำเร็จ:
   ├→ session_regenerate_id(true)
   ├→ $_SESSION['user_id'], $_SESSION['user_role']
   ├→ resetRateLimit()
   └→ redirect ตาม role
7. ไม่สำเร็จ:
   ├→ incrementRateLimit()
   └→ error "อีเมลหรือรหัสผ่านไม่ถูกต้อง" (ไม่แยกว่าอะไรผิด)
```

#### DB Changes
- **Read only:** `users` (SELECT)
- **Write:** `rate_limits` (INSERT/UPDATE counter)

#### Output

| Case | Response |
|------|----------|
| Success (admin/staff) | 302 → `/admin/` |
| Success (member) | 302 → `/index.php` |
| Failure | แสดง error ในหน้า login |

#### Common Failure Cases

| Case | ผลลัพธ์ |
|------|--------|
| Email/password ผิด | "อีเมลหรือรหัสผ่านไม่ถูกต้อง" |
| Rate limit exceeded | "ลองผิดหลายครั้งเกินไป กรุณารอ 15 นาที" |

#### จุดระวังเวลาแก้ (Invariants)

| Invariant | เหตุผล |
|-----------|--------|
| Error message ต้องกว้างๆ | ป้องกัน user enumeration |
| `session_regenerate_id(true)` หลัง login | ป้องกัน session fixation |
| Rate limit key ใช้ md5(email) | ป้องกัน brute force per account |
| AuthService return null ทั้ง email ไม่พบ + password ผิด | Timing consistency |

#### Test Steps

**Happy Path:**
```
1. เปิด /login.php
2. กรอก email: admin@library.com, password: 123456
3. กด Submit
4. ✅ คาดหวัง: redirect ไป /admin/
```

**Failure Case — Rate Limit:**
```
1. กรอก email ถูก, password ผิด
2. กด Submit 6 ครั้ง
3. ✅ คาดหวัง: "ลองผิดหลายครั้งเกินไป กรุณารอ 15 นาที"
```

---

### 3.2 Flow: Register (สมัครสมาชิก)

#### Goal
ผู้ใช้ใหม่สมัครเป็น member (role='member' เท่านั้น — admin/staff สร้างผ่าน admin panel)

#### Entry Point
`register.php` | Method: `POST`

#### Inputs + Validation

| Input | Type | Validation |
|-------|------|------------|
| `csrf_token` | string | ต้องตรง |
| `name` | string | ไม่ว่าง, ≤100 chars |
| `email` | string | ไม่ว่าง, valid format, unique |
| `phone` | string | optional, 9-10 digits |
| `password` | string | ≥ MIN_PASSWORD_LENGTH (6) chars |
| `confirm_password` | string | ต้องตรงกับ password |

#### Authorization / CSRF / Guards
- ไม่ต้อง auth (ถ้า login แล้ว redirect)
- `validateCSRFToken()`
- Rate limit: global key `'register'` (ไม่ใช่ per-email เพราะ attacker ใช้ email ใหม่ได้)
- `incrementRateLimit()` เรียก**ก่อน** validation — ป้องกัน bypass ด้วย invalid data

#### Steps การทำงาน

```
1. ตรวจ login อยู่แล้ว → redirect index.php
2. validateCSRFToken()
3. checkRateLimit('register') → ถ้าเกิน → error
4. incrementRateLimit('register') ← นับก่อน validate
5. รับ + trim inputs
6. validateMemberData() ← shared helper (Single Source of Truth)
7. ตรวจ confirm_password == password
8. ถ้าไม่มี errors:
   └→ AuthService::register($data)
      └→ MemberService::createMember($data)  ← delegate (SSoT)
         ├→ ตรวจ email ซ้ำ
         ├→ hashPassword()
         └→ UserRepository::create() (role='member')
9. สำเร็จ → redirect('/login.php') + flash
10. ล้มเหลว → แสดง errors + เก็บค่าเดิม
```

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `users` | INSERT | role='member', password=bcrypt hash |
| `rate_limits` | UPDATE | increment counter |

#### Output

| Case | Response |
|------|----------|
| Success | 302 → `/login.php` + flash "สมัครสมาชิกสำเร็จ" |
| Email ซ้ำ | error "อีเมลนี้ถูกใช้งานแล้ว" |
| Validation error | แสดง errors (เก็บค่าเดิม) |

#### จุดระวังเวลาแก้ (Invariants)

| Invariant | เหตุผล |
|-----------|--------|
| สร้าง member เท่านั้น | admin/staff สร้างผ่าน admin panel |
| Rate limit ใช้ global key | per-email ไม่ช่วย |
| incrementRateLimit() ก่อน validation | ป้องกัน brute force |
| Delegate ไป MemberService | SSoT — register + admin ใช้ logic เดียว |

#### Test Steps

**Happy Path:**
```
1. เปิด /register.php
2. กรอก name, email ใหม่, password, confirm_password
3. กด Submit
4. ✅ คาดหวัง: redirect ไป login.php + "สมัครสมาชิกสำเร็จ"
5. ✅ ตรวจ DB: users มี row ใหม่, role='member'
```

**Failure Case — Email ซ้ำ:**
```
1. กรอก email ที่มีอยู่แล้ว
2. ✅ คาดหวัง: error "อีเมลนี้ถูกใช้งานแล้ว"
```

---

### 3.3 Flow: Create Borrow (ยืมหนังสือ)

#### Goal
Staff บันทึกการยืมหนังสือให้ member (รองรับหลายเล่ม) โดยลด stock + สร้าง borrow record

#### Entry Point
`admin/borrow_form.php` | Method: `POST`

#### Inputs + Validation

| Input | Type | Validation |
|-------|------|------------|
| `csrf_token` | string | ต้องตรง |
| `user_id` | int | > 0, ต้องเป็น member |
| `book_ids[]` | array | ไม่ว่าง, แต่ละ id > 0 |
| `borrow_days` | int | 1–30, default: DEFAULT_BORROW_DAYS (7) |

#### Authorization / CSRF / Guards
- `requireStaff()`
- `validateCSRFToken()`
- Idempotency key: `borrow_{userId}_{md5(bookIds)}`

#### Steps การทำงาน

```
1. requireStaff()
2. validateCSRFToken()
3. ตรวจ idempotency key → ซ้ำ = redirect + warning
4. Validate inputs
5. BorrowService::createBorrow($userId, $bookIds, $borrowDays)
   ├── validate: userId, bookIds, borrowDays
   ├── UserRepository::findMemberById() → ตรวจเป็น member
   ├── beginTransaction()
   ├── UserRepository::lockById() ← lock user row ก่อน
   ├── BorrowRepository::countActiveBorrowsForUpdate() ← ตรวจ quota
   ├── ตรวจ: count + current ≤ MAX_BORROW_BOOKS
   ├── Loop each book:
   │   ├── BookRepository::findByIdForUpdate() ← lock
   │   ├── ตรวจ available > 0
   │   ├── ตรวจ isAlreadyBorrowing() ← ห้ามซ้ำ
   │   ├── BookRepository::decrementAvailable()
   │   └── BorrowRepository::create()
   └── commit()
6. บันทึก idempotency key
7. setFlash() + redirect('borrows.php')
```

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `books` | UPDATE | `available - 1` per book |
| `borrows` | INSERT | 1 row per book, status='borrowing' |

**Transaction:** ใช่ — ครอบทั้ง loop  
**Row Locking:** FOR UPDATE บน users + books

#### Output

| Case | Response |
|------|----------|
| Success | "บันทึกการยืมสำเร็จ N เล่ม กำหนดคืน: dd/mm/yyyy" | 
| Partial | แจ้งเล่มที่ skip |
| Quota full | "ผู้ยืมถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว" |

#### Common Failure Cases

| Case | ผลลัพธ์ |
|------|--------|
| Double submit | idempotency catch |
| Stock = 0 | Book ถูก skip |
| Quota full | Exception + rollback |
| Concurrent last copy | FOR UPDATE → คนที่ 2 รอ |
| ยืมเล่มเดิมซ้ำ | skip "ยืมอยู่แล้ว" |

#### จุดระวังเวลาแก้ (Invariants)

| Invariant | เหตุผล |
|-----------|--------|
| `decrementAvailable()` มี `WHERE available > 0` | ป้องกัน stock ติดลบ |
| Transaction ครอบทุก book | rollback ทั้งหมดถ้า exception |
| `MAX_BORROW_BOOKS` อยู่ใน `config.php` → แก้ที่เดียว |
| Lock user ก่อน books | ป้องกัน deadlock |
| ตรวจ isAlreadyBorrowing() ภายใต้ lock | ป้องกัน concurrent duplicate |

#### Test Steps

**Happy Path:**
```
1. Login staff → /admin/borrow_form.php
2. เลือก member ที่ยังไม่ยืม + book ที่ available > 0
3. กด Submit
4. ✅ redirect borrows.php + "สำเร็จ 1 เล่ม"
5. ✅ DB: books.available ลด 1, borrows มี row ใหม่
```

**Failure — Quota:**
```
1. เลือก member ที่ยืมครบ 3 เล่ม
2. ✅ error "ถึงจำนวนสูงสุดแล้ว"
```

**Edge — Double Submit:**
```
1. กด Back + Submit อีกครั้ง
2. ✅ "รายการนี้ถูกบันทึกไปแล้ว"
```

---

### 3.4 Flow: Return Book (คืนหนังสือ)

#### Goal
Staff บันทึกการคืน + คำนวณค่าปรับอัตโนมัติ + คืน stock

#### Entry Point
`admin/borrows.php` | Method: `POST` (action=return)

#### Inputs + Validation

| Input | Type | Validation |
|-------|------|------------|
| `csrf_token` | string | ต้องตรง |
| `action` | string | = 'return' |
| `borrow_id` | int | > 0 |
| `pay_now` | checkbox | optional |

#### Authorization / CSRF / Guards
- `requireStaff()`
- `validateCSRFToken()`
- Idempotency key: `return_{borrowId}`

#### Steps การทำงาน

```
1. requireStaff() + validateCSRFToken() + idempotency check
2. BorrowService::returnBook($borrowId, $payNow, $staffId)
   ├── beginTransaction()
   ├── BorrowRepository::findByIdForUpdate($borrowId)
   │   └→ WHERE status='borrowing' FOR UPDATE
   │   └→ null = คืนแล้ว/ไม่พบ
   ├── calculateFine(due_date, today)
   │   └→ overdue: days × FINE_PER_DAY
   │   └→ ไม่เกิน: {days:0, amount:0}
   ├── BorrowRepository::markAsReturned() → status='returned'
   ├── BookRepository::incrementAvailable() → คืน stock
   ├── ถ้า payNow && fine > 0:
   │   └→ PaymentRepository::create()
   └── commit()
3. setFlash() + redirect()
```

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `borrows` | UPDATE | status='returned', return_date, fine_amount |
| `books` | UPDATE | `available + 1` |
| `payments` | INSERT | เฉพาะ pay_now && fine > 0 |

#### Output

| Case | Response |
|------|----------|
| ไม่มีค่าปรับ | "บันทึกการคืนหนังสือสำเร็จ" |
| มีปรับ + จ่าย | "ค่าปรับ: X บาท [รับชำระเงินแล้ว]" |
| มีปรับ + ไม่จ่าย | "ค่าปรับ: X บาท [ยังไม่จ่าย]" |

#### จุดระวังเวลาแก้ (Invariants)

| Invariant | เหตุผล |
|-----------|--------|
| `incrementAvailable()` ต้องเรียกเสมอ | ไม่งั้น stock หาย |
| Fine calc อยู่ `calculateFine()` เท่านั้น | Single Source of Truth |
| ตรวจ status='borrowing' ใน SQL WHERE | ป้องกันคืนซ้ำ (atomic) |
| Payment ต้องอยู่ใน TX เดียวกับ return | Consistency |

#### Test Steps

**Happy Path (ไม่มีปรับ):**
```
1. หา borrow status='borrowing', ยังไม่เลย due_date
2. กดปุ่ม "คืน"
3. ✅ "บันทึกการคืนหนังสือสำเร็จ"
4. ✅ DB: status='returned', fine_amount=0, book.available +1
```

**Failure Case (มีปรับ ไม่จ่าย):**
```
1. หา borrow เลย due_date แล้ว
2. กด "คืน" ไม่ติ๊ก pay_now
3. ✅ "ค่าปรับ: X บาท [ยังไม่จ่าย]"
4. ✅ DB: fine_amount > 0, ไม่มี payment row, book.available +1
```

**Edge Case (จ่ายทันที):**
```
1. หา borrow เลย due_date + ติ๊ก pay_now
2. ✅ "ค่าปรับ: X บาท [รับชำระเงินแล้ว]"
3. ✅ DB: fine_amount > 0, payments มี row ใหม่, book.available +1
```

---

### 3.5 Flow: Reserve Book (จองหนังสือ — AJAX)

#### Goal
Member จองหนังสือผ่าน AJAX โดย stock ถูกกันทันที (หมดอายุอัตโนมัติ 2 วัน)

#### Entry Point
`api/reserve_book.php` | Method: `POST` | Response: JSON

#### Inputs + Validation

| Input | Type | Validation |
|-------|------|------------|
| `csrf_token` | string | ต้องตรง |
| `book_id` | int | > 0 |

**สำคัญ:** `user_id` จาก `$_SESSION['user_id']` เท่านั้น — ห้ามรับจาก POST

#### Authorization / CSRF / Guards
- `isLoggedIn()` → 401
- `validateCSRFToken()` → 403
- Method = POST → 405

#### Steps การทำงาน

```
1. ตรวจ isLoggedIn() → 401
2. ตรวจ method POST → 405
3. validateCSRFToken() → 403
4. Validate book_id > 0 → 400
5. checkRateLimit('reserve_' + userId, 10, 5) → 429 (ใหม่: 10 ครั้ง/5นาที)
6. Idempotency check (session key, 5 วินาที)
7. ReservationService::createReservation($userId, $bookId)
   ├── markExpiredReservations() ← lazy expiration (ก่อน TX)
   ├── beginTransaction()
   ├── BookRepository::findByIdForUpdate() ← lock
   ├── ตรวจ available > 0
   ├── ตรวจ hasPending() → ห้ามจองซ้ำ
   ├── ตรวจ isAlreadyBorrowing() → ห้ามจองถ้ายืมอยู่
   ├── ตรวจโควต้า (activeBorrows + pendingReservations ≥ MAX_BORROW_BOOKS)
   ├── ReservationRepository::create()
   │   └→ status='pending', expires_at = +2 days
   ├── BookRepository::decrementAvailable() ← กัน stock ทันที
   └── commit()
8. บันทึก idempotency key
9. JSON { success: true }
```

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `books` | UPDATE | `available - 1` (กัน stock) |
| `reservations` | INSERT | status='pending', expires_at |
| `reservations` | UPDATE | expired rows (lazy, ถ้ามี) |
| `books` | UPDATE | คืน stock expired (ถ้ามี) |

#### Output

| Case | HTTP | Response |
|------|------|----------|
| Success | 200 | `{"success":true,"message":"จองสำเร็จ!..."}` |
| Not logged in | 401 | `{"success":false,"message":"กรุณาเข้าสู่ระบบ"}` |
| จองซ้ำ | 400 | `{"success":false,"message":"จองไว้แล้ว"}` |
| หมด stock | 400 | `{"success":false,"message":"หนังสือหมด"}` |

#### จุดระวังเวลาแก้ (Invariants)

| Invariant | เหตุผล |
|-----------|--------|
| Stock หักทันทีตอนจอง | กัน stock ไว้ |
| user_id จาก session เท่านั้น | ห้ามรับจาก POST |
| Cancel/Expire **ต้อง**คืน stock | ไม่งั้น stock หายถาวร |
| Lazy expiration ก่อน check available | ปลด stock หมดอายุก่อน |

#### Test Steps

**Happy Path (curl):**
```bash
# Login
curl -X POST "http://localhost/book_borrowing/login.php" \
  -d "email=member@test.com&password=123456" -c cookies.txt -L

# Reserve
curl -X POST "http://localhost/book_borrowing/api/reserve_book.php" \
  -d "book_id=1&csrf_token=TOKEN" -b cookies.txt
# ✅ {"success":true}
```

**Failure — จองซ้ำ:**
```bash
# จองเล่มเดิมอีกครั้ง
# ✅ {"success":false,"message":"คุณได้จองหนังสือเล่มนี้ไว้แล้ว"}
```

---

### 3.6 Flow: Fulfill Reservation (อนุมัติการจอง)

#### Goal
Staff อนุมัติการจอง → สร้าง borrow อัตโนมัติ (stock ไม่ถูกหัก เพราะหักตอนจอง)

#### Entry Point
`admin/reservations.php` | Method: `POST` (action=approve)

#### Inputs + Validation

| Input | Type | Validation |
|-------|------|------------|
| `csrf_token` | string | ต้องตรง |
| `action` | string | = 'approve' |
| `id` | int | reservation_id > 0 |

#### Steps การทำงาน

```
1. requireStaff() + validateCSRFToken()
2. ReservationService::fulfillReservation($reservationId)
   ├── beginTransaction()
   ├── ReservationRepository::findPendingForUpdate()
   │   └→ lock + status='pending' → null = ไม่ใช่ pending
   ├── BorrowRepository::isAlreadyBorrowing() ← ตรวจยืมซ้ำ
   ├── BorrowRepository::countActiveBorrowsForUpdate() ← ตรวจ quota
   ├── BorrowRepository::create() ← สร้าง borrow
   ├── ReservationRepository::updateStatusWithBorrow($id, 'fulfilled', $borrowId)
   └→ commit()
3. setFlash() + redirect()
```

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `reservations` | UPDATE | status='fulfilled', borrow_id |
| `borrows` | INSERT | สร้าง borrow |

**สำคัญ:** ไม่แตะ books.available — stock หักไปตอนจอง

#### จุดระวังเวลาแก้ (Invariants)

| Invariant | เหตุผล |
|-----------|--------|
| ตรวจ quota ก่อน fulfill | member อาจยืมเพิ่มระหว่างรอ |
| **ห้ามแตะ** books.available | หักไปแล้วตอน createReservation |
| status='pending' ใน WHERE | ป้องกัน double approve |

#### Test Steps

**Happy Path:**
```
1. Staff → /admin/reservations.php
2. หา reservation status='pending' → กด "อนุมัติ"
3. ✅ "อนุมัติสำเร็จ! สร้างรายการยืมแล้ว"
4. ✅ DB: reservation.status='fulfilled', borrows มี row ใหม่
5. ✅ DB: books.available ไม่เปลี่ยน
```

---

### 3.7 Flow: Pay Fine (ชำระค่าปรับ — ภายหลัง)

#### Goal
Staff บันทึกชำระค่าปรับของ borrow ที่คืนแล้วแต่ยังไม่จ่าย

#### Entry Point
`admin/payments.php` | Method: `POST` (action=pay_fine)

#### Inputs + Validation

| Input | Type | Validation |
|-------|------|------------|
| `csrf_token` | string | ต้องตรง |
| `action` | string | = 'pay_fine' |
| `borrow_id` | int | > 0 |

#### Steps การทำงาน

```
1. requireStaff() + validateCSRFToken()
2. ตรวจ idempotency key: pay_fine_{borrowId}
3. BorrowService::payFine($borrowId, $staffId)
   ├── beginTransaction()
   ├── BorrowRepository::findByIdForUpdateAnyStatus() ← lock
   │   └→ ดึง borrow ทุก status (returned + borrowing)
   ├── ตรวจ fine_amount > 0 → "ไม่มีค่าปรับ"
   ├── PaymentRepository::findByBorrowId()
   │   └→ ตรวจชำระแล้วหรือยัง ภายใต้ lock
   │   └→ มี → "ชำระค่าปรับแล้ว"
   ├── PaymentRepository::create()
   │   └→ UNIQUE constraint บน borrow_id
   └── commit()
4. setFlash() + redirect()
```

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `payments` | INSERT | borrow_id (UNIQUE), amount |

#### Output

| Case | Response |
|------|----------|
| Success | "รับชำระค่าปรับ X บาท เรียบร้อยแล้ว" |
| ไม่มีค่าปรับ | "รายการนี้ไม่มีค่าปรับ" |
| ชำระแล้ว | "รายการนี้ชำระค่าปรับแล้ว" |

#### จุดระวังเวลาแก้ (Invariants)

| Invariant | เหตุผล |
|-----------|--------|
| `payments.borrow_id` UNIQUE | DB-level ป้องกันชำระซ้ำ |
| ตรวจ existing payment ภายใต้ lock | App-level ป้องกัน race |
| ใช้ `findByIdForUpdateAnyStatus()` | borrow อาจ returned แล้ว |

#### Test Steps

**Happy Path:**
```
1. Staff → /admin/payments.php
2. หา borrow ค้างจ่าย → กด "ชำระ"
3. ✅ "รับชำระค่าปรับ X บาท เรียบร้อยแล้ว"
4. ✅ DB: payments มี row ใหม่
```

**Edge — Concurrent:**
```
1. 2 staff กดชำระ borrow เดียวกันพร้อมกัน
2. ✅ คนแรกสำเร็จ, คนที่ 2 "ชำระค่าปรับแล้ว"
3. ✅ DB: payments 1 row (UNIQUE)
```

---

### 3.8 Flow: Create Book (เพิ่มหนังสือ)

#### Goal
Staff เพิ่มหนังสือใหม่ (พร้อม upload รูปปก)

#### Entry Point
`admin/book_form.php` (ไม่มี ?id) | Method: `POST` | Encoding: `multipart/form-data`

#### Inputs + Validation

| Input | Type | Validation |
|-------|------|------------|
| `csrf_token` | string | ต้องตรง |
| `title` | string | ไม่ว่าง, ≤200 chars |
| `author` | string | ไม่ว่าง, ≤100 chars |
| `isbn` | string | optional, unique |
| `category_id` | int | optional, must exist |
| `quantity` | int | ≥ 1 |
| `cover_image` | file | JPEG/PNG/GIF/WEBP, ≤2MB |

#### Steps การทำงาน

```
1. requireStaff() + validateCSRFToken()
2. Validate inputs
3. ถ้ามี file upload:
   ├→ finfo_file() ตรวจ MIME จาก content
   ├→ ตรวจ size ≤ 2MB
   ├→ ชื่อใหม่: cover_{timestamp}_{uniqid}.{ext}
   └→ move_uploaded_file()
4. BookRepository::create($data)
   └→ available = quantity
5. setFlash() + redirect('books.php')
```

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `books` | INSERT | available = quantity |

**File:** `uploads/covers/cover_*.ext`

#### จุดระวังเวลาแก้ (Invariants)

| Invariant | เหตุผล |
|-----------|--------|
| ตรวจ MIME จาก finfo ไม่ใช่ $_FILES['type'] | client ปลอมได้ |
| สร้างชื่อไฟล์ใหม่ | ป้องกัน path traversal |
| available = quantity ตอนสร้าง | initial stock |

#### Test Steps

**Happy Path:**
```
1. Staff → /admin/book_form.php
2. กรอก title, author, quantity=5
3. ✅ redirect books.php + "เพิ่มหนังสือสำเร็จ"
4. ✅ DB: available = 5 = quantity
```

**Failure — ISBN ซ้ำ:**
```
1. กรอก ISBN ที่มีอยู่ → ✅ "ISBN นี้มีในระบบแล้ว"
```

---

## 4. Single Source of Truth Map

### 4.1 ตำแหน่งที่ถูกต้องของแต่ละ Concern

| Concern | ตำแหน่ง (SSoT) | ไฟล์/Function |
|---------|----------------|---------------|
| **Auth Check** | Helper | `functions.php`: `isLoggedIn()`, `isStaff()`, `isAdmin()` |
| **Auth Guard (web)** | Helper | `functions.php`: `requireLogin()`, `requireStaff()`, `requireAdmin()` |
| **Auth Guard (API)** | Helper | `functions.php`: `requireStaffApi()`, `requireAdminApi()` |
| **CSRF Generate** | Helper | `functions.php`: `generateCSRFToken()` |
| **CSRF Validate** | Helper | `functions.php`: `validateCSRFToken()` (ใช้ `hash_equals`) |
| **Rate Limit** | Helper | `functions.php`: `checkRateLimit()`, `incrementRateLimit()`, `resetRateLimit()` |
| **Password Validation** | Helper | `functions.php`: `validatePassword()` |
| **Password Hashing** | Helper | `functions.php`: `hashPassword()` (wraps `password_hash`) |
| **Member Data Validation** | Helper | `functions.php`: `validateMemberData()` |
| **Email/Phone Validation** | Helper | `functions.php`: `isValidEmail()`, `isValidPhone()` |
| **XSS Escaping** | Helper | `functions.php`: `e()` (wraps `htmlspecialchars`) |
| **Session Start** | Helper | `functions.php`: `startSession()` (HttpOnly, SameSite, Secure) |
| **Business Constants** | Config | `config.php`: `MAX_BORROW_BOOKS`, `FINE_PER_DAY`, `DEFAULT_BORROW_DAYS` |
| **DB Connection** | Helper | `db.php`: `getDB()` (PDO singleton) |
| **Fine Calculation** | Service | `BorrowService::calculateFine()` |
| **Borrow Quota** | Service | `BorrowService::createBorrow()` ภายใน TX |
| **Stock Management** | Repo | `BookRepository::decrementAvailable()` / `incrementAvailable()` |
| **Member Creation** | Service | `MemberService::createMember()` (ทั้ง register + admin) |
| **Report Config** | Helper | `report_helper.php`: `getReportConfig()` (shared reports + PDF) |
| **SQL Queries** | Repository | `app/Repositories/*.php` ทุกไฟล์ |

### 4.2 Validation ที่ทำซ้ำ (Dual Validation)

| สิ่งที่ตรวจ | Entry Point | Service | เหตุผลที่ซ้ำได้ |
|------------|-------------|---------|----------------|
| Password format | `validatePassword()` | — | Single source ไม่ซ้ำ |
| Email format | `isValidEmail()` | — | Single source ไม่ซ้ำ |
| Email unique | — | `MemberService` → DB | ต้องตรวจใน DB |
| User exists | UI กรอง (dropdown) | ตรวจใน TX | **Concurrency** — user อาจถูกลบ |
| Book available | UI กรอง | ตรวจ FOR UPDATE | **Concurrency** — stock เปลี่ยน |
| Borrow quota | — | ตรวจใน TX (FOR UPDATE) | **ต้อง lock** ก่อนตรวจ |
| Reservation ซ้ำ | — | ตรวจใน TX | **Concurrency** — อาจจองพร้อมกัน |

**หลักการ:** Validation ที่เกี่ยวกับ concurrency (stock, quota, duplicate) ต้องทำซ้ำใน transaction + lock

### 4.3 Logic ที่ถูก Duplicate — ระวังเป็นพิเศษ

| Logic | ตำแหน่ง SSoT | ใครเรียก |
|-------|-------------|---------|
| Report type mapping | `report_helper.php::getReportConfig()` | `reports.php` + `export_pdf.php` |
| Member creation | `MemberService::createMember()` | `register.php` + `admin/member_form.php` |
| Reservation expiration | `ReservationRepository::markExpiredReservations()` | createReservation (lazy), cron, admin dashboard (fallback) |
| `e()` escaping | `functions.php` | ทุกที่ที่แสดง user data — ถ้าลืมจุดใดก็ XSS |

---

## 5. Debug Playbook

### 5.1 เปิด/ปิด Debug Mode

แก้ไฟล์ `.env`:
```
APP_DEBUG=true    ← เปิด: แสดง errors บนหน้าเว็บ
APP_DEBUG=false   ← ปิด: แสดงแค่ "ระบบขัดข้อง กรุณาลองใหม่"
```

ตำแหน่งโค้ด: `bootstrap.php` → `error_reporting()` + `ini_set('display_errors')`

### 5.2 Log อยู่ที่ไหน

| ประเภท | ตำแหน่ง |
|--------|---------|
| PHP Errors | Apache error log (`xampp/apache/logs/error.log`) |
| Custom Logs | `logs/` folder (cron.log) |
| DB Errors | หน้าเว็บ (APP_DEBUG=true) หรือ `error_log()` |

### 5.3 วิธีไล่ Debug ตาม Error Type

#### HTTP 400 (Bad Request)
```
สาเหตุ: Input validation ไม่ผ่าน
1. ดู response body → อ่าน error message
2. ตรวจ $errors array ใน Entry Point
3. ตรวจว่า required fields ส่งมาครบ
4. ตรวจ Service throw Exception อะไร
```

#### HTTP 401 (Unauthorized)
```
สาเหตุ: ไม่ได้ login / session หมดอายุ
1. ตรวจ $_SESSION['user_id'] มีค่าไหม
2. ตรวจ session หมดอายุ → SESSION_LIFETIME = 3600s
3. ตรวจ cookie ถูกส่งมาไหม (browser dev tools → Cookies)
4. ตรวจ isLoggedIn() return อะไร
```

#### HTTP 403 (Forbidden)
```
สาเหตุ: CSRF invalid / ไม่มีสิทธิ์
CSRF:
  1. Refresh หน้า form → submit ใหม่
  2. ตรวจ <input name="csrf_token"> อยู่ใน form
  3. ตรวจ $_POST['csrf_token'] ถูกส่งมาจริง
Permission:
  1. ตรวจ $_SESSION['user_role']
  2. ดูว่าหน้าใช้ requireStaff() หรือ requireAdmin()
```

#### HTTP 500 (Internal Server Error)
```
สาเหตุ: PHP error, DB error, file permission
1. เปิด APP_DEBUG=true
2. ดู Apache error log
3. Common causes:
   - PDOException → ตรวจ .env DB settings
   - SQL syntax → ตรวจ migration รันหมดหรือยัง
   - Class not found → autoloader (namespace + ชื่อไฟล์)
   - File permission → uploads/, logs/ writable?
   - TX ไม่ commit/rollback → ดู catch block
```

### 5.4 ตัวอย่าง Debug ด้วย curl

```bash
# 1. ทดสอบ Login
curl -X POST "http://localhost/book_borrowing/login.php" \
  -d "email=admin@library.com&password=123456" \
  -c cookies.txt -L -v

# 2. ทดสอบ Search API (ไม่ต้อง login)
curl "http://localhost/book_borrowing/api/search_books.php?search=php" -v

# 3. ทดสอบ Reserve (ต้อง login + CSRF)
curl -X POST "http://localhost/book_borrowing/login.php" \
  -d "email=member@test.com&password=123456" -c cookies.txt -L

curl -X POST "http://localhost/book_borrowing/api/reserve_book.php" \
  -d "book_id=1&csrf_token=YOUR_TOKEN" -b cookies.txt

# 4. ทดสอบ Member History (staff only)
curl "http://localhost/book_borrowing/api/member_history.php?id=1" \
  -b cookies.txt
```

### 5.5 Debug Checklist (เวลาระบบพัง)

```
□ APP_DEBUG=true ใน .env?
□ ดู error message บนหน้าเว็บ
□ ดู Apache error log
□ Session works? (startSession() ถูกเรียกใน functions.php)
□ DB connection works? (ลอง var_dump(getDB()) ใน test file)
□ CSRF valid? (var_dump validateCSRFToken())
□ User logged in? (var_dump isLoggedIn())
□ User has permission? (var_dump isStaff()/isAdmin())
□ Input valid? (var_dump $_POST)
□ Service exception? (wrap try-catch ดู message)
□ TX commit/rollback ครบ? (ดู catch block)
□ File permissions ถูกต้อง? (uploads/, logs/)
```

---

## 6. Modification Guide

### 6.1 แก้ Business Rule

| ต้องการ | แก้ที่ | ตัวอย่าง |
|---------|-------|---------|
| จำนวนวันยืม | `.env` | `DEFAULT_BORROW_DAYS=14` |
| จำนวนยืมสูงสุด | `.env` | `MAX_BORROW_BOOKS=5` |
| ค่าปรับต่อวัน | `.env` | `FINE_PER_DAY=20` |
| สูตรค่าปรับ | `BorrowService.php` | แก้ `calculateFine()` |
| อายุการจอง | `ReservationService.php` | param `$expireDays` default 2 |

### 6.2 แก้ Validation

| ต้องการ | แก้ที่ | Function |
|---------|-------|----------|
| Password length | `.env` | `MIN_PASSWORD_LENGTH=8` |
| Email format | `functions.php` | `isValidEmail()` |
| Phone format | `functions.php` | `isValidPhone()` |
| Member data | `functions.php` | `validateMemberData()` |

### 6.3 แก้ Permission

| ต้องการ | แก้ที่ |
|---------|-------|
| เปลี่ยน access level | Entry Point: `requireStaff()` ↔ `requireAdmin()` |
| เพิ่ม role | 1. `schema.sql`: แก้ ENUM ใน users.role |
| | 2. `functions.php`: เพิ่ม `isNewRole()`, `requireNewRole()` |
| | 3. Entry Points ที่เกี่ยวข้อง: เพิ่ม guard |

### 6.4 แก้ SQL

| ต้องการ | แก้ที่ | กฎเหล็ก |
|---------|-------|---------|
| Query หนังสือ | `BookRepository.php` | SQL ต้องอยู่ใน Repository เท่านั้น |
| Query การยืม | `BorrowRepository.php` | ใช้ prepared statements เสมอ |
| Query user | `UserRepository.php` | ห้าม string concatenation |
| Query การจอง | `ReservationRepository.php` | ตรวจ state guard ใน WHERE |
| Query รายงาน | `ReportRepository.php` | Read-only queries |

### 6.5 เพิ่ม Field ใหม่ในตาราง

**ตัวอย่าง:** เพิ่ม `publisher` ในตาราง `books`

```
ลำดับที่ต้องแก้:

1. DATABASE
   └→ สร้าง database/migrations/004_add_publisher.sql
      ALTER TABLE books ADD COLUMN publisher VARCHAR(100);

2. REPOSITORY
   └→ BookRepository.php
      - findById(): เพิ่มใน SELECT (หรือถ้าใช้ SELECT * อยู่แล้วก็ไม่ต้อง)
      - create(): เพิ่มใน INSERT
      - update(): เพิ่มใน UPDATE

3. ENTRY POINT (Form)
   └→ admin/book_form.php
      - เพิ่ม <input name="publisher">
      - รับ $_POST['publisher'] + sanitize

4. ENTRY POINT (Display)
   └→ admin/books.php, book.php
      - แสดง <?= e($book['publisher']) ?>

5. TEST
   □ Create: เพิ่มหนังสือพร้อม publisher
   □ Read: ดูรายการ — เห็น publisher
   □ Update: แก้ไข publisher
   □ ตรวจว่า e() ครอบ output
```

### 6.6 เพิ่ม API Endpoint ใหม่

**Template:**

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

// 3. CSRF
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

// 4. Validate Input
$input = (int) ($_POST['input'] ?? 0);
if ($input <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

// 5. Call Service
try {
    $service = new \App\Services\SomeService(getDB());
    $result = $service->doSomething($input);
    echo json_encode(['success' => true, 'data' => $result]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
```

**Checklist หลังสร้าง:**
```
□ curl ทดสอบ success case
□ curl ทดสอบ 401 (ไม่ login)
□ curl ทดสอบ 403 (CSRF ผิด)
□ curl ทดสอบ 400 (input ผิด)
```

### 6.7 Checklist หลังแก้ไขทุกครั้ง

```
□ ทดสอบ happy path
□ ทดสอบ failure cases
□ SQL ไม่มี syntax error
□ Transaction commit/rollback ครบ
□ CSRF protection ยังทำงาน
□ Auth protection ยังทำงาน
□ e() ครอบ output ของ user data ทุกจุด
□ ไม่มี SQL ใน Entry Point / Service
□ ไม่มี business logic ใน Repository
```

---

## 7. Quick Reference

### 7.1 Helper Functions สำคัญ

```php
// === Security ===
e($str)                           // Escape HTML (XSS)
generateCSRFToken()               // สร้าง CSRF token
validateCSRFToken($token)         // ตรวจ CSRF (hash_equals)
hashPassword($pw)                 // password_hash wrapper

// === Auth ===
isLoggedIn()                      // login อยู่ไหม
isStaff()                         // staff/admin ไหม
isAdmin()                         // admin ไหม
requireLogin()                    // บังคับ login (redirect)
requireStaff()                    // บังคับ staff+ (redirect)
requireAdmin()                    // บังคับ admin (redirect)
requireStaffApi()                 // บังคับ staff+ (JSON 401)
getCurrentUser()                  // ดึง user data จาก session

// === Redirect & Flash ===
redirect($url)                    // redirect + exit
setFlash($type, $msg)             // ตั้ง flash (success/error/info)
getFlash()                        // ดึง flash
displayFlash()                    // แสดง flash (HTML)

// === Validation ===
isValidEmail($email)              // email format
isValidPhone($phone)              // 9-10 digits
validatePassword($pw)             // return error string | null
validateMemberData($data)         // return errors array
validateMaxLength($val, $max)     // ความยาว
validateName($name)               // ชื่อ

// === Rate Limit ===
checkRateLimit($key, $max, $window) // เกิน limit ไหม ($max=attempts, $window=minutes)
incrementRateLimit($key)          // +1 counter
resetRateLimit($key)              // reset

// === Session ===
startSession()                    // HttpOnly, SameSite=Lax, Secure, lifetime
```

### 7.2 Config Constants สำคัญ

```php
// Database
DB_HOST, DB_NAME, DB_USER, DB_PASS

// Application
APP_NAME              // ชื่อแอป (ใช้แสดงใน UI)
APP_URL               // URL ฐาน (ใช้ใน redirect)
APP_DEBUG             // true = แสดง errors

// Business Rules (แก้ใน .env)
DEFAULT_BORROW_DAYS   // วันยืมเริ่มต้น (7)
MAX_BORROW_BOOKS      // ยืมสูงสุด (3)
FINE_PER_DAY          // ค่าปรับ/วัน (10 บาท)

// Security (แก้ใน .env)
MIN_PASSWORD_LENGTH        // รหัสผ่านขั้นต่ำ (6)
RATE_LIMIT_MAX_ATTEMPTS    // จำนวนครั้งสูงสุด (5)
RATE_LIMIT_WINDOW_MINUTES  // ช่วงเวลา (15 นาที)
SESSION_LIFETIME           // อายุ session (3600 วินาที)
```

### 7.3 Invariants ระดับระบบ (ห้ามพัง)

| Invariant | เหตุผล | ตรวจสอบที่ |
|-----------|--------|-----------|
| `books.available >= 0` | Stock ห้ามติดลบ | `decrementAvailable()` มี WHERE available > 0 + CHECK constraint |
| Return ต้องคืน stock | ไม่งั้น stock หาย | `BorrowService::returnBook()` → `incrementAvailable()` |
| Cancel/Expire reservation ต้องคืน stock | ไม่งั้น stock หาย | `ReservationService::cancelReservation()` / `expireOverdue` |
| Borrow ต้องลด stock | ไม่งั้น stock ไม่ตรง | `BorrowService::createBorrow()` → `decrementAvailable()` |
| Reserve ต้องลด stock ทันที | กัน stock | `ReservationService::createReservation()` → `decrementAvailable()` |
| Fulfill ไม่แตะ stock | หักไปตอนจอง | `ReservationService::fulfillReservation()` |
| Fine = days_overdue × FINE_PER_DAY | สูตรเดียว | `BorrowService::calculateFine()` |
| Payment ต่อ borrow ได้ครั้งเดียว | ห้ามจ่ายซ้ำ | `payments.borrow_id` UNIQUE constraint |
| `books.quantity >= books.available` | available ห้ามเกิน quantity | CHECK constraint ใน DB |
| Session regenerate หลัง login | ป้องกัน session fixation | `login.php` |
| Password hash ด้วย `password_hash()` | ห้าม hash เอง | `hashPassword()` ใน functions.php |
| Error message login ต้องกว้างๆ | ป้องกัน user enumeration | `AuthService::login()` return null เหมือนกัน |

---

## สรุป

### Flows ที่ครอบคลุมแล้ว (8 flows)

| # | Flow | หมวด | Entry Point |
|---|------|------|-------------|
| 1 | Login | Auth | `login.php` |
| 2 | Register | Auth | `register.php` |
| 3 | Create Borrow | Core Transaction | `admin/borrow_form.php` |
| 4 | Return Book | Core Transaction | `admin/borrows.php` |
| 5 | Reserve Book | API + Stock | `api/reserve_book.php` |
| 6 | Fulfill Reservation | Admin Action | `admin/reservations.php` |
| 7 | Pay Fine | Payment | `admin/payments.php` |
| 8 | Create Book | Admin CRUD | `admin/book_form.php` |

### ส่วนที่ยังไม่ได้ศึกษาเชิงลึก

| ส่วน | หมายเหตุ |
|------|----------|
| Forgot/Reset password | ใช้ token + email, PasswordResetRepository |
| Update profile | คล้าย register แต่มี ownership check, email ห้ามเปลี่ยน |
| Delete book/member | Integrity checks (ห้ามลบถ้ามียืม/จอง active) |
| Cancel reservation (user) | `api/cancel_reservation.php` → redirect, ต้องคืน stock |
| Reports + CSV/PDF | Read-only, ใช้ report_helper.php mapping |
| Settings | Admin-only, upsert ใน settings table |
| Import books/members | File upload + batch insert + error handling |
| Cron jobs | CLI only, expire reservations + cleanup tokens |
| Dashboard | Aggregate read-only stats จากหลาย repositories |

### วิธีศึกษาต่อ

1. อ่าน Entry Point ที่เกี่ยวข้อง (ดูหัว comment ⭐ สำหรับคนมาใหม่)
2. ตามไปดู Service ที่ถูกเรียก
3. ตามไปดู Repository methods
4. ดู `database/schema.sql` สำหรับ table structure + constraints
5. ทดสอบจริงผ่าน browser หรือ curl

---

*เอกสารนี้สร้างจากโค้ดจริงในโปรเจกต์ทั้งหมด — ไม่มีการเดาหรือแต่งเพิ่ม*
