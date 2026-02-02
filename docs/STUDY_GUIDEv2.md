# Study Guide V2 - คู่มือศึกษาระบบยืมคืนหนังสือ (ฉบับสมบูรณ์)

เอกสารนี้สำหรับ **เจ้าของโปรเจกต์** ที่ให้ AI เขียนโค้ดส่วนใหญ่ เพื่อให้สามารถ:
- อ่านโค้ดได้เอง
- เข้าใจ flow การทำงาน
- ทดสอบระบบได้
- แก้ไขระบบได้โดยไม่พัง

---

## สารบัญ

1. [แผนที่โปรเจกต์ (Project Map)](#1-แผนที่โปรเจกต์-project-map)
2. [Request → Response Flow](#2-request--response-flow)
3. [Core Flows](#3-core-flows-8-flows)
4. [Single Source of Truth Map](#4-single-source-of-truth-map)
5. [Debug Playbook](#5-debug-playbook)
6. [Modification Guide](#6-modification-guide-แก้ระบบแบบไม่พัง)

---

## 1. แผนที่โปรเจกต์ (Project Map)

### 1.1 โฟลเดอร์สำคัญและหน้าที่

```
book_borrowing/
├── *.php              → Public Entry Points (หน้าเว็บสำหรับ user)
├── admin/             → Admin Panel (หน้าจัดการสำหรับ staff/admin)
├── api/               → API Endpoints (JSON/HTML responses)
├── app/
│   ├── Services/      → Business Logic Layer
│   └── Repositories/  → Data Access Layer (SQL queries)
├── includes/          → Shared: config, DB, helpers, UI components
├── database/          → SQL schema และ migrations
├── uploads/covers/    → รูปปกหนังสือ (user uploads)
├── cron/              → Scheduled tasks
├── tests/             → Test files
├── docs/              → Documentation
└── logs/              → Application logs
```

| โฟลเดอร์ | หน้าที่หลัก | ตัวอย่างไฟล์ |
|----------|------------|-------------|
| `/` (root) | Public pages ที่ user เข้าถึงได้ | `index.php`, `login.php`, `book.php` |
| `admin/` | หน้าจัดการสำหรับ staff/admin (ป้องกันด้วย `requireStaff()`) | `books.php`, `borrows.php`, `settings.php` |
| `api/` | API endpoints สำหรับ AJAX | `search_books.php`, `reserve_book.php` |
| `app/Services/` | Business logic, transactions, rules | `BorrowService.php`, `AuthService.php` |
| `app/Repositories/` | SQL queries, data access | `BookRepository.php`, `UserRepository.php` |
| `includes/` | Shared components | `config.php`, `functions.php`, `db.php` |
| `database/` | Database schema | `schema.sql`, `migrations/` |
| `uploads/` | User-uploaded files | `covers/cover_*.png` |
| `cron/` | Scheduled jobs | `expire_reservations.php` |

### 1.2 ไฟล์ Entry Point สำคัญ (อ่านก่อน 10 ไฟล์)

| ลำดับ | ไฟล์ | เหตุผลที่ต้องอ่านก่อน |
|-------|------|---------------------|
| 1 | `bootstrap.php` | **จุดเริ่มต้นของทุกหน้า** - โหลด config, DB, helpers, autoloader |
| 2 | `includes/config.php` | ค่าคงที่ทั้งระบบ (DB credentials, business rules) อ่านจาก `.env` |
| 3 | `includes/functions.php` | **Helper functions ทั้งหมด** - auth, CSRF, validation, formatting |
| 4 | `includes/db.php` | PDO connection (Singleton pattern) |
| 5 | `login.php` | ตัวอย่าง **authentication flow** + rate limiting + security practices |
| 6 | `app/Services/BorrowService.php` | **Business logic หลัก** - ยืม/คืน/ค่าปรับ + transactions |
| 7 | `app/Repositories/BookRepository.php` | ตัวอย่าง **Repository pattern** + row locking |
| 8 | `admin/borrow_form.php` | ตัวอย่าง **admin page** + CSRF + idempotency protection |
| 9 | `api/reserve_book.php` | ตัวอย่าง **API endpoint** - auth/CSRF/validation/JSON response |
| 10 | `admin/index.php` | **Dashboard** - เห็นภาพรวมว่าระบบ query อะไรบ้าง |

---

## 2. Request → Response Flow

### 2.1 Flow ภาพรวม

```
┌─────────────────────────────────────────────────────────────────────────┐
│ Browser (User)                                                          │
│   GET /admin/borrow_form.php                                            │
│   POST /admin/borrow_form.php (with form data)                          │
└─────────────────────────────────────────────────────────────────────────┘
                │
                │ HTTP Request
                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ 1. Entry Point (admin/borrow_form.php)                                  │
│    ─────────────────────────────────────                                │
│    require_once '../bootstrap.php';     ← โหลด config, DB, helpers      │
│    requireStaff();                      ← ตรวจสิทธิ์ (redirect ถ้าไม่ผ่าน)│
│    validateCSRFToken($_POST['csrf_token']); ← ตรวจ CSRF                 │
│    $userId = (int) $_POST['user_id'];   ← รับ & sanitize input          │
└─────────────────────────────────────────────────────────────────────────┘
                │
                │ Validated Input
                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ 2. Service Layer (app/Services/BorrowService.php)                       │
│    ─────────────────────────────────────────────                        │
│    createBorrow($userId, $bookIds, $borrowDays)                         │
│    • Validate business rules (quota, availability)                      │
│    • Begin Transaction                                                  │
│    • Lock rows (FOR UPDATE) ← ป้องกัน race condition                    │
│    • Call Repository methods                                            │
│    • Commit / Rollback                                                  │
└─────────────────────────────────────────────────────────────────────────┘
                │
                │ Repository Calls
                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ 3. Repository Layer (app/Repositories/)                                 │
│    ─────────────────────────────────────                                │
│    BookRepository::findByIdForUpdate($id)   ← SELECT ... FOR UPDATE     │
│    BookRepository::decrementAvailable($id)  ← UPDATE books SET...       │
│    BorrowRepository::create($data)          ← INSERT INTO borrows       │
└─────────────────────────────────────────────────────────────────────────┘
                │
                │ PDO Query
                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ 4. Database (MySQL)                                                     │
└─────────────────────────────────────────────────────────────────────────┘
                │
                │ Result
                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ 5. Response                                                             │
│    ─────────                                                            │
│    • Web Page: setFlash('success', '...') → redirect('borrows.php')     │
│    • API: echo json_encode(['success' => true, 'data' => ...])          │
└─────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Boundary (ขอบเขตความรับผิดชอบ)

| Layer | ตำแหน่ง | ควรทำ | ห้ามทำ |
|-------|---------|-------|--------|
| **Entry Point** | `*.php`, `admin/*.php`, `api/*.php` | รับ input, ตรวจ auth/CSRF, เรียก Service, render response | เขียน SQL, Business logic ซับซ้อน |
| **Service** | `app/Services/*.php` | Business logic, transactions, validation rules, เรียก Repository | เขียน SQL โดยตรง, output HTML/JSON |
| **Repository** | `app/Repositories/*.php` | SQL queries (SELECT/INSERT/UPDATE/DELETE), return arrays | Business logic, session access |
| **Helpers** | `includes/functions.php` | Utility functions, formatting, security helpers | Database queries |

### 2.3 ตัวอย่าง Code Path: ยืมหนังสือ

```
admin/borrow_form.php
  └── validateCSRFToken()                    [includes/functions.php]
  └── BorrowService::createBorrow()          [app/Services/BorrowService.php]
        └── $pdo->beginTransaction()
        └── UserRepository::lockById()       [app/Repositories/UserRepository.php]
        └── BorrowRepository::countActiveBorrowsForUpdate()
        └── BookRepository::findByIdForUpdate()
        └── BookRepository::decrementAvailable()
        └── BorrowRepository::create()
        └── $pdo->commit()
  └── setFlash('success', '...')             [includes/functions.php]
  └── redirect('borrows.php')                [includes/functions.php]
```

---

## 3. Core Flows (8 Flows)

### 3.1 Flow: User Login

#### Goal
ให้ผู้ใช้ authenticate ด้วย email/password และสร้าง session

#### Entry Point
- **ไฟล์:** `login.php`
- **Method:** `POST`

#### Inputs

| Parameter | Type | Required | หมายเหตุ |
|-----------|------|----------|----------|
| `email` | string | Yes | ไม่ว่าง |
| `password` | string | Yes | ไม่ว่าง |

#### Steps การทดสอบ

```
1. เปิดหน้า GET /login.php
2. กรอก email และ password
3. กด Submit
4. ⏳ System ตรวจ rate limit (checkRateLimit)
5. ⏳ System validate input
6. ⏳ System เรียก AuthService::login()
7. ✅ ถ้าสำเร็จ → redirect ตาม role
   ❌ ถ้าไม่สำเร็จ → แสดง error
```

**ทดสอบ Success:**
- ใช้ email: `admin@library.com`, password: `123456`
- คาดหวัง: redirect ไป `/admin/`

**ทดสอบ Failure:**
- ใช้ password ผิด 6 ครั้ง
- คาดหวัง: แสดง "ลองผิดหลายครั้งเกินไป กรุณารอ 15 นาที"

#### Validation Rules

| Rule | Implementation |
|------|----------------|
| Email ไม่ว่าง | `empty($email)` check in login.php |
| Password ไม่ว่าง | `empty($password)` check in login.php |
| Rate limit | `checkRateLimit('login_' . md5($email))` - 5 attempts/15 min |

#### Authorization
ไม่ต้อง (หน้า public) - แต่ถ้า login อยู่แล้วจะ redirect ไป index.php

#### DB Changes
- **Read:** `users` table (SELECT by email)
- **Write:** ไม่มี

#### Output

| Case | Response |
|------|----------|
| Success (admin/staff) | 302 redirect → `/admin/` |
| Success (member) | 302 redirect → `/index.php` |
| Failure | แสดง error message |

#### Common Failure Cases

| Case | Behavior |
|------|----------|
| Wrong email/password | "อีเมลหรือรหัสผ่านไม่ถูกต้อง" (ไม่บอกว่าอันไหนผิด) |
| Rate limit exceeded | "ลองผิดหลายครั้งเกินไป กรุณารอ 15 นาที" |
| Already logged in | redirect ไป index.php ทันที |

#### จุดระวังเวลาแก้

| จุด | ระวัง |
|-----|-------|
| Error message | ห้ามบอกว่า email หรือ password ผิด (user enumeration attack) |
| Rate limit key | ใช้ md5(email) เป็น key - เปลี่ยน key = reset counter |
| Session regenerate | `session_regenerate_id(true)` หลัง login สำเร็จ - ห้ามลบ |

---

### 3.2 Flow: User Registration

#### Goal
ให้ผู้ใช้ใหม่สร้าง account เป็น member

#### Entry Point
- **ไฟล์:** `register.php`
- **Method:** `POST`

#### Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `name` | string | Yes | ไม่ว่าง, ≤100 chars |
| `email` | string | Yes | valid email, unique |
| `password` | string | Yes | ≥6 chars |
| `confirm_password` | string | Yes | = password |
| `phone` | string | No | 9-10 digits |

#### Steps การทดสอบ

```
1. เปิดหน้า GET /register.php
2. กรอกข้อมูลครบทุก field
3. กด Submit
4. ⏳ System ตรวจ rate limit (global key "register")
5. ⏳ System validate ทุก field
6. ⏳ System เรียก AuthService::register()
7. ✅ ถ้าสำเร็จ → redirect ไป login.php พร้อม flash
```

**ทดสอบ Success:**
- กรอกข้อมูลถูกต้องทั้งหมด
- คาดหวัง: redirect ไป `/login.php` พร้อม "สมัครสมาชิกสำเร็จ"

**ทดสอบ Failure:**
- ใช้ email ที่มีอยู่แล้ว
- คาดหวัง: "อีเมลนี้ถูกใช้งานแล้ว"

#### Validation Rules

| Rule | Function/Location |
|------|-------------------|
| Name ไม่ว่าง | `empty($name)` in register.php |
| Name ≤100 chars | `validateMaxLength($name, 100, 'ชื่อ')` |
| Email valid | `isValidEmail($email)` |
| Email unique | `AuthService::register()` → `userRepo->emailExists()` |
| Password ≥6 | `validatePassword($password)` |
| Password match | `$password !== $confirmPassword` |
| Phone format | `isValidPhone($phone)` (9-10 digits) |

#### Authorization
ไม่ต้อง (หน้า public)

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `users` | INSERT | role='member' (hardcoded) |

#### Output

| Case | Response |
|------|----------|
| Success | 302 redirect → `/login.php` + flash "สมัครสมาชิกสำเร็จ" |
| Validation fail | แสดง errors + repopulate form |

#### Common Failure Cases

| Case | Behavior |
|------|----------|
| Email duplicate | "อีเมลนี้ถูกใช้งานแล้ว" |
| Password mismatch | "รหัสผ่านไม่ตรงกัน" |
| Rate limit | "ลองหลายครั้งเกินไป..." (global key ป้องกัน spam) |

#### จุดระวังเวลาแก้

| จุด | ระวัง |
|-----|-------|
| Role assignment | role='member' hardcoded ใน AuthService - ห้ามรับจาก user input |
| Password hash | ใช้ `password_hash()` - ห้าม hash เอง |
| Rate limit key | ใช้ global key "register" ไม่ใช่ per-email (เพราะ attacker ใช้ email ใหม่ได้) |

---

### 3.3 Flow: Create Borrow (ยืมหนังสือ)

#### Goal
Staff บันทึกการยืมหนังสือให้ member โดยลด stock และสร้าง borrow record

#### Entry Point
- **ไฟล์:** `admin/borrow_form.php`
- **Method:** `POST`

#### Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `csrf_token` | string | Yes | ต้องตรงกับ session |
| `user_id` | int | Yes | > 0, must be member role |
| `book_ids[]` | array | Yes | ไม่ว่าง, แต่ละ id > 0 |
| `borrow_days` | int | No | 1-30 (default: DEFAULT_BORROW_DAYS=7) |

#### Steps การทดสอบ

```
1. Login เป็น staff/admin
2. เปิดหน้า GET /admin/borrow_form.php
3. เลือก user (member)
4. เลือก book(s) - ได้สูงสุด 3 เล่ม
5. กด Submit
6. ⏳ System ตรวจ CSRF token
7. ⏳ System ตรวจ idempotency key
8. ⏳ System เรียก BorrowService::createBorrow()
   - BEGIN TRANSACTION
   - Lock user row
   - ตรวจ quota (current < 3)
   - Loop each book:
     - Lock book row
     - ตรวจ available > 0
     - decrementAvailable()
     - INSERT borrow
   - COMMIT
9. ✅ redirect ไป borrows.php พร้อม flash
```

**ทดสอบ Success:**
- เลือก member ที่ยังไม่ยืมอะไร + book ที่ available > 0
- คาดหวัง: redirect ไป `/admin/borrows.php` + "บันทึกการยืมสำเร็จ"

**ทดสอบ Failure:**
- เลือก member ที่ยืมครบ 3 เล่มแล้ว
- คาดหวัง: error "ผู้ยืมถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว"

#### Validation Rules

| Rule | Location |
|------|----------|
| CSRF token | `validateCSRFToken()` in borrow_form.php |
| User exists & is member | `userRepo->findMemberById()` |
| Books not empty | `empty($bookIds)` check |
| Borrow days 1-30 | `$borrowDays < 1 \|\| $borrowDays > 30` |
| Quota check | `borrowRepo->countActiveBorrowsForUpdate()` < MAX_BORROW_BOOKS |
| Stock available | `$book['available'] > 0` |

#### Authorization
- `requireStaff()` - ต้องเป็น staff หรือ admin

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `borrows` | INSERT | 1 row per book |
| `books` | UPDATE | `available = available - 1` per book |

**Transaction:** ใช่ - `beginTransaction()` / `commit()` / `rollback()`  
**Locking:** ใช่ - `SELECT ... FOR UPDATE` บน users และ books

#### Output

| Case | Response |
|------|----------|
| All success | redirect + "บันทึกการยืมสำเร็จ X เล่ม \| กำหนดคืน: dd/mm/yyyy" |
| Partial success | redirect + แจ้งว่าเล่มไหนยืมได้ เล่มไหน skip |
| All fail | redirect + error message |

#### Common Failure Cases

| Case | Behavior |
|------|----------|
| Double submit | Idempotency key ป้องกัน - redirect พร้อม "รายการนี้ถูกบันทึกไปแล้ว" |
| Concurrent borrow | FOR UPDATE lock ป้องกัน race condition |
| Stock 0 mid-transaction | Book ถูก skip พร้อมเหตุผล |
| Quota exceeded | Exception + rollback |

#### จุดระวังเวลาแก้

| จุด | ระวัง |
|-----|-------|
| MAX_BORROW_BOOKS | อยู่ใน config.php - แก้ที่เดียว |
| Idempotency key format | `borrow_{userId}_{md5(bookIds)}` - เปลี่ยน = ป้องกัน double submit พัง |
| decrementAvailable() | มี `WHERE available > 0` - ห้ามลบ condition นี้ |
| Transaction | ต้อง commit/rollback ครบทุก path |

---

### 3.4 Flow: Return Book (คืนหนังสือ)

#### Goal
Staff บันทึกการคืนหนังสือ คำนวณค่าปรับ และคืน stock

#### Entry Point
- **ไฟล์:** `admin/borrows.php`
- **Method:** `POST` (action=return)

#### Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `csrf_token` | string | Yes | ต้องตรง |
| `action` | string | Yes | = 'return' |
| `borrow_id` | int | Yes | > 0, status='borrowing' |
| `pay_now` | checkbox | No | ถ้ามี = รับชำระทันที |

#### Steps การทดสอบ

```
1. Login เป็น staff/admin
2. เปิดหน้า GET /admin/borrows.php
3. หา borrow ที่ status='borrowing'
4. กดปุ่ม "คืน" → modal ยืนยัน
5. (Optional) ติ๊ก "รับชำระเงินทันที" ถ้ามีค่าปรับ
6. กด Submit
7. ⏳ System ตรวจ CSRF + idempotency
8. ⏳ System เรียก BorrowService::returnBook()
   - BEGIN TRANSACTION
   - Lock borrow row
   - calculateFine(due_date, today)
   - UPDATE borrow (status='returned', fine_amount)
   - UPDATE book (available + 1)
   - INSERT payment (ถ้า pay_now)
   - COMMIT
9. ✅ redirect พร้อม flash
```

**ทดสอบ Success (ไม่มีค่าปรับ):**
- คืนหนังสือก่อนหรือตรง due_date
- คาดหวัง: "บันทึกการคืนหนังสือสำเร็จ"

**ทดสอบ Success (มีค่าปรับ + จ่ายทันที):**
- คืนหนังสือหลัง due_date + ติ๊ก pay_now
- คาดหวัง: "...ค่าปรับ: X บาท (เกิน Y วัน) [รับชำระเงินแล้ว]"

#### Validation Rules

| Rule | Location |
|------|----------|
| CSRF token | `validateCSRFToken()` |
| Borrow exists | `borrowRepo->findByIdForUpdate()` |
| Status = borrowing | check in findByIdForUpdate() |

#### Authorization
- `requireStaff()`

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `borrows` | UPDATE | status='returned', return_date, fine_amount |
| `books` | UPDATE | `available = available + 1` |
| `payments` | INSERT | ถ้า pay_now && fine > 0 |

**Fine Calculation:** `days_overdue × FINE_PER_DAY (default: 10 บาท)`

#### Output

| Case | Response |
|------|----------|
| No fine | "บันทึกการคืนหนังสือสำเร็จ" |
| Fine + paid | "...ค่าปรับ: X บาท [รับชำระเงินแล้ว]" |
| Fine + not paid | "...ค่าปรับ: X บาท [ยังไม่จ่าย]" |

#### Common Failure Cases

| Case | Behavior |
|------|----------|
| Already returned | "ไม่พบรายการยืมหรือคืนหนังสือแล้ว" |
| Double submit | Idempotency key ป้องกัน |

#### จุดระวังเวลาแก้

| จุด | ระวัง |
|-----|-------|
| FINE_PER_DAY | อยู่ใน config.php |
| สูตรค่าปรับ | อยู่ใน `BorrowService::calculateFine()` |
| incrementAvailable() | ต้องเรียก - ไม่งั้น stock ไม่คืน |

---

### 3.5 Flow: Create Reservation (จองหนังสือ)

#### Goal
Member จองหนังสือเพื่อมารับทีหลัง โดย stock ถูกกันไว้ทันที

#### Entry Point
- **ไฟล์:** `api/reserve_book.php`
- **Method:** `POST`
- **Response:** JSON

#### Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `csrf_token` | string | Yes | ต้องตรง |
| `book_id` | int | Yes | > 0 |

**Note:** `user_id` มาจาก `$_SESSION['user_id']` เท่านั้น

#### Steps การทดสอบ

```
1. Login เป็น member (หรือ role อื่น)
2. เปิดหน้า book detail
3. กดปุ่ม "จอง" (AJAX POST)
4. ⏳ System ตรวจ isLoggedIn()
5. ⏳ System ตรวจ method = POST
6. ⏳ System ตรวจ CSRF token
7. ⏳ System เรียก ReservationService::createReservation()
   - BEGIN TRANSACTION
   - ตรวจ ไม่มี pending reservation เล่มนี้
   - Lock book row
   - ตรวจ available > 0
   - INSERT reservation (status='pending', expires_at=+2 days)
   - UPDATE book (available - 1)
   - COMMIT
8. ✅ return JSON success
```

**ทดสอบด้วย curl:**
```bash
curl -X POST "http://localhost/book_borrowing/api/reserve_book.php" \
  -d "book_id=1&csrf_token=YOUR_TOKEN" \
  -b "PHPSESSID=YOUR_SESSION"
```

**คาดหวัง:**
```json
{"success": true, "message": "จองสำเร็จ! กรุณามารับหนังสือ..."}
```

#### Validation Rules

| Rule | Location |
|------|----------|
| Must be logged in | `isLoggedIn()` |
| CSRF token | `validateCSRFToken()` |
| book_id > 0 | `$bookId <= 0` check |
| No pending reservation | `reservationRepo->hasPending()` |
| Book available | `$book['available'] > 0` |

#### Authorization
- `isLoggedIn()` - ต้อง login (ไม่จำกัด role)

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `reservations` | INSERT | status='pending', expires_at |
| `books` | UPDATE | `available = available - 1` |

**Note:** Stock ถูกหักทันทีตอนจอง (กัน stock ไว้)

#### Output

| Case | HTTP | Response |
|------|------|----------|
| Success | 200 | `{"success": true, "message": "จองสำเร็จ!..."}` |
| Not logged in | 401 | `{"success": false, "message": "กรุณาเข้าสู่ระบบ..."}` |
| Already reserved | 400 | `{"success": false, "message": "คุณได้จองหนังสือเล่มนี้ไว้แล้ว..."}` |
| Out of stock | 400 | `{"success": false, "message": "หนังสือหมด ไม่สามารถจองได้"}` |

#### Common Failure Cases

| Case | Behavior |
|------|----------|
| Concurrent reserve last copy | FOR UPDATE lock - คนที่ 2 ได้ "หนังสือหมด" |
| Reserve same book twice | hasPending() check ป้องกัน |

#### จุดระวังเวลาแก้

| จุด | ระวัง |
|-----|-------|
| expires_at | default +2 days - อยู่ใน ReservationService |
| Stock หัก/คืน | ต้องจัดการใน cancel/expire ด้วย |
| user_id | มาจาก session เท่านั้น - ห้ามรับจาก POST |

---

### 3.6 Flow: Fulfill Reservation (อนุมัติการจอง)

#### Goal
Staff อนุมัติการจองและสร้าง borrow record อัตโนมัติ

#### Entry Point
- **ไฟล์:** `admin/reservations.php`
- **Method:** `POST` (action=approve)

#### Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `csrf_token` | string | Yes | ต้องตรง |
| `action` | string | Yes | = 'approve' |
| `id` | int | Yes | reservation_id > 0 |

#### Steps การทดสอบ

```
1. Login เป็น staff/admin
2. เปิดหน้า GET /admin/reservations.php
3. หา reservation ที่ status='pending'
4. กดปุ่ม "อนุมัติ"
5. ⏳ System ตรวจ CSRF + idempotency
6. ⏳ System เรียก ReservationService::fulfillReservation()
   - BEGIN TRANSACTION
   - Lock reservation row
   - ตรวจ status = 'pending'
   - ตรวจ user quota < MAX_BORROW_BOOKS
   - INSERT INTO borrows
   - UPDATE reservation (status='fulfilled', borrow_id)
   - COMMIT
7. ✅ redirect พร้อม flash
```

**ทดสอบ Success:**
- อนุมัติ reservation ที่ user ยังไม่ครบ quota
- คาดหวัง: "อนุมัติการจองสำเร็จ! สร้างรายการยืมแล้ว"

**ทดสอบ Failure:**
- อนุมัติ reservation ของ user ที่ยืมครบ 3 เล่มแล้ว
- คาดหวัง: "ผู้จองถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว"

#### Validation Rules

| Rule | Location |
|------|----------|
| CSRF token | `validateCSRFToken()` |
| Reservation pending | `findPendingForUpdate()` |
| User quota | `borrowRepo->countActiveBorrows()` < MAX_BORROW_BOOKS |

#### Authorization
- `requireStaff()`

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `borrows` | INSERT | สร้าง borrow record ใหม่ |
| `reservations` | UPDATE | status='fulfilled', borrow_id |

**Note:** ไม่ต้อง update books.available เพราะหักไปแล้วตอนจอง

#### จุดระวังเวลาแก้

| จุด | ระวัง |
|-----|-------|
| Quota check | ต้องตรวจก่อนสร้าง borrow |
| Stock | ไม่ต้องแก้ - หักไปแล้วตอนจอง |

---

### 3.7 Flow: Create Book (เพิ่มหนังสือ)

#### Goal
Staff เพิ่มหนังสือใหม่เข้าระบบ

#### Entry Point
- **ไฟล์:** `admin/book_form.php` (ไม่มี ?id parameter)
- **Method:** `POST`
- **Encoding:** `multipart/form-data` (มี file upload)

#### Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `csrf_token` | string | Yes | ต้องตรง |
| `title` | string | Yes | ไม่ว่าง, ≤200 chars |
| `author` | string | Yes | ไม่ว่าง, ≤100 chars |
| `isbn` | string | No | unique (ถ้ากรอก) |
| `category_id` | int | No | must exist in categories |
| `description` | string | No | - |
| `quantity` | int | Yes | ≥ 1 |
| `cover_image` | file | No | JPEG/PNG/GIF/WEBP, ≤2MB |

#### Steps การทดสอบ

```
1. Login เป็น staff/admin
2. เปิดหน้า GET /admin/book_form.php (ไม่มี ?id)
3. กรอกข้อมูลหนังสือ
4. (Optional) เลือกรูปปก
5. กด Submit
6. ⏳ System ตรวจ CSRF token
7. ⏳ System validate ทุก field
8. ⏳ ถ้ามี file upload:
   - ตรวจ MIME type จาก content (finfo)
   - ตรวจขนาด ≤ 2MB
   - สร้างชื่อไฟล์ใหม่ (cover_timestamp_uniqid.ext)
   - move_uploaded_file()
9. ⏳ System เรียก BookRepository::create()
10. ✅ redirect ไป books.php พร้อม flash
```

**ทดสอบ Success:**
- กรอก title, author, quantity
- คาดหวัง: redirect + "เพิ่มหนังสือสำเร็จ"

**ทดสอบ Failure:**
- กรอก ISBN ที่มีอยู่แล้ว
- คาดหวัง: "ISBN นี้มีในระบบแล้ว"

#### Validation Rules

| Rule | Location |
|------|----------|
| CSRF token | `validateCSRFToken()` |
| Title ไม่ว่าง | `empty($book['title'])` |
| Title ≤200 | `mb_strlen($book['title']) > 200` |
| Author ไม่ว่าง | `empty($book['author'])` |
| ISBN unique | `bookRepo->isbnExists()` |
| File type | `finfo_file()` check MIME |
| File size | `$file['size'] > 2MB` |

#### Authorization
- `requireStaff()`

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `books` | INSERT | available = quantity |

**File:** `uploads/covers/cover_*.ext` (ถ้า upload)

#### จุดระวังเวลาแก้

| จุด | ระวัง |
|-----|-------|
| File upload security | ใช้ finfo ตรวจ MIME จาก content - ห้ามเชื่อ $_FILES['type'] |
| Filename | สร้างชื่อใหม่ด้วย uniqid - ห้ามใช้ชื่อจาก user |
| available | ต้อง = quantity ตอนสร้าง |

---

### 3.8 Flow: Delete Book (ลบหนังสือ)

#### Goal
Staff ลบหนังสือออกจากระบบ

#### Entry Point
- **ไฟล์:** `admin/books.php`
- **Method:** `POST` (action=delete)

#### Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `csrf_token` | string | Yes | ต้องตรง |
| `action` | string | Yes | = 'delete' |
| `id` | int | Yes | book_id > 0 |

#### Steps การทดสอบ

```
1. Login เป็น staff/admin
2. เปิดหน้า GET /admin/books.php
3. หา book ที่ available = quantity (ไม่มีคนยืม)
4. กดปุ่ม "ลบ"
5. ⏳ System ตรวจ CSRF token
6. ⏳ System เรียก BookService::deleteBook()
   - BEGIN TRANSACTION
   - Lock book row (FOR UPDATE)
   - ตรวจ available = quantity
   - ตรวจ ไม่มี borrow history
   - DELETE FROM books
   - COMMIT
7. ⏳ ลบไฟล์ cover_image (ถ้ามี)
8. ✅ redirect พร้อม flash
```

**ทดสอบ Success:**
- ลบ book ที่ไม่เคยถูกยืม
- คาดหวัง: "ลบหนังสือสำเร็จ"

**ทดสอบ Failure:**
- ลบ book ที่มีคนยืมอยู่
- คาดหวัง: "ไม่สามารถลบได้ หนังสือเล่มนี้กำลังถูกยืมอยู่"

#### Validation Rules

| Rule | Location |
|------|----------|
| CSRF token | `validateCSRFToken()` |
| Book exists | `bookRepo->findByIdForUpdate()` |
| Not being borrowed | `available = quantity` |
| No borrow history | `borrowRepo->countByBook()` = 0 |

#### Authorization
- `requireStaff()`

#### DB Changes

| Table | Operation | หมายเหตุ |
|-------|-----------|----------|
| `books` | DELETE | 1 row |

**File:** ลบ `uploads/covers/cover_*.ext` (ถ้ามี)

#### จุดระวังเวลาแก้

| จุด | ระวัง |
|-----|-------|
| UI ซ่อนปุ่ม | ปุ่มลบ disabled ถ้า available ≠ quantity |
| Cover file | ต้องลบไฟล์ด้วย - ไม่งั้นค้างอยู่ใน uploads |

---

## 4. Single Source of Truth Map

### 4.1 ตำแหน่งที่ถูกต้องของแต่ละ Concern

| Concern | ตำแหน่งที่ถูกต้อง | ไฟล์ |
|---------|-----------------|------|
| **DB Connection** | `getDB()` | `includes/db.php` |
| **Config Values** | `env()` + Constants | `includes/config.php` |
| **Auth Check** | `isLoggedIn()`, `isStaff()`, `isAdmin()` | `includes/functions.php` |
| **Access Control** | `requireLogin()`, `requireStaff()`, `requireAdmin()` | `includes/functions.php` |
| **CSRF Token** | `generateCSRFToken()`, `validateCSRFToken()` | `includes/functions.php` |
| **Rate Limiting** | `checkRateLimit()`, `incrementRateLimit()` | `includes/functions.php` |
| **XSS Protection** | `e()` (htmlspecialchars wrapper) | `includes/functions.php` |
| **Password Rules** | `validatePassword()` | `includes/functions.php` |
| **Email Validation** | `isValidEmail()` | `includes/functions.php` |
| **Phone Validation** | `isValidPhone()` | `includes/functions.php` |
| **Name Validation** | `validateName()`, `validateMaxLength()` | `includes/functions.php` |
| **Borrow Rules** | `MAX_BORROW_BOOKS`, `DEFAULT_BORROW_DAYS`, `FINE_PER_DAY` | `includes/config.php` |
| **Fine Calculation** | `calculateFine()` | `app/Services/BorrowService.php` |
| **SQL Queries** | Repository methods | `app/Repositories/*.php` |

### 4.2 จุดที่พบ Validation ซ้ำ/ใกล้ซ้ำ

| จุดที่พบ | ตำแหน่ง | หมายเหตุ |
|---------|---------|---------|
| Password length | Entry Point + Service | Entry point ใช้ `validatePassword()` helper ก่อนส่งให้ Service - ถูกต้อง |
| Email format | Entry Point ใช้ `isValidEmail()` | Single source - ถูกต้อง |
| User exists check | ทั้ง Entry Point และ Service | Entry Point ตรวจคร่าวๆ, Service ตรวจอีกครั้งภายใน transaction - pattern ที่ถูกต้องสำหรับ concurrency |
| Book available check | ทั้ง UI (Select2) และ Service | UI กรองให้เลือก, Service ตรวจอีกครั้ง (จำเป็น) |

---

## 5. Debug Playbook

### 5.1 วิธีเปิด Debug Mode

**Step 1:** สร้างไฟล์ `.env` จาก `.env.example`
```bash
cp .env.example .env
```

**Step 2:** แก้ไข `.env`
```
APP_DEBUG=true
```

**ผลลัพธ์:** Error details จะแสดงบนหน้าเว็บแทน "ระบบขัดข้อง"

### 5.2 Log อยู่ที่ไหน

| ประเภท | ตำแหน่ง |
|--------|---------|
| PHP Errors | `logs/` folder หรือ Apache error log (`/var/log/apache2/error.log`) |
| DB Connection Error | หน้าเว็บ (ถ้า APP_DEBUG=true) |
| Custom Logs | ใช้ `error_log()` → Apache error log |

### 5.3 เวลาเจอ Error แต่ละประเภท

#### HTTP 400 Bad Request
```
1. ดู response body → อ่าน error message
2. ตรวจ input validation ใน Entry Point
3. ดู $errors array
4. ตรวจว่า required fields ส่งมาครบไหม
```

#### HTTP 401 Unauthorized
```
1. ตรวจว่า user login อยู่ไหม ($_SESSION['user_id'])
2. ตรวจ session หมดอายุไหม (SESSION_LIFETIME = 3600s)
3. ดูว่า isLoggedIn() ถูกเรียกที่ไหน
```

#### HTTP 403 Forbidden
```
1. ตรวจ CSRF token → อาจหมดอายุหรือไม่ตรง
2. ตรวจ user role → อาจไม่มีสิทธิ์
3. ดู requireStaff() หรือ requireAdmin()
```

#### HTTP 500 Internal Server Error
```
1. เปิด APP_DEBUG=true ดู error
2. ตรวจ PDO Exception (DB connection, SQL syntax)
3. ดู PHP error log
4. ตรวจ file permissions (uploads/, logs/)
5. ตรวจ transaction ว่า commit/rollback ครบไหม
```

### 5.4 ตัวอย่าง Debug ด้วย curl

#### 1. ทดสอบ Login
```bash
curl -X POST "http://localhost/book_borrowing/login.php" \
  -d "email=admin@library.com&password=123456" \
  -c cookies.txt -L -v
```

#### 2. ทดสอบ Search Books API
```bash
curl "http://localhost/book_borrowing/api/search_books.php?search=php&category=1"
```

#### 3. ทดสอบ Reserve Book (ต้อง login ก่อน)
```bash
# Step 1: Login
curl -X POST "http://localhost/book_borrowing/login.php" \
  -d "email=member@test.com&password=123456" \
  -c cookies.txt -L

# Step 2: Reserve
curl -X POST "http://localhost/book_borrowing/api/reserve_book.php" \
  -d "book_id=1&csrf_token=YOUR_TOKEN" \
  -b cookies.txt
```

### 5.5 Debug Checklist

```
□ APP_DEBUG=true ใน .env?
□ Apache error log checked?
□ Session started properly? (startSession() via bootstrap.php)
□ CSRF token matches session?
□ User has required role?
□ DB connection works? (test getDB())
□ Input validation passed?
□ Transaction commit/rollback complete?
□ File permissions correct? (uploads/, logs/)
```

---

## 6. Modification Guide (แก้ระบบแบบไม่พัง)

### 6.1 ถ้าจะแก้ Business Rule

| ต้องการแก้ | แก้ที่ไฟล์ | ตัวอย่าง |
|-----------|-----------|---------|
| จำนวนวันยืมเริ่มต้น | `.env` → `DEFAULT_BORROW_DAYS` | `DEFAULT_BORROW_DAYS=14` |
| ยืมสูงสุดกี่เล่ม | `.env` → `MAX_BORROW_BOOKS` | `MAX_BORROW_BOOKS=5` |
| ค่าปรับต่อวัน | `.env` → `FINE_PER_DAY` | `FINE_PER_DAY=20` |
| สูตรค่าปรับ | `app/Services/BorrowService.php` → `calculateFine()` | แก้ลอจิกคำนวณ |
| อายุการจอง | `app/Services/ReservationService.php` → param $expireDays | default 2 days |

### 6.2 ถ้าจะแก้ Validation

| ต้องการแก้ | แก้ที่ไฟล์ | Function |
|-----------|-----------|----------|
| Password length | `.env` → `MIN_PASSWORD_LENGTH` | ใช้โดย `validatePassword()` |
| Email format | `includes/functions.php` | `isValidEmail()` |
| Phone format | `includes/functions.php` | `isValidPhone()` |
| Name max length | `includes/functions.php` | `validateMaxLength()` |
| Custom validation | `includes/functions.php` | สร้าง function ใหม่ |

### 6.3 ถ้าจะแก้ SQL

| ต้องการแก้ | แก้ที่ไฟล์ |
|-----------|-----------|
| Query หนังสือ | `app/Repositories/BookRepository.php` |
| Query การยืม | `app/Repositories/BorrowRepository.php` |
| Query user | `app/Repositories/UserRepository.php` |
| Query การจอง | `app/Repositories/ReservationRepository.php` |
| Query รายงาน | `app/Repositories/ReportRepository.php` |
| Query payments | `app/Repositories/PaymentRepository.php` |

**กฎสำคัญ:** 
- ห้ามเขียน SQL ใน Entry Point หรือ Service
- ทุก SQL ต้องอยู่ใน Repository เท่านั้น
- ใช้ Prepared Statements (`?` placeholder) เสมอ

### 6.4 ถ้าจะแก้ Permission

| ต้องการแก้ | แก้ที่ไฟล์ | วิธี |
|-----------|-----------|------|
| เพิ่ม role ใหม่ | `database/schema.sql` | แก้ ENUM ของ role |
| | `includes/functions.php` | เพิ่ม `isNewRole()` |
| | `includes/functions.php` | เพิ่ม `requireNewRole()` |
| เปลี่ยน access level | Entry Point | เปลี่ยน `requireStaff()` ↔ `requireAdmin()` |

### 6.5 Checklist: เพิ่ม Field ใหม่ในตาราง

ตัวอย่าง: เพิ่ม field `publisher` ในตาราง `books`

```
□ 1. Database
   └── สร้าง migration: database/migrations/XXX_add_publisher_to_books.sql
       ALTER TABLE books ADD COLUMN publisher VARCHAR(100) DEFAULT NULL;
   └── Run migration

□ 2. Repository
   └── app/Repositories/BookRepository.php
       - แก้ findById() ให้ SELECT publisher ด้วย
       - แก้ create() ให้ INSERT publisher
       - แก้ update() ให้ UPDATE publisher

□ 3. Service (ถ้ามี validation)
   └── app/Services/BookService.php
       - รับ $data['publisher'] ใน createBook(), updateBook()
       - เพิ่ม validation ถ้าจำเป็น

□ 4. Entry Point (Form)
   └── admin/book_form.php
       - เพิ่ม <input name="publisher"> 
       - รับค่าจาก $_POST['publisher']
       - ส่งไปให้ Repository/Service

□ 5. Entry Point (Display)
   └── admin/books.php, book.php
       - แสดง <?= e($book['publisher']) ?>

□ 6. ทดสอบ
   □ Create: เพิ่มหนังสือใหม่พร้อม publisher
   □ Read: ดูรายการหนังสือ - เห็น publisher
   □ Update: แก้ไข publisher
   □ Delete: ลบหนังสือ (ไม่กระทบ field นี้)
```

### 6.6 Checklist: เพิ่ม API Endpoint ใหม่

```
□ 1. สร้างไฟล์ api/new_endpoint.php

□ 2. ใช้ Template นี้:
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

// 3. CSRF Check (ถ้าเป็น POST)
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

// 4. Validate Input
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
□ 3. ทดสอบด้วย curl
□ 4. ทดสอบ error cases ทุก path
```

### 6.7 สรุป: แก้ 1 จุดให้ครบ (Decision Matrix)

| ต้องการแก้ | Database | Repository | Service | Entry Point | Config |
|-----------|----------|------------|---------|-------------|--------|
| เพิ่ม field | ✅ ALTER | ✅ CRUD | ถ้ามี logic | ✅ Form/Display | - |
| เพิ่ม table | ✅ CREATE | ✅ ใหม่ | ✅ ใหม่ | ✅ ใหม่ | - |
| เปลี่ยน business rule | - | - | ✅ | - | ✅ ถ้าเป็น constant |
| เปลี่ยน validation | - | - | ถ้า complex | ✅ | ✅ ถ้าเป็น limit |
| เปลี่ยน permission | - | - | - | ✅ require* | - |
| เปลี่ยน UI | - | - | - | ✅ | - |

---

## Quick Reference Card

### Helper Functions ที่ใช้บ่อย

```php
// ===== Security =====
e($string)                    // Escape HTML (ป้องกัน XSS)
generateCSRFToken()           // สร้าง CSRF token
validateCSRFToken($token)     // ตรวจ CSRF token

// ===== Auth =====
isLoggedIn()                  // ตรวจว่า login อยู่ไหม
isAdmin()                     // ตรวจว่าเป็น admin ไหม
isStaff()                     // ตรวจว่าเป็น staff หรือ admin ไหม
requireLogin()                // บังคับ login (redirect ถ้าไม่)
requireStaff()                // บังคับเป็น staff+
requireAdmin()                // บังคับเป็น admin

// ===== Redirect & Flash =====
redirect($url)                // redirect + exit
setFlash($type, $message)     // ตั้ง flash message
getFlash()                    // ดึง flash message
displayFlash()                // แสดง flash message (HTML)

// ===== Validation =====
isValidEmail($email)          // ตรวจ email format
isValidPhone($phone)          // ตรวจ phone format (9-10 digits)
validatePassword($password)   // ตรวจ password (return error หรือ null)
validateMaxLength($val, $max) // ตรวจความยาว
validateName($name)           // ตรวจชื่อ

// ===== Rate Limiting =====
checkRateLimit($key)          // ตรวจว่าเกิน limit ไหม
incrementRateLimit($key)      // เพิ่ม counter
resetRateLimit($key)          // reset counter

// ===== Formatting =====
formatDate($date, $format)    // จัดรูปแบบวันที่ (default: d/m/Y)
formatFine($amount)           // จัดรูปแบบค่าปรับ
daysDiff($date1, $date2)      // คำนวณจำนวนวัน
```

### Config Constants

```php
// Database
DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHARSET

// Application
APP_NAME, APP_URL, APP_DEBUG

// Business Rules
DEFAULT_BORROW_DAYS   // วันยืมเริ่มต้น (default: 7)
MAX_BORROW_BOOKS      // ยืมสูงสุด (default: 3)
FINE_PER_DAY          // ค่าปรับ/วัน (default: 10)

// Security
MIN_PASSWORD_LENGTH        // รหัสผ่านขั้นต่ำ (default: 6)
RATE_LIMIT_MAX_ATTEMPTS    // จำนวนครั้งสูงสุด (default: 5)
RATE_LIMIT_WINDOW_MINUTES  // ช่วงเวลานับ (default: 15)
SESSION_LIFETIME           // อายุ session (default: 3600)
```

---

*เอกสารนี้สร้างจากโค้ดจริงในโปรเจกต์ทั้งหมด ไม่มีการเดาหรือแต่งเพิ่ม*
