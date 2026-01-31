# Study Guide - Book Borrowing System

เอกสารนี้สำหรับเจ้าของโปรเจกต์ที่ต้องการเข้าใจโค้ดเพื่ออ่านและแก้ไขต่อได้ด้วยตนเอง

**หมายเหตุ:** เนื้อหาทั้งหมดอ้างอิงจากโค้ดที่มีอยู่จริงในโปรเจกต์เท่านั้น

---

## สารบัญ

1. [แผนที่โปรเจกต์ (Project Map)](#1-แผนที่โปรเจกต์-project-map)
2. [Request → Response Flow](#2-request--response-flow)
3. [Core Flows](#3-core-flows)
4. [Single Source of Truth Map](#4-single-source-of-truth-map)
5. [Debug Playbook](#5-debug-playbook)
6. [Modification Guide](#6-modification-guide)

---

## 1. แผนที่โปรเจกต์ (Project Map)

### โครงสร้างโฟลเดอร์

```
book_borrowing/
├── admin/              # หน้า backend สำหรับ staff/admin
├── api/                # API endpoints (JSON/HTML responses)
├── app/                # Modern architecture layer (เตรียมไว้สำหรับ Phase 2)
│   ├── Config/         # Settings class
│   ├── Helpers/        # Namespaced helper functions
│   ├── Repositories/   # Data access layer
│   └── Services/       # Business logic layer
├── css/                # Stylesheet
├── database/           # SQL schema และ migrations
├── docs/               # เอกสารโปรเจกต์
├── includes/           # Core files ที่ใช้งานจริง (Legacy layer)
├── tests/              # Test files
└── uploads/            # ไฟล์ที่ upload (covers/)
```

### หน้าที่ของแต่ละโฟลเดอร์

| โฟลเดอร์ | หน้าที่ | ใช้งานจริง |
|---------|--------|-----------|
| `admin/` | หน้าจัดการระบบ: books, members, borrows, payments, reports | ✅ ใช้งาน |
| `api/` | API endpoints สำหรับ AJAX | ✅ ใช้งาน |
| `app/Config/` | Settings class สำหรับอ่าน `.env` | ⚠️ มีแต่ยังไม่ใช้ |
| `app/Helpers/` | Helper functions แบบ namespace | ⚠️ มีแต่ยังไม่ใช้ |
| `app/Repositories/` | Repository classes สำหรับ DB access | ⚠️ ใช้บางส่วน |
| `app/Services/` | Service classes สำหรับ business logic | ✅ ใช้บางส่วน |
| `includes/` | **Core files หลักที่ใช้งานจริง** | ✅ ใช้งานทั้งหมด |
| `database/` | Schema, migrations, sample data | ✅ ใช้ตอน install |
| `uploads/covers/` | รูปปกหนังสือ | ✅ ใช้งาน |

---

### ไฟล์ Entry Point สำคัญ 10 ไฟล์ที่ควรอ่านก่อน

อ่านตามลำดับนี้เพื่อเข้าใจระบบ:

#### ระดับ 1: Foundation (เข้าใจพื้นฐาน)

| # | ไฟล์ | เหตุผลที่ควรอ่าน |
|---|------|-----------------|
| 1 | `includes/config.php` | จุดเริ่มต้นของระบบ - โหลด `.env`, กำหนด constants ทั้งหมด (DB, APP_URL, FINE_PER_DAY) |
| 2 | `includes/db.php` | เข้าใจการเชื่อมต่อ DB - singleton pattern ผ่าน `getDB()` |
| 3 | `includes/functions.php` | **ไฟล์สำคัญที่สุด** - รวม helper functions 30+ ตัว (auth, csrf, validation, formatting) |

#### ระดับ 2: Authentication Flow

| # | ไฟล์ | เหตุผลที่ควรอ่าน |
|---|------|-----------------|
| 4 | `login.php` | เข้าใจ auth flow: validation → query → session → redirect |
| 5 | `register.php` | เข้าใจ user creation: validation → hash password → insert |

#### ระดับ 3: Public Pages

| # | ไฟล์ | เหตุผลที่ควรอ่าน |
|---|------|-----------------|
| 6 | `index.php` | หน้าแรก: การ query books, การใช้ filters, include header/footer |
| 7 | `api/reserve_book.php` | ตัวอย่าง API: JSON response, CSRF check, service layer usage |

#### ระดับ 4: Admin Pattern

| # | ไฟล์ | เหตุผลที่ควรอ่าน |
|---|------|-----------------|
| 8 | `admin/header.php` | Template pattern: permission check, navigation, common UI |
| 9 | `admin/books.php` | CRUD pattern: list + delete + filters ในไฟล์เดียว |
| 10 | `admin/borrow_form.php` | Complex form: multi-select, validation, transaction |

---

## 2. Request → Response Flow

### ภาพรวมการทำงาน

```
┌─────────────┐     ┌──────────────┐     ┌────────────────┐     ┌──────────────┐
│   Browser   │────>│   Endpoint   │────>│   Validation   │────>│   Database   │
│  (Request)  │     │  (PHP file)  │     │  & Auth Check  │     │    (MySQL)   │
└─────────────┘     └──────────────┘     └────────────────┘     └──────────────┘
       ^                   │                     │                      │
       │                   v                     v                      v
       │            ┌──────────────┐     ┌────────────────┐     ┌──────────────┐
       └────────────│   Response   │<────│    Service     │<────│  Repository  │
                    │ (HTML/JSON)  │     │ (Business Logic)│    │ (Data Access)│
                    └──────────────┘     └────────────────┘     └──────────────┘
```

### Sequence ของ Request ทั่วไป

```
1. Browser ส่ง Request
   ↓
2. PHP file โหลด dependencies
   └── require_once 'includes/functions.php'  ← โหลด config.php ด้วย
   └── require_once 'includes/db.php'
   ↓
3. Permission Check (ถ้าเป็น protected page)
   └── requireLogin() / requireAdmin() / requireStaff()
   ↓
4. CSRF Validation (ถ้าเป็น POST)
   └── validateCSRFToken($_POST['csrf_token'])
   ↓
5. Input Validation
   └── ตรวจสอบ fields ที่จำเป็น
   └── ใช้ isValidEmail(), isValidPhone() etc.
   ↓
6. Business Logic / Database Operation
   └── Direct query: $pdo->prepare() → execute()
   └── หรือ Service: $service->doSomething()
   ↓
7. Response
   └── HTML: include header, render, include footer
   └── JSON: header('Content-Type: application/json'), echo json_encode()
   └── Redirect: setFlash() → redirect()
```

### Boundary ของแต่ละ Layer

| Layer | หน้าที่ | ตำแหน่งในโปรเจกต์ |
|-------|--------|------------------|
| **Entry Point** | รับ request, โหลด dependencies, render response | `*.php` (root), `admin/*.php`, `api/*.php` |
| **Auth/CSRF** | ตรวจสอบสิทธิ์และ token | `includes/functions.php` (isLoggedIn, requireAdmin, validateCSRFToken) |
| **Validation** | ตรวจสอบ input | ทำใน entry point หรือ service |
| **Service** | Business logic, transaction | `app/Services/*.php` |
| **Repository** | Data access (CRUD) | `app/Repositories/*.php` (มีแต่ใช้น้อย) |
| **Database** | Query execution | `includes/db.php` → `getDB()` |
| **Helpers** | Utility functions | `includes/functions.php` |

### ตัวอย่าง Flow จริง: การจองหนังสือ

```php
// api/reserve_book.php

// 1. Load dependencies
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

// 2. Method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') → 405

// 3. Auth check
if (!isLoggedIn()) → 401

// 4. CSRF check
if (!validateCSRFToken($_POST['csrf_token'])) → 403

// 5. Input validation
$bookId = filter_input(INPUT_POST, 'book_id', FILTER_VALIDATE_INT);
if (!$bookId) → 400

// 6. Business logic (via Service)
$service = new ReservationService(getDB());
$result = $service->createReservation($userId, $bookId);

// 7. Response
echo json_encode(['success' => true, 'message' => $result['message']]);
```

---

## 3. Core Flows

### Flow 1: User Login

#### Goal
ตรวจสอบตัวตนผู้ใช้และสร้าง session สำหรับเข้าถึงระบบ

#### Entry Point
`login.php` (GET: แสดง form, POST: process login)

#### Inputs
| Field | Type | Required | Source |
|-------|------|----------|--------|
| email | string | Yes | `$_POST['email']` |
| password | string | Yes | `$_POST['password']` |

**Session ที่ใช้:** rate limit keys `login_attempts_{md5(email)}`, `login_time_{md5(email)}`

#### Validation Rules
```php
// includes/functions.php + login.php
- Email: ต้องไม่ว่าง
- Password: ต้องไม่ว่าง
- Rate limit: ≤ 5 attempts / 15 นาที (ต่อ email)
```

#### Authorization
ไม่มี - เป็น public page (แต่ถ้า login อยู่แล้วจะ redirect)

#### DB Changes
| Table | Operation | Condition |
|-------|-----------|-----------|
| `users` | SELECT | `WHERE email = ?` |
| (session) | UPDATE | Set user data on success |

#### Outputs
- **Success:** Redirect to `/admin/` (admin) หรือ `/index.php` (member)
- **Failure:** Re-render form with error messages

#### Common Failure Cases
| Case | Result |
|------|--------|
| Duplicate submit หลัง login | Redirect (isLoggedIn check) |
| Multi-tab login | Session ใหม่ override เก่า |
| Rate limit exceeded | Block 15 นาที |
| SQL injection | Prepared statement ป้องกัน |

#### จุดที่ควรระวังเวลาแก้
- ⚠️ `session_regenerate_id(true)` ต้องเรียกหลัง verify password (ป้องกัน session fixation)
- ⚠️ Rate limit keys ใช้ `md5(email)` - ถ้าเปลี่ยน format จะ reset counter ทั้งหมด
- ⚠️ Redirect logic ดูจาก `$_SESSION['role']` - ถ้าเพิ่ม role ใหม่ต้องเพิ่ม case

---

### Flow 2: User Registration

#### Goal
สร้าง account ใหม่สำหรับ member

#### Entry Point
`register.php` (GET: form, POST: process)

#### Inputs
| Field | Type | Required | Validation |
|-------|------|----------|------------|
| name | string | Yes | ≤ 100 chars |
| email | string | Yes | `isValidEmail()`, unique |
| phone | string | No | `isValidPhone()` (9-10 digits) |
| password | string | Yes | ≥ 6 chars |
| confirm_password | string | Yes | = password |

#### Validation Rules
```php
// register.php lines 42-77
- Name: required, max 100 chars
- Email: required, valid format, unique in DB
- Phone: optional, 9-10 digits if provided
- Password: min 6 chars, must match confirm
- Rate limit: 5 attempts / 15 min (global)
```

#### Authorization
ไม่มี - public page

#### DB Changes
| Table | Operation | Details |
|-------|-----------|---------|
| `users` | SELECT | Check email uniqueness |
| `users` | INSERT | name, email, password (bcrypt), phone, role='member' |

#### Outputs
- **Success:** Redirect to `/login.php` with flash message
- **Failure:** Re-render form with errors, retain field values

#### Common Failure Cases
| Case | Result |
|------|--------|
| Duplicate email submit | Second request fails (unique constraint) |
| Empty phone | Saved as NULL |
| Unicode in name | Supported (UTF-8) |

#### จุดที่ควรระวังเวลาแก้
- ⚠️ `password_hash()` ใช้ `PASSWORD_DEFAULT` - อย่าเปลี่ยนเป็น algorithm อื่นโดยไม่จำเป็น
- ⚠️ Default role คือ 'member' - ถ้าต้องการ role อื่นต้องแก้ INSERT query
- ⚠️ Phone validation เป็น Thai format (9-10 digits) - ถ้าต้องการ international ต้องแก้ regex

---

### Flow 3: Create Borrow (ยืมหนังสือ)

#### Goal
บันทึกการยืมหนังสือสำหรับ member

#### Entry Point
`admin/borrow_form.php` (POST)

#### Inputs
| Field | Type | Required | Validation |
|-------|------|----------|------------|
| csrf_token | string | Yes | `validateCSRFToken()` |
| user_id | int | Yes | Must be valid member |
| book_ids[] | array | Yes | ≤ `MAX_BORROW_BOOKS` |
| borrow_days | int | No | 1-30, default `DEFAULT_BORROW_DAYS` |

#### Validation Rules
```php
// admin/borrow_form.php
- User must exist and be member
- At least 1 book selected
- Each book: available > 0
- User not already borrowing same book
- User hasn't reached borrow limit
```

#### Authorization
```php
requireStaff();  // admin หรือ staff เท่านั้น
```

#### DB Changes (Transaction)
| Table | Operation | Details |
|-------|-----------|---------|
| `users` | SELECT FOR UPDATE | Lock user row |
| `books` | SELECT FOR UPDATE | Lock book rows |
| `borrows` | INSERT | user_id, book_id, borrow_date, due_date, status='borrowing' |
| `books` | UPDATE | `available = available - 1` |

**Transaction Pattern:**
```php
$pdo->beginTransaction();
try {
    // Lock rows, validate, insert, update
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    throw $e;
}
```

#### Outputs
- **Success:** Redirect to `/admin/borrows.php` with success flash
- **Failure:** Re-render form with error messages

#### Common Failure Cases
| Case | Result |
|------|--------|
| Concurrent borrow (same book) | Transaction + row lock ป้องกัน |
| Last copy race condition | First transaction wins |
| Duplicate submit | Second request sees "already borrowing" |

#### จุดที่ควรระวังเวลาแก้
- ⚠️ Transaction และ `FOR UPDATE` lock จำเป็นสำหรับ concurrency - อย่าลบออก
- ⚠️ Logic นี้ duplicate ระหว่าง `borrow_form.php` และ `BorrowService::createBorrow()` - ถ้าแก้ต้องแก้ทั้งสองที่
- ⚠️ `MAX_BORROW_BOOKS` กำหนดใน `includes/config.php` - ถ้าแก้ต้องแก้ที่เดียว

---

### Flow 4: Return Book (คืนหนังสือ + ค่าปรับ)

#### Goal
บันทึกการคืนหนังสือ คำนวณค่าปรับถ้าเกินกำหนด และรับชำระเงิน (optional)

#### Entry Point
`admin/borrows.php` (POST with action=return)

#### Inputs
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| csrf_token | string | Yes | CSRF token |
| action | string | Yes | = 'return' |
| borrow_id | int | Yes | Borrow record ID |
| pay_now | checkbox | No | ถ้า checked = รับค่าปรับทันที |

#### Validation Rules
```php
- Borrow must exist
- Borrow status = 'borrowing'
```

#### Authorization
```php
requireStaff();
```

#### DB Changes (Transaction via BorrowService)
| Table | Operation | Details |
|-------|-----------|---------|
| `borrows` | SELECT FOR UPDATE | Lock borrow row |
| `borrows` | UPDATE | status='returned', return_date, fine_amount |
| `books` | UPDATE | `available = available + 1` |
| `payments` | INSERT (optional) | ถ้า pay_now และมี fine |

**Fine Calculation:**
```php
// app/Services/BorrowService.php
$overdueDays = max(0, daysDiff($dueDate, $returnDate));
$fineAmount = $overdueDays * FINE_PER_DAY;
```

#### Outputs
- **No fine:** Flash "บันทึกการคืนเรียบร้อย"
- **With fine + paid:** Flash "บันทึกการคืนและรับค่าปรับ {amount} บาท"
- **With fine + not paid:** Warning flash "มีค่าปรับค้างชำระ {amount} บาท"

#### Common Failure Cases
| Case | Result |
|------|--------|
| Return already returned | Error "รายการนี้คืนแล้ว" |
| Concurrent return | Transaction ป้องกัน |

#### จุดที่ควรระวังเวลาแก้
- ⚠️ `FINE_PER_DAY` กำหนดใน `includes/config.php` - เปลี่ยนตรงนั้นที่เดียว
- ⚠️ Fine calculation อยู่ใน `BorrowService` - ถ้าต้องการ cap สูงสุดต้องเพิ่มที่นั่น
- ⚠️ Payment record links to borrow_id - ถ้า delete borrow จะ cascade delete payment

---

### Flow 5: Reserve Book (API)

#### Goal
ให้ member จองหนังสือผ่าน API

#### Entry Point
`api/reserve_book.php` (POST)

#### Inputs
| Field | Type | Required | Source |
|-------|------|----------|--------|
| book_id | int | Yes | `$_POST['book_id']` |
| csrf_token | string | Yes | `$_POST['csrf_token']` |

**Headers:** Content-Type: application/x-www-form-urlencoded

#### Validation Rules
```php
// api/reserve_book.php + ReservationService
- User must be logged in
- CSRF token valid
- book_id is positive integer
- Book exists
- Book available > 0
- User doesn't have pending reservation for same book
```

#### Authorization
```php
isLoggedIn()  // ต้อง login เป็น member
```

#### DB Changes (Transaction via ReservationService)
| Table | Operation | Details |
|-------|-----------|---------|
| `reservations` | SELECT | Check existing pending |
| `books` | SELECT FOR UPDATE | Lock book row |
| `reservations` | INSERT | user_id, book_id, status='pending', expires_at |
| `books` | UPDATE | `available = available - 1` |

#### Outputs
```json
// Success (200)
{"success": true, "message": "จองสำเร็จ! กรุณามารับหนังสือ..."}

// Failure (400/401/403/405)
{"success": false, "message": "error message"}
```

| HTTP Status | Meaning |
|-------------|---------|
| 200 | Success |
| 400 | Invalid input / business error |
| 401 | Not logged in |
| 403 | Invalid CSRF |
| 405 | Method not POST |

#### Common Failure Cases
| Case | Result |
|------|--------|
| Duplicate reservation | Error "คุณได้จองหนังสือเล่มนี้แล้ว" |
| Race condition (last copy) | Row lock - first wins |
| Session timeout | 401 error |

#### จุดที่ควรระวังเวลาแก้
- ⚠️ `expires_at` คำนวณจาก `RESERVATION_DAYS` (default 2 วัน) - แก้ใน config หรือ service
- ⚠️ API นี้ไม่มี rate limiting - ถ้าต้องการต้องเพิ่มเอง
- ⚠️ Row locking สำคัญ - อย่าลบ `FOR UPDATE`

---

### Flow 6: Search Books (AJAX API)

#### Goal
ค้นหาหนังสือแบบ real-time โดยไม่ reload หน้า

#### Entry Point
`api/search_books.php` (GET)

#### Inputs
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| search | string | No | คำค้นหา (title, author, ISBN) |
| category | int | No | Category ID filter |
| status | string | No | 'available' = มีพร้อมยืม |

**Source:** Query string (`$_GET`)

#### Validation Rules
```php
// ไม่มี validation เข้มงวด - public API
- Parameters sanitized via prepared statements
```

#### Authorization
ไม่มี - public API

#### DB Changes
ไม่มี - read only

#### Outputs
- **Content-Type:** `text/html; charset=utf-8`
- **Response:** HTML partial (book grid) หรือ empty state message

#### Common Failure Cases
| Case | Result |
|------|--------|
| Invalid category ID | Empty results (ไม่ error) |
| SQL injection | Prepared statement ป้องกัน |

#### จุดที่ควรระวังเวลาแก้
- ⚠️ Response เป็น HTML partial (ไม่ใช่ JSON) - frontend คาดหวัง HTML
- ⚠️ ใช้ `includes/book_grid.php` สำหรับ render - ถ้าแก้ layout ต้องแก้ที่นั่น
- ⚠️ LIKE query ใช้ `%search%` - ถ้า search string ยาวมากอาจช้า

---

### Flow 7: Delete Book

#### Goal
ลบหนังสือออกจากระบบ

#### Entry Point
`admin/books.php` (POST with action=delete)

#### Inputs
| Field | Type | Required |
|-------|------|----------|
| csrf_token | string | Yes |
| action | string | Yes | = 'delete' |
| id | int | Yes | Book ID |

#### Validation Rules
```php
// admin/books.php
- Book must exist
- No active borrows (status='borrowing')
- All copies available (available == quantity)
```

#### Authorization
```php
requireStaff();
```

#### DB Changes (Transaction)
| Table | Operation | Details |
|-------|-----------|---------|
| `books` | SELECT FOR UPDATE | Lock row |
| `borrows` | SELECT | Check active borrows |
| `books` | DELETE | Remove book |

**File System:**
- Delete cover image from `uploads/covers/` if exists

#### Outputs
- **Success:** Redirect with flash "ลบหนังสือสำเร็จ"
- **Failure:** Redirect with error message

#### Common Failure Cases
| Case | Result |
|------|--------|
| Book has active borrow | Error "ไม่สามารถลบได้ มีการยืมอยู่" |
| Concurrent delete | First wins, second gets "ไม่พบหนังสือ" |

#### จุดที่ควรระวังเวลาแก้
- ⚠️ Borrow history ยังอยู่หลังลบ book (FK cascade) - อาจมี orphan records
- ⚠️ Cover file ถูกลบด้วย - ไม่สามารถ restore ได้
- ⚠️ ไม่มี soft delete - ถ้าต้องการต้องเพิ่ม `deleted_at` column

---

### Flow 8: Approve/Cancel Reservation

#### Goal
Admin อนุมัติหรือยกเลิกการจองหนังสือ

#### Entry Point
`admin/reservations.php` (POST)

#### Inputs (Approve)
| Field | Type | Required |
|-------|------|----------|
| csrf_token | string | Yes |
| action | string | Yes | = 'approve' |
| id | int | Yes | Reservation ID |

#### Inputs (Cancel)
| Field | Type | Required |
|-------|------|----------|
| csrf_token | string | Yes |
| action | string | Yes | = 'cancel' |
| id | int | Yes | Reservation ID |

#### Validation Rules
```php
- Reservation must exist
- Reservation status = 'pending'
```

#### Authorization
```php
requireAdmin();  // เฉพาะ admin เท่านั้น (staff ไม่ได้)
```

#### DB Changes

**Approve (Transaction):**
| Table | Operation | Details |
|-------|-----------|---------|
| `borrows` | INSERT | เริ่มการยืมทันที |
| `reservations` | UPDATE | status='fulfilled' |

**Cancel (Transaction):**
| Table | Operation | Details |
|-------|-----------|---------|
| `books` | UPDATE | `available = available + 1` |
| `reservations` | UPDATE | status='cancelled' |

#### Outputs
- **Approve success:** Flash "อนุมัติการจองสำเร็จ"
- **Cancel success:** Flash "ยกเลิกการจองเรียบร้อย"
- **Failure:** Flash error

#### Common Failure Cases
| Case | Result |
|------|--------|
| Already processed | Error "รายการนี้ถูกดำเนินการแล้ว" |
| Concurrent approve/cancel | Transaction ป้องกัน |

#### จุดที่ควรระวังเวลาแก้
- ⚠️ Approve สร้าง borrow ทันที - due_date คำนวณจากวันที่ approve
- ⚠️ Cancel คืน stock (`available + 1`) - ถ้าลืมจะ stock ผิด
- ⚠️ เฉพาะ admin - staff ไม่มีสิทธิ์ (ต่างจาก borrow)

---

## 4. Single Source of Truth Map

### ตารางแหล่งที่มาของแต่ละ Feature

| Feature | ไฟล์หลัก | หมายเหตุ |
|---------|---------|---------|
| **Configuration** | `includes/config.php` | Constants ทั้งหมด, อ่าน `.env` |
| **Database Connection** | `includes/db.php` | `getDB()` singleton |
| **Auth Functions** | `includes/functions.php` | `isLoggedIn()`, `isAdmin()`, `isStaff()`, `requireLogin()`, `requireAdmin()`, `requireStaff()` |
| **CSRF** | `includes/functions.php` | `generateCSRFToken()`, `validateCSRFToken()` |
| **Validation** | `includes/functions.php` | `isValidEmail()`, `isValidPhone()` |
| **Rate Limiting** | `login.php`, `register.php` | Session-based, inline code |
| **Flash Messages** | `includes/functions.php` | `setFlash()`, `getFlash()`, `displayFlash()` |
| **Formatting** | `includes/functions.php` | `formatDate()`, `formatFine()`, status labels |
| **Borrow Logic** | `app/Services/BorrowService.php` | Transaction, fine calculation |
| **Reservation Logic** | `app/Services/ReservationService.php` | Transaction, expiry |

### จุดที่ซ้ำ/ใกล้ซ้ำ (Duplication Points)

| สิ่งที่ซ้ำ | ตำแหน่งที่ 1 | ตำแหน่งที่ 2 | ผลกระทบ |
|-----------|-------------|-------------|---------|
| Helper functions | `includes/functions.php` | `app/Helpers/functions.php` | ยังใช้แค่ includes/ |
| Borrow creation logic | `admin/borrow_form.php` | `app/Services/BorrowService.php` | แก้ต้องแก้ 2 ที่ |
| Settings loading | `includes/config.php` | `app/Config/settings.php` | ยังใช้แค่ includes/ |
| Date formatting | `includes/functions.php` | `app/Helpers/functions.php` | Format ต่างกันเล็กน้อย |
| Status label functions | `includes/functions.php` | `app/Helpers/functions.php` | HTML ต่างกัน |

**คำแนะนำ:** ตอนนี้ใช้ `includes/` เป็นหลัก - `app/` เตรียมไว้สำหรับ migration ในอนาคต

---

## 5. Debug Playbook

### เมื่อเจอ Error ให้ไล่ดูตามลำดับ

#### Error 400 (Bad Request)

```
1. ตรวจ input validation
   └── ดู error message ใน response
   └── ตรวจว่าส่ง parameters ครบหรือไม่
   └── ตรวจ format (email, phone, int)

2. ตรวจ business logic
   └── เช่น book available = 0
   └── user มี pending reservation อยู่แล้ว
   └── duplicate action
```

#### Error 401 (Unauthorized)

```
1. ตรวจ session
   └── Session หมดอายุหรือไม่ (SESSION_LIFETIME = 3600)
   └── Cookie ถูกลบหรือไม่
   └── isLoggedIn() return false

2. ตรวจ $_SESSION
   └── var_dump($_SESSION) ดูว่ามี user_id หรือไม่
   └── session_status() ดูว่า session active หรือไม่
```

#### Error 403 (Forbidden)

```
1. ตรวจ CSRF token
   └── Form มี hidden field csrf_token หรือไม่
   └── Token ตรงกับใน session หรือไม่
   └── generateCSRFToken() ถูกเรียกก่อน form หรือไม่

2. ตรวจ permission
   └── User role คืออะไร ($_SESSION['role'])
   └── Page ต้องการ admin หรือ staff
   └── isAdmin() / isStaff() return อะไร
```

#### Error 500 (Server Error)

```
1. เปิด error display
   └── แก้ .env: APP_DEBUG=true
   └── หรือ php.ini: display_errors = On

2. ดู error log
   └── XAMPP: C:\xampp\php\logs\php_error_log
   └── หรือ Apache: C:\xampp\apache\logs\error.log

3. Common causes:
   └── Database connection failed
   └── SQL syntax error
   └── Missing required file
   └── PHP fatal error
```

### Log และ Debug Mode

**เปิด Debug Mode:**
```ini
# .env
APP_DEBUG=true
```

**ดู Error Log (XAMPP Windows):**
```
C:\xampp\php\logs\php_error_log
C:\xampp\apache\logs\error.log
```

**Debug ด้วย var_dump:**
```php
// เพิ่มชั่วคราวในโค้ด
var_dump($_POST);
var_dump($_SESSION);
exit;
```

### ตัวอย่าง cURL Commands

#### 1. ทดสอบ Login

```bash
# ส่ง login request
curl -X POST http://localhost/book_borrowing/login.php \
  -d "email=admin@example.com&password=password123" \
  -c cookies.txt \
  -v
```

#### 2. ทดสอบ Search Books API

```bash
# ค้นหาหนังสือ
curl "http://localhost/book_borrowing/api/search_books.php?search=php&category=1&status=available"

# ค้นหาทั้งหมด
curl "http://localhost/book_borrowing/api/search_books.php"
```

#### 3. ทดสอบ Reserve Book API (ต้อง login ก่อน)

```bash
# Step 1: Login และเก็บ cookie
curl -X POST http://localhost/book_borrowing/login.php \
  -d "email=member@example.com&password=password123" \
  -c cookies.txt

# Step 2: ดึง CSRF token (จาก session หรือ หน้า book.php)
# Token อยู่ใน hidden field ของ form

# Step 3: ส่ง reservation
curl -X POST http://localhost/book_borrowing/api/reserve_book.php \
  -d "book_id=1&csrf_token=YOUR_TOKEN_HERE" \
  -b cookies.txt
```

---

## 6. Modification Guide (แก้ระบบแบบไม่พัง)

### ถ้าจะแก้ Business Rule

**ตัวอย่าง:** เปลี่ยนค่าปรับจาก 10 บาท/วัน เป็น 20 บาท/วัน

| ขั้นตอน | ไฟล์ | การแก้ไข |
|--------|------|---------|
| 1 | `includes/config.php` | เปลี่ยน `FINE_PER_DAY` จาก 10 เป็น 20 |
| 2 | `.env` (ถ้ามี) | เพิ่ม `FINE_PER_DAY=20` |

**จุดที่ใช้ค่านี้:**
- `app/Services/BorrowService.php` - คำนวณค่าปรับ
- `admin/borrows.php` - แสดงค่าปรับ

---

### ถ้าจะแก้ Validation

**ตัวอย่าง:** เปลี่ยน password ขั้นต่ำจาก 6 เป็น 8 ตัวอักษร

| ขั้นตอน | ไฟล์ | การแก้ไข |
|--------|------|---------|
| 1 | `register.php` | แก้ validation `strlen($password) < 8` |
| 2 | `reset_password.php` | แก้ validation เหมือนกัน |
| 3 | `profile.php` | แก้ validation ใน change password |

**หมายเหตุ:** ไม่มี central validation สำหรับ password - ต้องแก้ทุกที่ที่ใช้

---

### ถ้าจะแก้ SQL / Database

**ตัวอย่าง:** เพิ่ม column `description` ใน `categories`

| ขั้นตอน | ไฟล์/ตำแหน่ง | การแก้ไข |
|--------|-------------|---------|
| 1 | `database/schema.sql` | เพิ่ม `description TEXT` ใน CREATE TABLE |
| 2 | MySQL | `ALTER TABLE categories ADD description TEXT;` |
| 3 | `admin/categories.php` | เพิ่ม field ใน form และ INSERT/UPDATE |

---

### ถ้าจะแก้ Permission

**ตัวอย่าง:** ให้ staff จัดการ reservations ได้ (ปัจจุบันเฉพาะ admin)

| ขั้นตอน | ไฟล์ | การแก้ไข |
|--------|------|---------|
| 1 | `admin/reservations.php` | เปลี่ยน `requireAdmin()` เป็น `requireStaff()` |

**หมายเหตุ:** ถ้าเพิ่ม role ใหม่ต้องแก้:
- `includes/functions.php` - เพิ่ม function check role
- `login.php` - เพิ่ม redirect logic
- ทุก page ที่ต้องการ permission ใหม่

---

### ตัวอย่าง: เพิ่ม Field ใหม่ (Checklist แบบสมบูรณ์)

**Scenario:** เพิ่ม field `publisher` ในหนังสือ

#### Checklist

- [ ] **1. Database**
  - [ ] แก้ `database/schema.sql` - เพิ่ม column
  - [ ] Run migration: `ALTER TABLE books ADD publisher VARCHAR(100);`

- [ ] **2. Create/Update Form**
  - [ ] `admin/book_form.php` - เพิ่ม input field
  - [ ] เพิ่มใน INSERT query
  - [ ] เพิ่มใน UPDATE query
  - [ ] Validate input (ถ้าจำเป็น)

- [ ] **3. List/Display**
  - [ ] `admin/books.php` - เพิ่มใน SELECT query
  - [ ] เพิ่มใน table column (ถ้าต้องการแสดง)
  - [ ] `book.php` - เพิ่มในหน้า detail

- [ ] **4. Search (ถ้าต้องการ)**
  - [ ] `api/search_books.php` - เพิ่มใน WHERE LIKE
  - [ ] `app/Repositories/BookRepository.php` - เพิ่มใน filters

- [ ] **5. Import (ถ้าต้องการ)**
  - [ ] `admin/import_books.php` - เพิ่ม column ใน CSV parser
  - [ ] `docs/samples/books_sample.csv` - update sample

- [ ] **6. Test**
  - [ ] Create book ใหม่
  - [ ] Update book เดิม
  - [ ] ดูหน้า list และ detail
  - [ ] ค้นหา (ถ้าเพิ่มใน search)

---

### Quick Reference: ไฟล์ที่ต้องแก้ตาม Feature

| ต้องการแก้ | ไฟล์ที่เกี่ยวข้อง |
|-----------|-----------------|
| **User fields** | `register.php`, `profile.php`, `admin/member_form.php`, `admin/ajax_add_member.php` |
| **Book fields** | `admin/book_form.php`, `admin/books.php`, `book.php`, `api/search_books.php` |
| **Borrow rules** | `includes/config.php`, `admin/borrow_form.php`, `app/Services/BorrowService.php` |
| **Fine rules** | `includes/config.php`, `app/Services/BorrowService.php` |
| **Reservation rules** | `app/Services/ReservationService.php`, `admin/reservations.php` |
| **Auth/Session** | `includes/functions.php`, `login.php`, `logout.php` |
| **CSRF** | `includes/functions.php` (ไม่ควรแก้) |
| **UI/Layout** | `includes/header.php`, `includes/footer.php`, `admin/header.php`, `css/style.css` |

---

## Quick Reference Card

### Constants สำคัญ (`includes/config.php`)

| Constant | Default | ใช้ที่ |
|----------|---------|-------|
| `APP_URL` | from .env | Redirects, links |
| `DEFAULT_BORROW_DAYS` | 7 | Borrow form |
| `MAX_BORROW_BOOKS` | 5 | Borrow validation |
| `FINE_PER_DAY` | 10 | Fine calculation |
| `SESSION_LIFETIME` | 3600 | Session config |
| `APP_DEBUG` | false | Error display |

### Functions ที่ใช้บ่อย (`includes/functions.php`)

| Function | หน้าที่ |
|----------|--------|
| `e($str)` | HTML escape (XSS protection) |
| `isLoggedIn()` | Check login status |
| `isAdmin()` | Check admin role |
| `isStaff()` | Check admin or staff |
| `requireLogin()` | Force login or redirect |
| `requireAdmin()` | Force admin or redirect |
| `requireStaff()` | Force staff or redirect |
| `generateCSRFToken()` | Create CSRF token |
| `validateCSRFToken($token)` | Verify CSRF token |
| `setFlash($type, $msg)` | Set flash message |
| `redirect($url)` | Redirect and exit |
| `getDB()` | Get PDO instance |
| `formatDate($date)` | Format date Thai style |
| `formatFine($amount)` | Format currency |

### HTTP Status Codes ที่ใช้

| Code | Meaning | เมื่อไหร่ |
|------|---------|---------|
| 200 | OK | Success |
| 302 | Redirect | After POST success |
| 400 | Bad Request | Validation failed |
| 401 | Unauthorized | Not logged in |
| 403 | Forbidden | Invalid CSRF / No permission |
| 405 | Method Not Allowed | Wrong HTTP method |
| 500 | Server Error | PHP error / DB error |

---

## Revision History

| Date | Version | Author | Changes |
|------|---------|--------|---------|
| 2026-01-31 | 1.0 | Dev Team | Initial document |
