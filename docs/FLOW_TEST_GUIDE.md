# Flow Test Guide - Book Borrowing System

เอกสารนี้รวบรวม flows หลักของระบบยืม-คืนหนังสือ สำหรับใช้ทดสอบระบบแบบ manual และเป็นฐานสำหรับเขียน automated tests

**หมายเหตุ:** เอกสารนี้อ้างอิงจากโค้ดที่มีอยู่จริงเท่านั้น

---

## สารบัญ

1. [User Login](#1-user-login)
2. [User Registration](#2-user-registration)
3. [Forgot & Reset Password](#3-forgot--reset-password)
4. [Search Books (AJAX API)](#4-search-books-ajax-api)
5. [Reserve Book (API)](#5-reserve-book-api)
6. [Create Borrow](#6-create-borrow)
7. [Return Book with Fine Calculation](#7-return-book-with-fine-calculation)
8. [Create/Update Book](#8-createupdate-book)
9. [Delete Book](#9-delete-book)
10. [AJAX Add Member](#10-ajax-add-member)
11. [Import Books from CSV](#11-import-books-from-csv)
12. [Approve/Cancel Reservation](#12-approvecancel-reservation)

---

## 1. User Login

### Goal
ให้ผู้ใช้สามารถเข้าสู่ระบบด้วย email และ password เพื่อเข้าถึงฟีเจอร์ต่างๆ ตามสิทธิ์ของตน

### Preconditions
- **Login state:** ไม่ได้ login (ถ้า login อยู่แล้วจะถูก redirect)
- **Database state:** 
  - มี user ใน `users` table ที่มี email และ password (bcrypt hash)
  - `password_resets` table ต้องมีอยู่ (ระบบใช้ตรวจสอบ)

### Trigger
- **Endpoint:** `/login.php`
- **Method:** `POST`

### Inputs
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| email | string | Yes | อีเมลผู้ใช้ |
| password | string | Yes | รหัสผ่าน (plain text) |

- **Session:** ต้องมี session เริ่มต้น (session_start)
- **CSRF:** ไม่มี (ใช้ rate limiting แทน)

### Steps
1. ระบบตรวจสอบว่า user login อยู่หรือไม่ (ถ้า login แล้ว → redirect)
2. ตรวจสอบ rate limiting (ใช้ session-based)
   - Key: `login_attempts_{md5(email)}`, `login_time_{md5(email)}`
   - Limit: 5 attempts / 15 minutes
3. Validate ว่า email และ password ไม่ว่าง
4. Query หา user: `SELECT id, name, email, password, role FROM users WHERE email = ?`
5. ตรวจสอบ password ด้วย `password_verify()`
6. ถ้าถูกต้อง:
   - `session_regenerate_id(true)`
   - Set session: `user_id`, `user_name`, `user_email`, `role`
   - Reset attempt counter
7. Redirect ตาม role

### Expected Results

**Success:**
- **HTTP Status:** 302 Redirect
- **Redirect to:** 
  - Admin: `/admin/`
  - Member: `/index.php`
- **Session changes:**
  - `$_SESSION['user_id']` = user ID
  - `$_SESSION['user_name']` = user name
  - `$_SESSION['user_email']` = user email
  - `$_SESSION['role']` = 'admin' | 'member'
- **Flash message:** "เข้าสู่ระบบสำเร็จ ยินดีต้อนรับ {name}"

**Failure:**
- **HTTP Status:** 200 (re-render form)
- **Error messages:**
  - "อีเมลหรือรหัสผ่านไม่ถูกต้อง"
  - "ลองผิดหลายครั้งเกินไป กรุณารอ 15 นาที" (ถ้า attempts >= 5)

### Failure Paths
| Scenario | Expected Behavior |
|----------|------------------|
| Empty email/password | แสดง error "อีเมลหรือรหัสผ่านไม่ถูกต้อง" |
| Email ไม่มีในระบบ | แสดง error "อีเมลหรือรหัสผ่านไม่ถูกต้อง" |
| Password ไม่ถูกต้อง | แสดง error, เพิ่ม attempt counter |
| Rate limit exceeded | แสดง error rate limit, block 15 นาที |

### Edge Cases
| Scenario | Expected Behavior |
|----------|------------------|
| **Duplicate submit** | ถ้า login สำเร็จแล้ว redirect ไปหน้าหลัก |
| **Multi-tab login** | Session ใหม่จะ override session เก่า (session_regenerate_id) |
| **Concurrent users** | แต่ละ user มี session แยกกัน |
| **Retry after rate limit** | หลัง 15 นาที counter จะ reset ได้ |
| **SQL injection** | ใช้ prepared statements, ปลอดภัย |

---

## 2. User Registration

### Goal
ให้ผู้ใช้ใหม่สามารถสมัครสมาชิกเพื่อใช้งานระบบในฐานะ member

### Preconditions
- **Login state:** ไม่ได้ login
- **Database state:** 
  - `users` table ต้องมีอยู่
  - Email ที่จะสมัครต้องยังไม่มีในระบบ

### Trigger
- **Endpoint:** `/register.php`
- **Method:** `POST`

### Inputs
| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| name | string | Yes | ไม่เกิน 100 ตัวอักษร |
| email | string | Yes | Valid email format, unique |
| phone | string | No | 9-10 หลัก (ถ้ากรอก) |
| password | string | Yes | อย่างน้อย 6 ตัวอักษร |
| confirm_password | string | Yes | ต้องตรงกับ password |

- **CSRF:** ไม่มี (ใช้ rate limiting)

### Steps
1. ตรวจสอบว่า login อยู่หรือไม่ → redirect ถ้า login
2. ตรวจสอบ rate limiting: 5 attempts / 15 minutes
3. Validate fields ตามเงื่อนไข
4. Check email uniqueness: `SELECT id FROM users WHERE email = ?`
5. Hash password: `password_hash($password, PASSWORD_DEFAULT)`
6. Insert user: `INSERT INTO users (name, email, password, phone, role) VALUES (?, ?, ?, ?, 'member')`
7. Redirect to login page

### Expected Results

**Success:**
- **HTTP Status:** 302 Redirect to `/login.php`
- **Database changes:**
  - New row in `users`:
    - `role` = 'member'
    - `password` = bcrypt hash
    - `phone` = value หรือ NULL
- **Flash message:** "สมัครสมาชิกสำเร็จ กรุณาเข้าสู่ระบบ"

**Failure:**
- **HTTP Status:** 200 (re-render form with errors)
- **Form values retained:** name, email, phone (ไม่รวม password)

### Failure Paths
| Scenario | Error Message |
|----------|---------------|
| ไม่กรอกชื่อ | "กรุณากรอกชื่อ-นามสกุล" |
| ชื่อเกิน 100 ตัวอักษร | "ชื่อต้องไม่เกิน 100 ตัวอักษร" |
| Email format ผิด | "รูปแบบอีเมลไม่ถูกต้อง" |
| เบอร์โทรไม่ใช่ 9-10 หลัก | "เบอร์โทรต้องเป็นตัวเลข 9-10 หลัก" |
| Password น้อยกว่า 6 ตัว | "รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร" |
| Password ไม่ตรงกัน | "รหัสผ่านไม่ตรงกัน" |
| Email ซ้ำ | "อีเมลนี้ถูกใช้งานแล้ว" |
| Rate limit | "ลองหลายครั้งเกินไป กรุณารอ 15 นาที" |

### Edge Cases
| Scenario | Expected Behavior |
|----------|------------------|
| **Duplicate submit** | Email unique constraint ป้องกัน duplicate |
| **Multi-tab** | ใครส่งก่อนได้ email, อีกคนจะเจอ error duplicate |
| **Phone empty** | บันทึกเป็น NULL ในฐานข้อมูล |
| **Unicode name** | รองรับภาษาไทยและ UTF-8 |

---

## 3. Forgot & Reset Password

### Goal
ให้ผู้ใช้ที่ลืมรหัสผ่านสามารถขอ reset link และตั้งรหัสผ่านใหม่ได้

### Preconditions
- **Login state:** ไม่ได้ login
- **Database state:** 
  - `users` table มี email ที่ต้องการ reset
  - `password_resets` table ต้องมีอยู่

---

### Part A: Request Reset Link

#### Trigger
- **Endpoint:** `/forgot_password.php`
- **Method:** `POST`

#### Inputs
| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| email | string | Yes | Valid email format |

#### Steps
1. Validate email format
2. Check rate limit: 3 requests / email / hour (database-based)
3. Check email exists: `SELECT id, email FROM users WHERE email = ?`
4. Generate token: `bin2hex(random_bytes(32))` (64 chars)
5. Insert: `INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)`
   - `expires_at` = NOW() + 1 hour
6. แสดง success message (ไม่บอกว่า email มีหรือไม่ - security)

#### Expected Results

**Success:**
- **HTTP Status:** 200
- **Database changes:** New row in `password_resets`
- **Message:** "หากอีเมลนี้มีในระบบ คุณจะได้รับลิงก์รีเซ็ตรหัสผ่าน"
- **Demo mode:** แสดง reset link บนหน้าจอ

#### Failure Paths
| Scenario | Error Message |
|----------|---------------|
| Email format ผิด | "รูปแบบอีเมลไม่ถูกต้อง" |
| Rate limit exceeded | "คุณขอรีเซ็ตรหัสผ่านบ่อยเกินไป กรุณารอ 1 ชั่วโมง" |

---

### Part B: Reset Password

#### Trigger
- **Endpoint:** `/reset_password.php?token={token}`
- **Method:** `POST`

#### Inputs
| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| token | string | Yes | In URL query string |
| password | string | Yes | อย่างน้อย 6 ตัวอักษร |
| confirm_password | string | Yes | ต้องตรงกับ password |

#### Steps
1. Validate token: `SELECT pr.*, u.id as user_id FROM password_resets pr JOIN users u ON u.email = pr.email WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW()`
2. Validate password และ confirm_password
3. Begin transaction
4. Update password: `UPDATE users SET password = ? WHERE id = ?`
5. Mark token used: `UPDATE password_resets SET used = 1 WHERE id = ?`
6. Commit transaction

#### Expected Results

**Success:**
- **HTTP Status:** 200
- **Database changes:**
  - `users.password` = new bcrypt hash
  - `password_resets.used` = 1
- **Message:** "เปลี่ยนรหัสผ่านสำเร็จ!"

#### Failure Paths
| Scenario | Error Message |
|----------|---------------|
| Token ไม่ถูกต้อง/หมดอายุ | "ลิงก์ไม่ถูกต้องหรือหมดอายุ" |
| Token ใช้แล้ว | "ลิงก์ไม่ถูกต้องหรือหมดอายุ" |
| Password น้อยกว่า 6 ตัว | "รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร" |
| Password ไม่ตรงกัน | "รหัสผ่านไม่ตรงกัน" |

### Edge Cases
| Scenario | Expected Behavior |
|----------|------------------|
| **Token reuse** | Token ใช้ได้ครั้งเดียว (used = 1) |
| **Expired token** | เกิน 1 ชั่วโมงใช้ไม่ได้ |
| **Multiple requests** | สร้าง token ใหม่ได้ แต่มี rate limit |
| **Concurrent reset** | Transaction ป้องกัน race condition |

---

## 4. Search Books (AJAX API)

### Goal
ให้ผู้ใช้สามารถค้นหาหนังสือแบบ real-time โดยไม่ต้อง reload หน้า

### Preconditions
- **Login state:** ไม่จำเป็น (public API)
- **Database state:** `books` และ `categories` tables มีข้อมูล

### Trigger
- **Endpoint:** `/api/search_books.php`
- **Method:** `GET`

### Inputs
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| search | string | No | คำค้นหา (title, author, ISBN) |
| category | int | No | Category ID |
| status | string | No | `'available'` = หนังสือที่มีพร้อมยืม |

- **Headers:** ไม่มีข้อกำหนดพิเศษ

### Steps
1. รับ parameters จาก query string
2. สร้าง filters array
3. Call `BookRepository::findAll($filters)`
4. Render `includes/book_grid.php` 
5. Return HTML partial

### Expected Results

**Success:**
- **HTTP Status:** 200
- **Content-Type:** `text/html; charset=utf-8`
- **Response:** HTML grid ของ book cards หรือ empty state message
- **Database:** Read-only, ไม่มีการเปลี่ยนแปลง

### Failure Paths
| Scenario | Expected Behavior |
|----------|------------------|
| Invalid category ID | ไม่พบหนังสือ (empty results) |
| SQL injection attempt | Prepared statements ป้องกัน |

### Edge Cases
| Scenario | Expected Behavior |
|----------|------------------|
| **Empty search** | แสดงหนังสือทั้งหมด |
| **Search with spaces** | LIKE clause จัดการได้ |
| **Unicode search** | รองรับภาษาไทย |
| **Multiple filters** | ใช้ทุก filter รวมกัน (AND) |
| **Concurrent requests** | Read-only, ไม่มีปัญหา |

---

## 5. Reserve Book (API)

### Goal
ให้ member สามารถจองหนังสือที่ต้องการยืม

### Preconditions
- **Login state:** ต้อง login เป็น member
- **Database state:** 
  - หนังสือต้องมีอยู่และ `available > 0`
  - ผู้ใช้ไม่มี pending reservation สำหรับหนังสือเล่มเดียวกัน

### Trigger
- **Endpoint:** `/api/reserve_book.php`
- **Method:** `POST`

### Inputs
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| book_id | int | Yes | ID ของหนังสือที่ต้องการจอง |
| csrf_token | string | Yes | CSRF token จาก session |

- **Headers:** Content-Type: application/x-www-form-urlencoded หรือ JSON
- **Session:** ต้องมี `$_SESSION['user_id']`

### Steps
1. ตรวจสอบ method = POST
2. ตรวจสอบ login status
3. Validate CSRF token
4. Validate book_id
5. Call `ReservationService::createReservation($userId, $bookId)`
   - Begin transaction
   - Check existing pending reservation
   - Lock book row: `SELECT ... FOR UPDATE`
   - Check availability
   - Insert reservation (expires in 2 days)
   - Decrement `books.available`
   - Commit

### Expected Results

**Success:**
- **HTTP Status:** 200
- **Content-Type:** `application/json`
- **Response:**
```json
{
  "success": true,
  "message": "จองสำเร็จ! กรุณามารับหนังสือ \"...\" ภายในวันที่ ..."
}
```
- **Database changes:**
  - New row in `reservations` (status = 'pending')
  - `books.available` -= 1

### Failure Paths
| Scenario | HTTP Status | Response |
|----------|-------------|----------|
| ไม่ได้ login | 401 | `{"success": false, "message": "กรุณาเข้าสู่ระบบ"}` |
| CSRF invalid | 403 | `{"success": false, "message": "Invalid CSRF token"}` |
| Method != POST | 405 | `{"success": false, "message": "Method not allowed"}` |
| book_id invalid | 400 | `{"success": false, "message": "Invalid book ID"}` |
| หนังสือไม่มี | 400 | `{"success": false, "message": "ไม่พบหนังสือ"}` |
| available = 0 | 400 | `{"success": false, "message": "หนังสือไม่พร้อมให้ยืม"}` |
| จองซ้ำ | 400 | `{"success": false, "message": "คุณได้จองหนังสือเล่มนี้แล้ว"}` |

### Edge Cases
| Scenario | Expected Behavior |
|----------|------------------|
| **Duplicate submit** | ครั้งที่ 2 จะเจอ error "จองแล้ว" |
| **Race condition** | Row locking (`FOR UPDATE`) ป้องกัน |
| **Multi-tab** | Tab แรกสำเร็จ, tab อื่นเจอ error |
| **Session timeout** | Return 401 |

---

## 6. Create Borrow

### Goal
ให้ staff/admin สามารถบันทึกการยืมหนังสือสำหรับ member

### Preconditions
- **Login state:** ต้องเป็น staff หรือ admin
- **Database state:** 
  - User (member) ต้องมีอยู่
  - หนังสือต้องมี `available > 0`
  - Member ยังไม่ถึง borrow limit

### Trigger
- **Endpoint:** `/admin/borrow_form.php`
- **Method:** `POST`

### Inputs
| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| csrf_token | string | Yes | CSRF token |
| user_id | int | Yes | Member ID ที่จะยืม |
| book_ids[] | array | Yes | Array ของ book IDs |
| borrow_days | int | No | 1-30 วัน (default: `DEFAULT_BORROW_DAYS`) |

### Steps
1. Validate CSRF token
2. ตรวจสอบ user_id เป็น member ที่มีอยู่จริง
3. ตรวจสอบ book_ids ไม่เกิน `MAX_BORROW_BOOKS`
4. สำหรับแต่ละ book:
   - ตรวจสอบ available > 0
   - ตรวจสอบ user ไม่ได้ยืมเล่มเดียวกันอยู่
5. Begin transaction
6. สำหรับแต่ละ book:
   - INSERT into `borrows` (status = 'borrowing')
   - UPDATE `books` SET `available = available - 1`
7. Commit
8. Redirect with success message

### Expected Results

**Success:**
- **HTTP Status:** 302 Redirect to `/admin/borrows.php`
- **Database changes:**
  - New rows in `borrows`:
    - `user_id` = member ID
    - `book_id` = book ID
    - `borrow_date` = CURDATE()
    - `due_date` = CURDATE() + borrow_days
    - `status` = 'borrowing'
  - `books.available` -= 1 สำหรับแต่ละเล่ม
- **Flash message:** "บันทึกการยืมสำเร็จ"

### Failure Paths
| Scenario | Error Message |
|----------|---------------|
| CSRF invalid | Redirect with error |
| User ไม่ใช่ member | "ไม่พบสมาชิก" |
| ไม่เลือก book | "กรุณาเลือกหนังสือ" |
| Book unavailable | "หนังสือ {title} ไม่พร้อมให้ยืม" |
| User ยืมเล่มเดิมอยู่ | "สมาชิกยืมหนังสือเล่มนี้อยู่แล้ว" |
| เกิน borrow limit | "เกินจำนวนที่ยืมได้" |

### Edge Cases
| Scenario | Expected Behavior |
|----------|------------------|
| **Duplicate submit** | ครั้งที่ 2 จะเจอ error "ยืมอยู่แล้ว" |
| **Concurrent borrow** | Transaction + available check ป้องกัน |
| **Last copy** | ถ้า available = 1 และ 2 คนยืมพร้อมกัน → คนแรกได้ |

---

## 7. Return Book with Fine Calculation

### Goal
ให้ staff/admin สามารถบันทึกการคืนหนังสือ พร้อมคำนวณและเก็บค่าปรับ (ถ้ามี)

### Preconditions
- **Login state:** ต้องเป็น staff หรือ admin
- **Database state:** 
  - มี borrow record ที่ `status = 'borrowing'`

### Trigger
- **Endpoint:** `/admin/borrows.php`
- **Method:** `POST`

### Inputs
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| csrf_token | string | Yes | CSRF token |
| action | string | Yes | = 'return' |
| borrow_id | int | Yes | ID ของการยืม |
| pay_now | checkbox | No | ถ้า checked = จ่ายค่าปรับทันที |

### Steps
1. Validate CSRF token และ action = 'return'
2. Load borrow record พร้อม book data
3. ตรวจสอบ status = 'borrowing'
4. Call `BorrowService::returnBook($borrowId, $payNow, $staffId)`
   - Begin transaction
   - คำนวณค่าปรับ: (CURDATE - due_date) × FINE_PER_DAY
   - UPDATE `borrows`:
     - `status` = 'returned'
     - `return_date` = CURDATE()
     - `fine_amount` = calculated fine
   - UPDATE `books` SET `available = available + 1`
   - ถ้า pay_now และมี fine:
     - INSERT into `payments`
   - Commit
5. Redirect with message

### Expected Results

**Success (ไม่เกินกำหนด):**
- **HTTP Status:** 302 Redirect
- **Database changes:**
  - `borrows.status` = 'returned'
  - `borrows.return_date` = วันที่คืน
  - `borrows.fine_amount` = 0
  - `books.available` += 1
- **Flash message:** "บันทึกการคืนเรียบร้อย"

**Success (เกินกำหนด + จ่ายค่าปรับ):**
- **Database changes:**
  - `borrows.fine_amount` = จำนวนค่าปรับ
  - New row in `payments`:
    - `borrow_id`, `amount`, `paid_at`, `received_by`
- **Flash message:** "บันทึกการคืนและรับค่าปรับ {amount} บาท เรียบร้อย"

**Success (เกินกำหนด + ไม่จ่าย):**
- **Flash message (warning):** "บันทึกการคืนเรียบร้อย (มีค่าปรับค้างชำระ {amount} บาท)"

### Failure Paths
| Scenario | Error Message |
|----------|---------------|
| Borrow ไม่พบ | "ไม่พบรายการยืม" |
| Status != 'borrowing' | "รายการนี้คืนแล้ว" |
| Transaction fail | "เกิดข้อผิดพลาด" + rollback |

### Edge Cases
| Scenario | Expected Behavior |
|----------|------------------|
| **คืนวันเดียวกับยืม** | Fine = 0 |
| **คืนก่อนกำหนด** | Fine = 0 |
| **คืนเกิน 1 ปี** | Fine คำนวณตามปกติ (ไม่มี cap) |
| **Duplicate submit** | ครั้งที่ 2 เจอ "คืนแล้ว" |
| **Concurrent return** | Transaction ป้องกัน |

---

## 8. Create/Update Book

### Goal
ให้ staff/admin สามารถเพิ่มหรือแก้ไขข้อมูลหนังสือในระบบ

### Preconditions
- **Login state:** ต้องเป็น staff หรือ admin
- **Database state:** 
  - `categories` table มี categories (optional)
  - (Update) book ID ต้องมีอยู่

### Trigger
- **Endpoint:** `/admin/book_form.php`
- **Method:** `POST`

### Inputs
| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| csrf_token | string | Yes | CSRF token |
| id | int | No | (Update only) Book ID |
| title | string | Yes | ไม่เกิน 200 ตัวอักษร |
| author | string | Yes | ไม่เกิน 100 ตัวอักษร |
| isbn | string | No | Unique (ถ้ากรอก) |
| category_id | int | No | Category ID |
| description | text | No | รายละเอียดหนังสือ |
| quantity | int | No | >= 1 (default: 1) |
| cover_image | file | No | JPEG/PNG/GIF/WEBP, max 2MB |

### Steps

**Create:**
1. Validate CSRF token
2. Validate required fields
3. Check ISBN uniqueness (ถ้ากรอก)
4. Handle cover image upload:
   - Validate MIME type (finfo)
   - Validate size <= 2MB
   - Save to `uploads/covers/`
5. INSERT into `books` (available = quantity)
6. Redirect to books.php

**Update:**
1. Steps 1-4 เหมือน create
2. Check ISBN uniqueness (exclude current book)
3. UPDATE `books` WHERE id = ?
4. Delete old cover ถ้า upload ใหม่
5. Redirect to books.php

### Expected Results

**Success (Create):**
- **HTTP Status:** 302 Redirect to `/admin/books.php`
- **Database changes:**
  - New row in `books`
  - `available` = `quantity`
- **File system:** Cover image saved (ถ้า upload)
- **Flash message:** "เพิ่มหนังสือสำเร็จ"

**Success (Update):**
- **Database changes:** Updated row in `books`
- **Flash message:** "แก้ไขหนังสือสำเร็จ"

### Failure Paths
| Scenario | Error Message |
|----------|---------------|
| Title/Author ว่าง | "กรุณากรอก..." |
| ISBN ซ้ำ | "ISBN นี้มีในระบบแล้ว" |
| File ใหญ่เกิน 2MB | "ไฟล์ใหญ่เกินไป" |
| File type ไม่รองรับ | "รองรับเฉพาะ JPEG, PNG, GIF, WEBP" |

### Edge Cases
| Scenario | Expected Behavior |
|----------|------------------|
| **Update quantity < borrowed** | ต้องจัดการ available ให้ถูกต้อง |
| **Empty ISBN** | บันทึกเป็น NULL |
| **Category ถูกลบ** | Foreign key set NULL |
| **Cover upload fail** | แสดง error, ไม่บันทึก |

---

## 9. Delete Book

### Goal
ให้ staff/admin สามารถลบหนังสือที่ไม่ต้องการออกจากระบบ

### Preconditions
- **Login state:** ต้องเป็น staff หรือ admin
- **Database state:** 
  - หนังสือต้องมีอยู่
  - ไม่มีการยืมที่ยังไม่คืน (`status != 'borrowing'`)
  - `available == quantity` (ทุกเล่มพร้อมอยู่)

### Trigger
- **Endpoint:** `/admin/books.php`
- **Method:** `POST`

### Inputs
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| csrf_token | string | Yes | CSRF token |
| action | string | Yes | = 'delete' |
| id | int | Yes | Book ID |

### Steps
1. Validate CSRF token และ action = 'delete'
2. Begin transaction
3. Lock book row: `SELECT ... FOR UPDATE`
4. Check ไม่มี active borrows: `SELECT COUNT(*) FROM borrows WHERE book_id = ? AND status = 'borrowing'`
5. Check all copies available: `available == quantity`
6. DELETE from `books`
7. Delete cover image file (ถ้ามี)
8. Commit
9. Redirect with success

### Expected Results

**Success:**
- **HTTP Status:** 302 Redirect
- **Database changes:**
  - Book row deleted from `books`
  - Related `borrows` records remain (history)
- **File system:** Cover image deleted
- **Flash message:** "ลบหนังสือสำเร็จ"

### Failure Paths
| Scenario | Error Message |
|----------|---------------|
| Book ไม่พบ | "ไม่พบหนังสือ" |
| มีการยืมอยู่ | "ไม่สามารถลบได้ มีการยืมอยู่" |
| บางเล่มถูกยืม | "ไม่สามารถลบได้ หนังสือบางเล่มถูกยืมอยู่" |

### Edge Cases
| Scenario | Expected Behavior |
|----------|------------------|
| **Delete ขณะมีคนกำลังยืม** | Transaction + lock ป้องกัน |
| **Book มี reservation pending** | ควรตรวจสอบ (ขึ้นอยู่กับ business rule) |
| **Delete แล้ว refresh** | เจอ error "ไม่พบหนังสือ" |

---

## 10. AJAX Add Member

### Goal
ให้ admin สามารถเพิ่ม member ใหม่แบบ quick add ขณะสร้าง borrow

### Preconditions
- **Login state:** ต้องเป็น admin เท่านั้น (staff ไม่ได้)
- **Database state:** Email ต้องไม่ซ้ำ

### Trigger
- **Endpoint:** `/admin/ajax_add_member.php`
- **Method:** `POST`

### Inputs
| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| csrf_token | string | Yes | CSRF token |
| name | string | Yes | 2-100 ตัวอักษร |
| email | string | Yes | Valid email, unique |
| phone | string | No | Valid phone format (ถ้ากรอก) |

- **Headers:** Accept: application/json

### Steps
1. Validate CSRF token
2. Check `isAdmin()` → return 403 ถ้าไม่ใช่ admin
3. Validate name (2-100 chars)
4. Validate email format และ uniqueness
5. Validate phone (optional)
6. Generate random password: `bin2hex(random_bytes(4))` (8 chars)
7. INSERT into `users` (role = 'member')
8. Return JSON response

### Expected Results

**Success:**
- **HTTP Status:** 200
- **Content-Type:** `application/json`
- **Response:**
```json
{
  "success": true,
  "message": "เพิ่มสมาชิกสำเร็จ",
  "member": {
    "id": 123,
    "name": "ชื่อ",
    "email": "email@example.com"
  }
}
```
- **Database changes:**
  - New row in `users`:
    - `role` = 'member'
    - `password` = random bcrypt hash

### Failure Paths
| Scenario | HTTP Status | Response |
|----------|-------------|----------|
| ไม่ใช่ admin | 403 | `{"success": false, "message": "ไม่มีสิทธิ์"}` |
| CSRF invalid | 403 | `{"success": false, "message": "CSRF error"}` |
| Name สั้นเกิน | 400 | `{"success": false, "message": "ชื่อต้องมี 2-100 ตัวอักษร"}` |
| Email ซ้ำ | 400 | `{"success": false, "message": "อีเมลนี้มีในระบบแล้ว"}` |
| Email format ผิด | 400 | `{"success": false, "message": "รูปแบบอีเมลไม่ถูกต้อง"}` |

### Edge Cases
| Scenario | Expected Behavior |
|----------|------------------|
| **Staff เรียก API** | Return 403 |
| **Duplicate email submit** | ครั้งที่ 2 เจอ email ซ้ำ |
| **Random password** | Member ต้องใช้ forgot password |

---

## 11. Import Books from CSV

### Goal
ให้ staff/admin สามารถ import หนังสือหลายเล่มจากไฟล์ CSV

### Preconditions
- **Login state:** ต้องเป็น staff หรือ admin
- **Database state:** ระบบพร้อมรับข้อมูล

### Trigger
- **Endpoint:** `/admin/import_books.php`
- **Method:** `POST`

### Inputs
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| csrf_token | string | Yes | CSRF token |
| csv_file | file | Yes | .csv file |

**CSV Format:**
```
Title, Author, ISBN, Category, Quantity
หนังสือ A, ผู้แต่ง A, 978-xxx, หมวด 1, 5
หนังสือ B, ผู้แต่ง B, , หมวด 2, 3
```

### Steps
1. Validate CSRF token
2. Validate file extension = .csv
3. Begin transaction
4. Read CSV line by line:
   - Skip header (optional detection)
   - Parse: title, author, isbn, category, quantity
   - ถ้า category ไม่มี → สร้างใหม่
   - ถ้า book exists (by title + author) → UPDATE quantity
   - ถ้า book ไม่มี → INSERT
5. Track: imported, updated, skipped counts
6. Commit
7. Redirect with summary

### Expected Results

**Success:**
- **HTTP Status:** 302 Redirect
- **Database changes:**
  - New rows in `books` (imported)
  - Updated rows in `books` (quantity เพิ่ม)
  - New rows in `categories` (ถ้า auto-create)
- **Flash message:** "นำเข้าสำเร็จ: เพิ่ม X เล่ม, อัพเดต Y เล่ม, ข้าม Z แถว"

### Failure Paths
| Scenario | Error Message |
|----------|---------------|
| File ไม่ใช่ .csv | "กรุณาอัพโหลดไฟล์ CSV" |
| File parse error | Row ถูก skip + แสดงใน summary |
| Transaction fail | Rollback ทั้งหมด |

### Edge Cases
| Scenario | Expected Behavior |
|----------|------------------|
| **Empty file** | Success แต่ 0 imported |
| **Duplicate in CSV** | แถวแรกสร้าง, แถวหลัง update |
| **UTF-8 BOM** | ควรจัดการได้ (หรือ skip header) |
| **มี comma ใน title** | ต้อง quote ใน CSV |
| **Very large file** | อาจ timeout (ขึ้นกับ server config) |

---

## 12. Approve/Cancel Reservation

### Goal
ให้ admin สามารถอนุมัติหรือยกเลิกการจองหนังสือ

### Preconditions
- **Login state:** ต้องเป็น admin เท่านั้น
- **Database state:** 
  - มี reservation ที่ `status = 'pending'`

### Trigger
- **Endpoint:** `/admin/reservations.php`
- **Method:** `POST`

### Inputs (Approve)
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| csrf_token | string | Yes | CSRF token |
| action | string | Yes | = 'approve' |
| id | int | Yes | Reservation ID |

### Inputs (Cancel)
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| csrf_token | string | Yes | CSRF token |
| action | string | Yes | = 'cancel' |
| id | int | Yes | Reservation ID |

### Steps (Approve)
1. Validate CSRF token
2. Load reservation (must be pending)
3. Begin transaction
4. INSERT into `borrows` (เริ่มการยืมทันที)
5. UPDATE `reservations` SET `status = 'fulfilled'`
6. Commit
7. Redirect with success

### Steps (Cancel)
1. Validate CSRF token
2. Load reservation (must be pending)
3. Begin transaction
4. UPDATE `books` SET `available = available + 1`
5. UPDATE `reservations` SET `status = 'cancelled'`
6. Commit
7. Redirect with success

### Expected Results

**Success (Approve):**
- **HTTP Status:** 302 Redirect
- **Database changes:**
  - `reservations.status` = 'fulfilled'
  - New row in `borrows`:
    - `user_id`, `book_id` from reservation
    - `status` = 'borrowing'
    - `borrow_date` = CURDATE()
- **Flash message:** "อนุมัติการจองสำเร็จ"

**Success (Cancel):**
- **HTTP Status:** 302 Redirect
- **Database changes:**
  - `reservations.status` = 'cancelled'
  - `books.available` += 1
- **Flash message:** "ยกเลิกการจองเรียบร้อย"

### Failure Paths
| Scenario | Error Message |
|----------|---------------|
| Reservation ไม่พบ | "ไม่พบรายการจอง" |
| Status != pending | "รายการนี้ถูกดำเนินการแล้ว" |
| ไม่ใช่ admin | Redirect to login |

### Edge Cases
| Scenario | Expected Behavior |
|----------|------------------|
| **Approve ซ้ำ** | ครั้งที่ 2 เจอ "ดำเนินการแล้ว" |
| **Cancel หลัง approved** | เจอ "ดำเนินการแล้ว" |
| **Concurrent approve/cancel** | Transaction ป้องกัน, คนแรกได้ |
| **Expired reservation** | ยังคง pending (ต้อง cancel manual) |

---

## Appendix: Common Test Data

### Test Users
```sql
-- Admin
INSERT INTO users (name, email, password, role) VALUES 
('Admin User', 'admin@test.com', '$2y$10$...', 'admin');

-- Member
INSERT INTO users (name, email, password, role) VALUES 
('Test Member', 'member@test.com', '$2y$10$...', 'member');
```

### Test Books
```sql
INSERT INTO books (title, author, isbn, quantity, available) VALUES 
('Test Book 1', 'Author A', '978-0001', 5, 5),
('Test Book 2', 'Author B', '978-0002', 1, 1),
('Test Book 3', 'Author C', '978-0003', 2, 0); -- ไม่มีของ
```

### Configuration Constants
| Constant | Default | Description |
|----------|---------|-------------|
| `MAX_BORROW_BOOKS` | 5 | จำนวน books สูงสุดต่อการยืม |
| `DEFAULT_BORROW_DAYS` | 7 | วันยืมเริ่มต้น |
| `FINE_PER_DAY` | 10 | ค่าปรับต่อวัน (บาท) |
| `RESERVATION_DAYS` | 2 | วันหมดอายุการจอง |

---

## Revision History

| Date | Version | Author | Changes |
|------|---------|--------|---------|
| 2026-01-31 | 1.0 | QA Team | Initial document |
