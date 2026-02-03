# Study Guide V3 - คู่มือศึกษาระบบยืมคืนหนังสือ

เอกสารนี้สำหรับ **เจ้าของโปรเจกต์** ที่ต้องการ:
- เข้าใจโครงสร้างระบบ
- ไล่ flow การทำงานได้
- Debug ได้เมื่อระบบพัง
- แก้/เพิ่มฟีเจอร์โดยไม่ทำระบบอื่นพัง

**บริบทระบบ:** PHP page-based + API / ขนาดเล็ก-กลาง / โค้ดส่วนใหญ่เขียนโดย AI

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
│   ├── profile.php          โปรไฟล์ member
│   ├── forgot_password.php  ลืมรหัสผ่าน
│   ├── reset_password.php   รีเซ็ตรหัสผ่าน
│   └── logout.php           ออกจากระบบ
│
├── admin/                   [ENTRY POINT - ADMIN]
│   ├── index.php            Dashboard
│   ├── books.php            รายการหนังสือ + ลบ
│   ├── book_form.php        เพิ่ม/แก้ไขหนังสือ
│   ├── members.php          รายการสมาชิก
│   ├── member_form.php      เพิ่ม/แก้ไขสมาชิก
│   ├── borrows.php          รายการยืม + คืน
│   ├── borrow_form.php      บันทึกการยืม
│   ├── reservations.php     รายการจอง + อนุมัติ/ยกเลิก
│   ├── payments.php         รายการค่าปรับ
│   ├── categories.php       จัดการหมวดหมู่
│   ├── reports.php          รายงาน
│   ├── settings.php         ตั้งค่าระบบ
│   ├── header.php           UI component
│   └── footer.php           UI component
│
├── api/                     [ENTRY POINT - API]
│   ├── search_books.php     GET - ค้นหาหนังสือ (HTML response)
│   ├── reserve_book.php     POST - จองหนังสือ (JSON response)
│   └── add_member.php       POST - เพิ่มสมาชิกด่วน (JSON response)
│
├── app/
│   ├── Services/            [SERVICE LAYER]
│   │   ├── AuthService.php
│   │   ├── BookService.php
│   │   ├── BorrowService.php
│   │   ├── ReservationService.php
│   │   ├── MemberService.php
│   │   ├── ReportService.php
│   │   ├── HomeService.php
│   │   └── DashboardService.php
│   │
│   └── Repositories/        [REPOSITORY LAYER]
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
├── includes/                [HELPERS / CONFIG]
│   ├── config.php           ★ ค่าคงที่ทั้งระบบ
│   ├── db.php               ★ PDO connection
│   ├── functions.php        ★ Helper functions ทั้งหมด
│   ├── header.php           HTML header (public)
│   ├── footer.php           HTML footer (public)
│   ├── book_grid.php        Component แสดงหนังสือ
│   └── modal.js             JavaScript สำหรับ modal
│
├── database/                [DATABASE]
│   ├── schema.sql           โครงสร้างตาราง
│   ├── sample_data.sql      ข้อมูลตัวอย่าง
│   └── migrations/          ALTER TABLE scripts
│
├── uploads/                 [STORAGE]
│   ├── .htaccess            ป้องกัน direct access
│   └── covers/              รูปปกหนังสือ
│
├── cron/                    [SCHEDULED JOBS]
│   ├── expire_reservations.php
│   └── cleanup_tokens.php
│
├── tests/                   [TESTS]
├── logs/                    [LOGS]
├── docs/                    [DOCUMENTATION]
│
├── .env                     Environment variables
├── .env.example             Template
└── install.php              Setup wizard
```

### 1.2 หน้าที่ของแต่ละ Layer

| Layer | โฟลเดอร์ | หน้าที่ | ควรทำ | ห้ามทำ |
|-------|---------|--------|-------|--------|
| **Entry Point** | `/*.php`, `admin/`, `api/` | รับ HTTP request, auth, CSRF, เรียก Service, render output | รับ/sanitize input, ตรวจสิทธิ์, redirect/JSON | เขียน SQL, business logic ซับซ้อน |
| **Service** | `app/Services/` | Business logic, transactions | Rules, validation ซับซ้อน, coordinate repos | SQL โดยตรง, access $_POST/$_SESSION |
| **Repository** | `app/Repositories/` | SQL queries | SELECT/INSERT/UPDATE/DELETE, row locking | Business logic, echo/print |
| **Helpers** | `includes/functions.php` | Utility functions | Auth check, CSRF, validation, formatting | Business logic, SQL |
| **Config** | `includes/config.php` | Constants | อ่าน .env, define ค่าคงที่ | Logic |

### 1.3 Entry Points สำคัญ (10 ไฟล์ที่ควรอ่านก่อน)

| # | ไฟล์ | เหตุผล |
|---|------|--------|
| 1 | `bootstrap.php` | **จุดเริ่มต้นทุกหน้า** - เข้าใจว่าระบบ load อะไรบ้าง |
| 2 | `includes/config.php` | **ค่าคงที่ทั้งระบบ** - รู้ว่า business rules มาจากไหน |
| 3 | `includes/functions.php` | **Helper ทั้งหมด** - auth, CSRF, validation อยู่ที่นี่ |
| 4 | `includes/db.php` | **DB connection** - เข้าใจ PDO singleton |
| 5 | `login.php` | **ตัวอย่าง auth flow** - rate limit, session management |
| 6 | `app/Services/BorrowService.php` | **Business logic หลัก** - transaction, locking, fine calculation |
| 7 | `app/Repositories/BookRepository.php` | **ตัวอย่าง Repository** - SQL patterns, FOR UPDATE |
| 8 | `admin/borrow_form.php` | **ตัวอย่าง admin page** - CSRF, idempotency, multi-select |
| 9 | `api/reserve_book.php` | **ตัวอย่าง API** - JSON response, error handling |
| 10 | `database/schema.sql` | **โครงสร้าง DB** - เข้าใจ relations, constraints |

---

## 2. Request → Response Flow

### 2.1 Flow มาตรฐาน

```
┌─────────────────────────────────────────────────────────────────────┐
│ 1. BROWSER                                                          │
│    GET /admin/borrows.php                                           │
│    POST /admin/borrows.php?action=return                            │
└─────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 2. ENTRY POINT (admin/borrows.php)                                  │
│    ├── require '../bootstrap.php'    ← โหลด config, db, helpers    │
│    ├── requireStaff()                ← ตรวจสิทธิ์                   │
│    ├── validateCSRFToken()           ← ตรวจ CSRF (POST only)        │
│    ├── $borrowId = (int)$_POST['id'] ← รับ + sanitize input         │
│    └── $service->returnBook(...)     ← เรียก Service                │
└─────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 3. SERVICE (BorrowService)                                          │
│    ├── $pdo->beginTransaction()      ← เริ่ม transaction            │
│    ├── validate business rules       ← ตรวจ quota, status           │
│    ├── $repo->findByIdForUpdate()    ← เรียก Repository (lock row) │
│    ├── calculateFine()               ← คำนวณค่าปรับ                 │
│    ├── $repo->update(...)            ← บันทึกการเปลี่ยนแปลง         │
│    └── $pdo->commit()                ← commit transaction           │
└─────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 4. REPOSITORY (BorrowRepository, BookRepository)                    │
│    ├── $stmt = $pdo->prepare("SELECT ... FOR UPDATE")               │
│    ├── $stmt->execute([$id])         ← ใช้ prepared statement       │
│    └── return $stmt->fetch()         ← return array                 │
└─────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 5. DATABASE (MySQL)                                                 │
│    ├── borrows table                 ← UPDATE status, return_date   │
│    └── books table                   ← UPDATE available + 1         │
└─────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 6. RESPONSE                                                         │
│    ├── Web: setFlash() → redirect()  ← flash message + redirect     │
│    └── API: echo json_encode([...])  ← JSON response                │
└─────────────────────────────────────────────────────────────────────┘
```

### 2.2 Boundary ของแต่ละ Layer

#### Entry Point (รับ Input)

```php
// ✓ ทำ
$userId = (int) $_POST['user_id'];        // sanitize
$email = trim($_POST['email'] ?? '');     // trim + null check
validateCSRFToken($_POST['csrf_token']);  // CSRF check
requireStaff();                            // auth check

// ✗ ห้ามทำ
$stmt = $pdo->prepare("SELECT ...");      // ห้ามเขียน SQL
if ($available > 0 && $quota < 3) {...}   // ห้าม business logic ซับซ้อน
```

#### Service (Business Logic)

```php
// ✓ ทำ
$pdo->beginTransaction();
if ($borrowCount >= MAX_BORROW_BOOKS) {
    throw new Exception('เกินโควต้า');
}
$this->repo->update($id, $data);
$pdo->commit();

// ✗ ห้ามทำ
$stmt = $pdo->prepare("SELECT ...");      // ห้ามเขียน SQL โดยตรง
$_SESSION['user_id'];                      // ห้าม access superglobals
echo json_encode($result);                 // ห้าม output
```

#### Repository (SQL)

```php
// ✓ ทำ
$stmt = $this->pdo->prepare("SELECT * FROM books WHERE id = ?");
$stmt->execute([$id]);
return $stmt->fetch() ?: null;

// ✗ ห้ามทำ
if ($book['available'] < $quantity) {...}  // ห้าม business logic
$pdo->beginTransaction();                   // ห้าม manage transaction
```

### 2.3 สิ่งที่ห้ามทำในแต่ละ Layer (สรุป)

| Layer | ห้ามทำ | เหตุผล |
|-------|--------|--------|
| Entry Point | SQL โดยตรง | แยก concern, testability |
| Entry Point | Business logic ซับซ้อน | ย้ายไป Service |
| Service | SQL โดยตรง | ใช้ Repository |
| Service | Access $_POST/$_SESSION | รับเป็น parameter แทน |
| Service | echo/print | return data แทน |
| Repository | Business logic | แค่ query data |
| Repository | Transaction management | Service ทำ |
| Helper | SQL | แค่ utility |
| Helper | Business logic | แค่ utility |

---

## 3. Core Flows

### 3.1 Flow: Login (เข้าสู่ระบบ)

#### Goal
ให้ผู้ใช้ authenticate ด้วย email/password และสร้าง session

#### Entry Point
`login.php` | Method: `POST`

#### Inputs + Validation

| Input | Validation |
|-------|------------|
| `email` | ไม่ว่าง |
| `password` | ไม่ว่าง |

#### Authorization / CSRF / Guards
- ไม่ต้อง auth (หน้า public)
- ไม่มี CSRF (เพราะ login ใช้ rate limit แทน)
- Rate limit: `checkRateLimit('login_' . md5($email), 15, 5)` - 5 ครั้ง/15 นาที

#### Steps การทำงาน

```
1. ตรวจว่า login อยู่แล้วไหม → ถ้าใช่ redirect ไป index.php
2. รับ $_POST['email'], $_POST['password']
3. Validate: ทั้งคู่ต้องไม่ว่าง
4. checkRateLimit() → ถ้าเกิน แสดง error
5. เรียก AuthService::login($email, $password)
   - UserRepository::findByEmail($email)
   - password_verify($password, $user['password'])
6. ถ้าสำเร็จ:
   - session_regenerate_id(true)
   - $_SESSION['user_id'] = $user['id']
   - $_SESSION['user_role'] = $user['role']
   - resetRateLimit()
   - redirect ตาม role
7. ถ้าไม่สำเร็จ:
   - incrementRateLimit()
   - แสดง error (ไม่บอกว่า email หรือ password ผิด)
```

#### DB Changes
- **Read only:** `users` table

#### Output

| Case | Response |
|------|----------|
| Success (admin/staff) | 302 → `/admin/` |
| Success (member) | 302 → `/index.php` |
| Failure | แสดง error message |

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
| Rate limit key ใช้ email | ป้องกัน brute force per account |

#### Test Steps

**Happy Path:**
```
1. เปิด /login.php
2. กรอก email: admin@library.com, password: 123456
3. กด Submit
4. ✅ คาดหวัง: redirect ไป /admin/
```

**Failure Case:**
```
1. เปิด /login.php
2. กรอก email ถูก, password ผิด
3. กด Submit 6 ครั้ง
4. ✅ คาดหวัง: "ลองผิดหลายครั้งเกินไป กรุณารอ 15 นาที"
```

---

### 3.2 Flow: Create Borrow (ยืมหนังสือ)

#### Goal
Staff บันทึกการยืมหนังสือให้ member โดยลด stock และสร้าง borrow record

#### Entry Point
`admin/borrow_form.php` | Method: `POST`

#### Inputs + Validation

| Input | Type | Validation |
|-------|------|------------|
| `csrf_token` | string | ต้องตรงกับ session |
| `user_id` | int | > 0, ต้องเป็น member |
| `book_ids[]` | array | ไม่ว่าง, แต่ละ id > 0 |
| `borrow_days` | int | 1-30, default: 7 |

#### Authorization / CSRF / Guards
- `requireStaff()` - ต้องเป็น staff/admin
- `validateCSRFToken()` - ป้องกัน CSRF
- Idempotency key: `borrow_{userId}_{md5(bookIds)}` - ป้องกัน double submit

#### Steps การทำงาน

```
1. requireStaff() → ถ้าไม่ใช่ staff redirect ไป login
2. validateCSRFToken() → ถ้าไม่ผ่าน redirect พร้อม error
3. ตรวจ idempotency key → ถ้าซ้ำ redirect พร้อม warning
4. Validate inputs
5. เรียก BorrowService::createBorrow($userId, $bookIds, $borrowDays)
   ├── beginTransaction()
   ├── UserRepository::lockById() - lock user row
   ├── BorrowRepository::countActiveBorrowsForUpdate() - ตรวจ quota
   ├── Loop each book:
   │   ├── BookRepository::findByIdForUpdate() - lock book row
   │   ├── ตรวจ available > 0
   │   ├── BookRepository::decrementAvailable()
   │   └── BorrowRepository::create()
   └── commit()
6. บันทึก idempotency key
7. setFlash('success', '...')
8. redirect('borrows.php')
```

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `books` | UPDATE | `available = available - 1` (per book) |
| `borrows` | INSERT | 1 row per book |

**Transaction:** ใช่  
**Row Locking:** `SELECT ... FOR UPDATE` บน users และ books

#### Output

| Case | Response |
|------|----------|
| Success | 302 → `/admin/borrows.php` + flash "บันทึกการยืมสำเร็จ" |
| Partial success | flash แจ้งว่าเล่มไหน skip |
| Quota exceeded | error "ผู้ยืมถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว" |

#### Common Failure Cases

| Case | ผลลัพธ์ |
|------|--------|
| Double submit | "รายการนี้ถูกบันทึกไปแล้ว" |
| Stock = 0 | Book ถูก skip, ไม่ fail ทั้ง transaction |
| Quota full | Exception + rollback |
| Concurrent borrow last copy | FOR UPDATE lock คนที่ 2 รอ |

#### จุดระวังเวลาแก้ (Invariants)

| Invariant | เหตุผล |
|-----------|--------|
| `decrementAvailable()` มี `WHERE available > 0` | ป้องกัน stock ติดลบ |
| Transaction ครอบทุก book | ถ้า 1 เล่มพัง ต้อง rollback หมด |
| Lock user ก่อน lock books | ป้องกัน deadlock |

#### Test Steps

**Happy Path:**
```
1. Login เป็น staff
2. เปิด /admin/borrow_form.php
3. เลือก member ที่ยังไม่ยืมอะไร
4. เลือก book 1 เล่มที่ available > 0
5. กด Submit
6. ✅ คาดหวัง: redirect ไป borrows.php + flash "บันทึกการยืมสำเร็จ 1 เล่ม"
7. ✅ ตรวจ DB: books.available ลดลง 1, borrows มี row ใหม่
```

**Failure Case - Quota:**
```
1. Login เป็น staff
2. เปิด /admin/borrow_form.php
3. เลือก member ที่ยืมครบ 3 เล่มแล้ว
4. เลือก book 1 เล่ม
5. กด Submit
6. ✅ คาดหวัง: error "ผู้ยืมถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว"
```

**Edge Case - Double Submit:**
```
1. ทำ Happy Path แล้ว
2. กด Back แล้ว Submit อีกครั้ง (ข้อมูลเหมือนเดิม)
3. ✅ คาดหวัง: "รายการนี้ถูกบันทึกไปแล้ว" (ไม่สร้าง borrow ซ้ำ)
```

---

### 3.3 Flow: Return Book (คืนหนังสือ)

#### Goal
Staff บันทึกการคืนหนังสือ คำนวณค่าปรับ และคืน stock

#### Entry Point
`admin/borrows.php` | Method: `POST` (action=return)

#### Inputs + Validation

| Input | Type | Validation |
|-------|------|------------|
| `csrf_token` | string | ต้องตรง |
| `action` | string | = 'return' |
| `borrow_id` | int | > 0, status='borrowing' |
| `pay_now` | checkbox | optional |

#### Authorization / CSRF / Guards
- `requireStaff()`
- `validateCSRFToken()`
- Idempotency key: `return_{borrowId}`

#### Steps การทำงาน

```
1. requireStaff()
2. validateCSRFToken()
3. ตรวจ idempotency key
4. เรียก BorrowService::returnBook($borrowId, $payNow, $staffId)
   ├── beginTransaction()
   ├── BorrowRepository::findByIdForUpdate() - lock & ตรวจ status='borrowing'
   ├── calculateFine(due_date, today)
   ├── BorrowRepository::update() - status='returned', return_date, fine_amount
   ├── BookRepository::incrementAvailable() - คืน stock
   ├── ถ้า payNow && fine > 0: PaymentRepository::create()
   └── commit()
5. บันทึก idempotency key
6. setFlash() + redirect()
```

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `borrows` | UPDATE | status='returned', return_date, fine_amount |
| `books` | UPDATE | `available = available + 1` |
| `payments` | INSERT | ถ้า pay_now && fine > 0 |

#### Output

| Case | Response |
|------|----------|
| ไม่มีค่าปรับ | "บันทึกการคืนหนังสือสำเร็จ" |
| มีค่าปรับ + จ่าย | "ค่าปรับ: X บาท [รับชำระเงินแล้ว]" |
| มีค่าปรับ + ไม่จ่าย | "ค่าปรับ: X บาท [ยังไม่จ่าย]" |

#### Common Failure Cases

| Case | ผลลัพธ์ |
|------|--------|
| Already returned | "ไม่พบรายการยืมหรือคืนหนังสือแล้ว" |
| Double submit | redirect พร้อม warning |

#### จุดระวังเวลาแก้ (Invariants)

| Invariant | เหตุผล |
|-----------|--------|
| `incrementAvailable()` ต้องเรียกเสมอ | คืน stock ให้ถูกต้อง |
| Fine calculation ต้องอยู่ใน Service | Single source |
| ตรวจ status='borrowing' ก่อน | ป้องกันคืนซ้ำ |

#### Test Steps

**Happy Path (ไม่มีค่าปรับ):**
```
1. Login เป็น staff
2. เปิด /admin/borrows.php
3. หา borrow ที่ status='borrowing' และยังไม่เลย due_date
4. กดปุ่ม "คืน"
5. ✅ คาดหวัง: "บันทึกการคืนหนังสือสำเร็จ"
6. ✅ ตรวจ DB: borrow.status='returned', book.available เพิ่ม 1
```

**Failure Case (มีค่าปรับ):**
```
1. หา borrow ที่เลย due_date แล้ว
2. กดปุ่ม "คืน" โดยไม่ติ๊ก pay_now
3. ✅ คาดหวัง: "ค่าปรับ: X บาท [ยังไม่จ่าย]"
4. ✅ ตรวจ DB: borrow.fine_amount > 0, ไม่มี payment row
```

---

### 3.4 Flow: Reserve Book (จองหนังสือ)

#### Goal
Member จองหนังสือเพื่อมารับทีหลัง โดย stock ถูกกันไว้ทันที

#### Entry Point
`api/reserve_book.php` | Method: `POST` | Response: JSON

#### Inputs + Validation

| Input | Type | Validation |
|-------|------|------------|
| `csrf_token` | string | ต้องตรง |
| `book_id` | int | > 0 |

**Note:** user_id มาจาก `$_SESSION['user_id']` เท่านั้น

#### Authorization / CSRF / Guards
- `isLoggedIn()` - ต้อง login (ทุก role)
- `validateCSRFToken()`
- ตรวจ method = POST

#### Steps การทำงาน

```
1. ตรวจ isLoggedIn() → 401 ถ้าไม่
2. ตรวจ method = POST → 405 ถ้าไม่
3. validateCSRFToken() → 403 ถ้าไม่ผ่าน
4. เรียก ReservationService::createReservation($userId, $bookId)
   ├── beginTransaction()
   ├── ตรวจ hasPendingReservation() - ห้ามจองซ้ำ
   ├── BookRepository::findByIdForUpdate()
   ├── ตรวจ available > 0
   ├── BookRepository::decrementAvailable() - กัน stock
   ├── ReservationRepository::create() - status='pending', expires_at=+2 days
   └── commit()
5. return JSON success
```

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `books` | UPDATE | `available = available - 1` |
| `reservations` | INSERT | status='pending', expires_at |

#### Output

| Case | HTTP | Response |
|------|------|----------|
| Success | 200 | `{"success": true, "message": "จองสำเร็จ!..."}` |
| Not logged in | 401 | `{"success": false, "message": "กรุณาเข้าสู่ระบบ"}` |
| Already reserved | 400 | `{"success": false, "message": "คุณได้จองหนังสือเล่มนี้ไว้แล้ว"}` |
| Out of stock | 400 | `{"success": false, "message": "หนังสือหมด"}` |

#### Common Failure Cases

| Case | ผลลัพธ์ |
|------|--------|
| Concurrent reserve last copy | FOR UPDATE - คนที่ 2 ได้ "หนังสือหมด" |
| Reserve same book twice | hasPendingReservation() ป้องกัน |

#### จุดระวังเวลาแก้ (Invariants)

| Invariant | เหตุผล |
|-----------|--------|
| Stock หักทันทีตอนจอง | กัน stock ไว้ |
| user_id จาก session เท่านั้น | ห้ามรับจาก POST |
| Cancel/Expire ต้องคืน stock | ไม่งั้น stock หาย |

#### Test Steps

**Happy Path (curl):**
```bash
# 1. Login ก่อน (เก็บ cookie)
curl -X POST "http://localhost/book_borrowing/login.php" \
  -d "email=member@test.com&password=123456" \
  -c cookies.txt -L

# 2. จองหนังสือ
curl -X POST "http://localhost/book_borrowing/api/reserve_book.php" \
  -d "book_id=1&csrf_token=YOUR_TOKEN" \
  -b cookies.txt

# ✅ คาดหวัง: {"success": true, "message": "จองสำเร็จ!..."}
```

**Failure Case:**
```bash
# จองเล่มเดิมอีกครั้ง
curl -X POST "http://localhost/book_borrowing/api/reserve_book.php" \
  -d "book_id=1&csrf_token=YOUR_TOKEN" \
  -b cookies.txt

# ✅ คาดหวัง: {"success": false, "message": "คุณได้จองหนังสือเล่มนี้ไว้แล้ว"}
```

---

### 3.5 Flow: Fulfill Reservation (อนุมัติการจอง)

#### Goal
Staff อนุมัติการจอง → สร้าง borrow record อัตโนมัติ

#### Entry Point
`admin/reservations.php` | Method: `POST` (action=approve)

#### Inputs + Validation

| Input | Type | Validation |
|-------|------|------------|
| `csrf_token` | string | ต้องตรง |
| `action` | string | = 'approve' |
| `id` | int | reservation_id > 0, status='pending' |

#### Authorization / CSRF / Guards
- `requireStaff()`
- `validateCSRFToken()`
- Idempotency check

#### Steps การทำงาน

```
1. requireStaff()
2. validateCSRFToken()
3. เรียก ReservationService::fulfillReservation($reservationId)
   ├── beginTransaction()
   ├── ReservationRepository::findPendingForUpdate()
   ├── ตรวจ user quota < MAX_BORROW_BOOKS
   ├── BorrowRepository::create() - สร้าง borrow
   ├── ReservationRepository::update() - status='fulfilled', borrow_id
   └── commit()
4. setFlash() + redirect()
```

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `reservations` | UPDATE | status='fulfilled', borrow_id |
| `borrows` | INSERT | สร้าง borrow record |

**Note:** ไม่ต้อง update books.available เพราะหักไปแล้วตอนจอง

#### จุดระวังเวลาแก้ (Invariants)

| Invariant | เหตุผล |
|-----------|--------|
| ตรวจ quota ก่อน fulfill | member อาจยืมเพิ่มระหว่างรอ |
| ไม่แตะ books.available | stock หักไปแล้วตอนจอง |

#### Test Steps

**Happy Path:**
```
1. Login เป็น staff
2. เปิด /admin/reservations.php
3. หา reservation ที่ status='pending'
4. กดปุ่ม "อนุมัติ"
5. ✅ คาดหวัง: "อนุมัติการจองสำเร็จ! สร้างรายการยืมแล้ว"
6. ✅ ตรวจ DB: reservation.status='fulfilled', มี borrow row ใหม่
```

---

### 3.6 Flow: Create Book (เพิ่มหนังสือ)

#### Goal
Staff เพิ่มหนังสือใหม่เข้าระบบ (พร้อม upload รูปปก)

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

#### Authorization / CSRF / Guards
- `requireStaff()`
- `validateCSRFToken()`

#### Steps การทำงาน

```
1. requireStaff()
2. validateCSRFToken()
3. Validate inputs
4. ถ้ามี file upload:
   - finfo_file() ตรวจ MIME type จาก content
   - ตรวจ size ≤ 2MB
   - สร้างชื่อไฟล์ใหม่: cover_{timestamp}_{uniqid}.{ext}
   - move_uploaded_file()
5. เรียก BookRepository::create($data)
   - available = quantity
6. setFlash() + redirect('books.php')
```

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `books` | INSERT | available = quantity |

**File:** `uploads/covers/cover_*.ext`

#### จุดระวังเวลาแก้ (Invariants)

| Invariant | เหตุผล |
|-----------|--------|
| ตรวจ MIME จาก finfo ไม่ใช่ $_FILES['type'] | $_FILES['type'] ปลอมได้ |
| สร้างชื่อไฟล์ใหม่ | ป้องกัน path traversal |
| available = quantity ตอนสร้าง | initial stock |

#### Test Steps

**Happy Path:**
```
1. Login เป็น staff
2. เปิด /admin/book_form.php
3. กรอก title, author, quantity
4. กด Submit
5. ✅ คาดหวัง: redirect ไป books.php + "เพิ่มหนังสือสำเร็จ"
6. ✅ ตรวจ DB: books มี row ใหม่, available = quantity
```

**Failure Case - ISBN ซ้ำ:**
```
1. กรอก ISBN ที่มีอยู่แล้ว
2. กด Submit
3. ✅ คาดหวัง: error "ISBN นี้มีในระบบแล้ว"
```

---

## 4. Single Source of Truth Map

### 4.1 ตำแหน่งที่ถูกต้องของแต่ละ Concern

| Concern | ตำแหน่ง | ไฟล์/Function |
|---------|---------|---------------|
| **Auth Check** | Helper | `includes/functions.php`: `isLoggedIn()`, `isStaff()`, `isAdmin()` |
| **Auth Guard** | Helper | `includes/functions.php`: `requireLogin()`, `requireStaff()`, `requireAdmin()` |
| **CSRF Generate** | Helper | `includes/functions.php`: `generateCSRFToken()` |
| **CSRF Validate** | Helper | `includes/functions.php`: `validateCSRFToken()` |
| **Rate Limit** | Helper | `includes/functions.php`: `checkRateLimit()`, `incrementRateLimit()` |
| **Password Validation** | Helper | `includes/functions.php`: `validatePassword()` |
| **Email Validation** | Helper | `includes/functions.php`: `isValidEmail()` |
| **XSS Protection** | Helper | `includes/functions.php`: `e()` |
| **Business Constants** | Config | `includes/config.php`: `MAX_BORROW_BOOKS`, `FINE_PER_DAY`, etc. |
| **Fine Calculation** | Service | `app/Services/BorrowService.php`: `calculateFine()` |
| **Quota Check** | Service | `app/Services/BorrowService.php`: ภายใน `createBorrow()` |
| **Stock Management** | Service + Repo | Service เรียก `decrementAvailable()`/`incrementAvailable()` |
| **SQL Queries** | Repository | `app/Repositories/*.php` |
| **DB Connection** | Helper | `includes/db.php`: `getDB()` |

### 4.2 Validation ที่ทำซ้ำ (Dual Validation)

| สิ่งที่ตรวจ | Entry Point | Service | เหตุผลที่ซ้ำได้ |
|------------|-------------|---------|----------------|
| Password format | `validatePassword()` | - | Single source ใน Helper |
| Email format | `isValidEmail()` | - | Single source ใน Helper |
| User exists | หา user มาแสดง form | ตรวจอีกครั้งใน transaction | Concurrency - user อาจถูกลบระหว่าง |
| Book available | UI กรองให้เลือก | ตรวจอีกครั้ง FOR UPDATE | Concurrency - stock อาจเปลี่ยน |
| Quota | - | ตรวจใน transaction | ต้องตรวจพร้อม lock |

**สรุป:** Validation ที่เกี่ยวกับ concurrency ต้องทำซ้ำใน transaction

---

## 5. Debug Playbook

### 5.1 เปิด/ปิด Debug Mode

**เปิด:**
```bash
# แก้ไฟล์ .env
APP_DEBUG=true
```

**ปิด:**
```bash
APP_DEBUG=false
```

**ผลลัพธ์:**
- `APP_DEBUG=true` → Error details แสดงบนหน้าเว็บ
- `APP_DEBUG=false` → แสดงแค่ "ระบบขัดข้อง กรุณาลองใหม่"

### 5.2 Log อยู่ที่ไหน

| ประเภท | ตำแหน่ง |
|--------|---------|
| PHP Errors | `logs/` folder หรือ Apache error log |
| Custom Logs | ใช้ `error_log()` → Apache error log |
| DB Errors | หน้าเว็บ (ถ้า APP_DEBUG=true) |

### 5.3 วิธีไล่ Debug ตาม Error Type

#### HTTP 400 (Bad Request)
```
1. ดู response body → อ่าน error message
2. ตรวจ input validation ใน Entry Point
3. ดู $errors array ใน Entry Point
4. ตรวจว่า required fields ส่งมาครบไหม
```

#### HTTP 401 (Unauthorized)
```
1. ตรวจว่า user login อยู่ไหม → $_SESSION['user_id']
2. ตรวจ session หมดอายุไหม → SESSION_LIFETIME = 3600s
3. ดูว่า isLoggedIn() return อะไร
4. ตรวจ cookie ถูกส่งมาไหม
```

#### HTTP 403 (Forbidden)
```
1. CSRF token หมดอายุ/ไม่ตรง:
   - ลอง refresh หน้า form แล้ว submit ใหม่
   - ตรวจว่า token ถูกส่งมาจริงใน $_POST
2. ไม่มีสิทธิ์:
   - ตรวจ user role ใน $_SESSION['user_role']
   - ดูว่าหน้านั้นใช้ requireStaff() หรือ requireAdmin()
```

#### HTTP 500 (Internal Server Error)
```
1. เปิด APP_DEBUG=true ดู error
2. ตรวจ PHP error log
3. Common causes:
   - PDOException (DB connection, SQL syntax)
   - File permission (uploads/, logs/)
   - Missing class (autoload fail)
   - Transaction ไม่ commit/rollback
```

### 5.4 ตัวอย่าง Debug ด้วย curl

#### 1. ทดสอบ Login
```bash
curl -X POST "http://localhost/book_borrowing/login.php" \
  -d "email=admin@library.com&password=123456" \
  -c cookies.txt -L -v

# ดู response headers และ redirect
```

#### 2. ทดสอบ Search API
```bash
curl "http://localhost/book_borrowing/api/search_books.php?search=php" -v

# ดู HTML response
```

#### 3. ทดสอบ Reserve (ต้อง login ก่อน)
```bash
# Step 1: Login
curl -X POST "http://localhost/book_borrowing/login.php" \
  -d "email=member@test.com&password=123456" \
  -c cookies.txt -L

# Step 2: ดู CSRF token จาก session (ต้องมาจากหน้าเว็บ)

# Step 3: Reserve
curl -X POST "http://localhost/book_borrowing/api/reserve_book.php" \
  -d "book_id=1&csrf_token=YOUR_TOKEN" \
  -b cookies.txt

# ดู JSON response
```

### 5.5 Debug Checklist (เวลาระบบพัง)

```
□ APP_DEBUG=true ใน .env?
□ ดู error message บนหน้าเว็บ
□ ดู PHP error log
□ Session started? (bootstrap.php มี startSession())
□ DB connection works? (ลอง getDB() ใน test file)
□ CSRF token valid? (validateCSRFToken() return true?)
□ User logged in? (isLoggedIn() return true?)
□ User has permission? (isStaff()/isAdmin() return true?)
□ Input validation passed? (ดู $errors array)
□ Service exception? (ลอง try-catch ดู message)
□ Transaction commit/rollback ครบ?
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
| สูตรค่าปรับ | `app/Services/BorrowService.php` | แก้ `calculateFine()` |
| อายุการจอง | `app/Services/ReservationService.php` | param `$expireDays` default 2 |

### 6.2 แก้ Validation

| ต้องการ | แก้ที่ | Function |
|---------|-------|----------|
| Password length | `.env` | `MIN_PASSWORD_LENGTH=8` |
| Email format | `includes/functions.php` | `isValidEmail()` |
| Phone format | `includes/functions.php` | `isValidPhone()` |
| Custom validation | `includes/functions.php` | สร้าง function ใหม่ |

### 6.3 แก้ Permission

| ต้องการ | แก้ที่ |
|---------|-------|
| เปลี่ยน access level | Entry Point: เปลี่ยน `requireStaff()` ↔ `requireAdmin()` |
| เพิ่ม role ใหม่ | 1. `database/schema.sql`: แก้ ENUM |
|  | 2. `includes/functions.php`: เพิ่ม `isNewRole()`, `requireNewRole()` |

### 6.4 แก้ SQL

| ต้องการ | แก้ที่ |
|---------|-------|
| Query หนังสือ | `app/Repositories/BookRepository.php` |
| Query การยืม | `app/Repositories/BorrowRepository.php` |
| Query user | `app/Repositories/UserRepository.php` |
| Query การจอง | `app/Repositories/ReservationRepository.php` |
| Query รายงาน | `app/Repositories/ReportRepository.php` |

**กฎเหล็ก:** SQL ต้องอยู่ใน Repository เท่านั้น

### 6.5 เพิ่ม Field ใหม่ในตาราง

**ตัวอย่าง:** เพิ่ม `publisher` ในตาราง `books`

**ลำดับที่ต้องแก้:**

```
1. DATABASE
   └── สร้าง migration: database/migrations/XXX_add_publisher.sql
       ALTER TABLE books ADD COLUMN publisher VARCHAR(100);

2. REPOSITORY
   └── app/Repositories/BookRepository.php
       - findById(): เพิ่มใน SELECT
       - create(): เพิ่มใน INSERT
       - update(): เพิ่มใน UPDATE

3. ENTRY POINT (Form)
   └── admin/book_form.php
       - เพิ่ม <input name="publisher">
       - รับค่าจาก $_POST['publisher']

4. ENTRY POINT (Display)
   └── admin/books.php, book.php
       - แสดง <?= e($book['publisher']) ?>

5. TEST
   □ Create: เพิ่มหนังสือพร้อม publisher
   □ Read: ดูรายการ - เห็น publisher
   □ Update: แก้ไข publisher
```

### 6.6 เพิ่ม API Endpoint ใหม่

**ลำดับที่ต้องทำ:**

```
1. สร้างไฟล์ api/new_endpoint.php

2. ใช้ Template:
```

```php
<?php
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

// 1. Auth Check
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 2. Method Check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// 3. CSRF Check
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
3. TEST
   □ curl ทดสอบ success case
   □ curl ทดสอบ 401 (ไม่ login)
   □ curl ทดสอบ 403 (CSRF ผิด)
   □ curl ทดสอบ 400 (input ผิด)
```

### 6.7 Checklist หลังแก้ไข

```
□ ทดสอบ happy path
□ ทดสอบ failure cases
□ ตรวจว่าไม่มี linter errors
□ ตรวจว่า SQL ไม่มี syntax error
□ ตรวจว่า transaction commit/rollback ครบ
□ ตรวจว่า CSRF protection ยังทำงาน
□ ตรวจว่า auth protection ยังทำงาน
```

---

## 7. Quick Reference

### 7.1 Helper Functions สำคัญ

```php
// === Security ===
e($str)                       // Escape HTML (ป้องกัน XSS)
generateCSRFToken()           // สร้าง CSRF token
validateCSRFToken($token)     // ตรวจ CSRF token

// === Auth ===
isLoggedIn()                  // ตรวจว่า login อยู่ไหม
isStaff()                     // ตรวจว่าเป็น staff/admin ไหม
isAdmin()                     // ตรวจว่าเป็น admin ไหม
requireLogin()                // บังคับ login (redirect ถ้าไม่)
requireStaff()                // บังคับ staff/admin
requireAdmin()                // บังคับ admin

// === Redirect & Flash ===
redirect($url)                // redirect + exit
setFlash($type, $msg)         // ตั้ง flash message
getFlash()                    // ดึง flash message
displayFlash()                // แสดง flash (HTML)

// === Validation ===
isValidEmail($email)          // ตรวจ email format
isValidPhone($phone)          // ตรวจ phone (9-10 digits)
validatePassword($pw)         // ตรวจ password (return error หรือ null)
validateMaxLength($val, $max) // ตรวจความยาว

// === Rate Limit ===
checkRateLimit($key, $min, $max)  // ตรวจว่าเกิน limit ไหม
incrementRateLimit($key)          // เพิ่ม counter
resetRateLimit($key)              // reset counter

// === Formatting ===
formatDate($date, $fmt)       // จัดรูปแบบวันที่
formatFine($amount)           // จัดรูปแบบค่าปรับ
```

### 7.2 Config Constants สำคัญ

```php
// Database
DB_HOST, DB_NAME, DB_USER, DB_PASS

// Application
APP_NAME           // ชื่อแอป
APP_URL            // URL ฐาน
APP_DEBUG          // true/false

// Business Rules
DEFAULT_BORROW_DAYS     // วันยืมเริ่มต้น (7)
MAX_BORROW_BOOKS        // ยืมสูงสุด (3)
FINE_PER_DAY            // ค่าปรับ/วัน (10 บาท)

// Security
MIN_PASSWORD_LENGTH        // รหัสผ่านขั้นต่ำ (6)
RATE_LIMIT_MAX_ATTEMPTS    // จำนวนครั้งสูงสุด (5)
RATE_LIMIT_WINDOW_MINUTES  // ช่วงเวลานับ (15 นาที)
SESSION_LIFETIME           // อายุ session (3600 วินาที)
```

### 7.3 Invariants ระดับระบบ

| Invariant | เหตุผล | ตรวจสอบที่ |
|-----------|--------|-----------|
| `books.available >= 0` | Stock ห้ามติดลบ | `decrementAvailable()` มี `WHERE available > 0` |
| Return ต้องคืน stock | ไม่งั้น stock หาย | `BorrowService::returnBook()` เรียก `incrementAvailable()` |
| Cancel/Expire reservation ต้องคืน stock | ไม่งั้น stock หาย | `ReservationService::cancelReservation()` |
| Borrow ต้องลด stock | ไม่งั้น stock ไม่ตรง | `BorrowService::createBorrow()` เรียก `decrementAvailable()` |
| Reserve ต้องลด stock | กัน stock ไว้ | `ReservationService::createReservation()` |
| Fulfill ไม่แตะ stock | หักไปแล้วตอนจอง | `ReservationService::fulfillReservation()` |
| Fine = days_overdue × FINE_PER_DAY | สูตรเดียว | `BorrowService::calculateFine()` |
| Session regenerate หลัง login | ป้องกัน session fixation | `login.php` |
| Password hash ด้วย `password_hash()` | ห้าม hash เอง | `AuthService::register()` |

---

## สรุป

### Flows ที่ครอบคลุมแล้ว

| # | Flow | หมวด |
|---|------|------|
| 1 | Login | Auth |
| 2 | Create Borrow | Core Transaction |
| 3 | Return Book | Core Transaction |
| 4 | Reserve Book | API + Stock |
| 5 | Fulfill Reservation | Admin Action |
| 6 | Create Book | Admin CRUD |

### ส่วนที่ยังไม่ได้ศึกษาเชิงลึก

| ส่วน | หมายเหตุ |
|------|----------|
| Register flow | คล้าย login + validation |
| Forgot/Reset password | ใช้ token + email |
| Update profile | คล้าย create แต่มี ownership check |
| Reports | Query-only, ไม่มี transaction |
| Settings | Admin-only CRUD |
| Payment recording | คล้าย return + payment insert |
| Import books/members | File upload + batch insert |
| Cron jobs | Background tasks |

### วิธีศึกษาต่อ

1. อ่าน Entry Point ที่เกี่ยวข้อง
2. ตามไปดู Service ที่ถูกเรียก
3. ตามไปดู Repository methods
4. ดู schema.sql สำหรับ table structure
5. ทดสอบจริงผ่าน browser/curl

---

*เอกสารนี้สร้างจากโค้ดจริงในโปรเจกต์ทั้งหมด ไม่มีการเดาหรือแต่งเพิ่ม*
