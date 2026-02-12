# Flow Test Guide V3 - คู่มือทดสอบ Flow ระบบยืมคืนหนังสือ

เอกสารนี้เขียนขึ้นเพื่อใช้ **ทดสอบตามได้จริง** โดยไม่ต้องเปิดโค้ด  
ใช้สำหรับ manual testing และเป็นฐานสำหรับเขียน automated tests

**ขอบเขต:** Backend flows (page + API) ที่มีอยู่จริงในโค้ด  
**ไม่ครอบคลุม:** Frontend UI detail, CSS, JavaScript interactions

---

## สารบัญ (12 Flows)

| # | Flow | หมวด | Entry Point |
|---|------|------|-------------|
| 1 | [Login](#flow-1-login) | Auth | `login.php` |
| 2 | [Register](#flow-2-register) | Auth | `register.php` |
| 3 | [Create Borrow](#flow-3-create-borrow) | Core TX | `admin/borrow_form.php` |
| 4 | [Return Book](#flow-4-return-book) | Core TX | `admin/borrows.php` |
| 5 | [Reserve Book](#flow-5-reserve-book-ajax) | API | `api/reserve_book.php` |
| 6 | [Cancel Reservation (User)](#flow-6-cancel-reservation-user) | API | `api/cancel_reservation.php` |
| 7 | [Fulfill Reservation (Admin)](#flow-7-fulfill-reservation-admin) | Admin TX | `admin/reservations.php` |
| 8 | [Cancel Reservation (Admin)](#flow-8-cancel-reservation-admin) | Admin TX | `admin/reservations.php` |
| 9 | [Pay Fine](#flow-9-pay-fine) | Payment | `admin/payments.php` |
| 10 | [Create Book](#flow-10-create-book) | CRUD | `admin/book_form.php` |
| 11 | [Delete Book](#flow-11-delete-book) | CRUD | `admin/books.php` |
| 12 | [Search Books](#flow-12-search-books-api) | API | `api/search_books.php` |

---

## Flow 1: Login

### 1) Flow Name
**User Login (เข้าสู่ระบบ)**

### 2) Goal
ผู้ใช้ authenticate ด้วย email/password → สร้าง session → redirect ตาม role

### 3) Preconditions
- **Login state:** ไม่ได้ login (ถ้า login อยู่แล้วจะ redirect ออกทันที)
- **Database state:** มี user ที่ email + password ตรงกันอยู่ใน `users` table

### 4) Trigger
- **Endpoint:** `POST /login.php`
- **Method:** POST (form submit)

### 5) Inputs

| Parameter | Source | Required | หมายเหตุ |
|-----------|--------|----------|----------|
| `email` | POST body | ✅ | email ของ user |
| `password` | POST body | ✅ | plaintext password |

- **ไม่มี CSRF token** (ใช้ rate limit แทน)
- **Session:** ต้องมี PHP session active (bootstrap.php สร้างให้)

### 6) Steps

```
1. POST /login.php ด้วย email + password
2. ระบบตรวจว่า login อยู่แล้วไหม → ถ้าใช่ redirect
3. Validate: email + password ไม่ว่าง
4. checkRateLimit('login_' . md5(email)) → ถ้าเกิน 5 ครั้ง/15 นาที → error
5. AuthService::login(email, password)
   → UserRepository::findByEmail(email)
   → password_verify(password, hash)
6. สำเร็จ:
   → session_regenerate_id(true)
   → เก็บ user_id + user_role ใน session
   → resetRateLimit()
   → redirect ตาม role
7. ไม่สำเร็จ:
   → incrementRateLimit()
   → แสดง error
```

### 7) Expected Results

**Success (admin/staff):**
- HTTP: `302 → /admin/`
- Session: `$_SESSION['user_id']` = user ID, `$_SESSION['user_role']` = 'admin'/'staff'
- DB: ไม่เปลี่ยน (read-only)

**Success (member):**
- HTTP: `302 → /index.php`
- Session: `$_SESSION['user_role']` = 'member'

### 8) Failure Paths

| Condition | HTTP | Response | DB Change |
|-----------|------|----------|-----------|
| Email ว่าง | 200 | แสดง error ใน form | ไม่มี |
| Password ว่าง | 200 | แสดง error ใน form | ไม่มี |
| Email ไม่พบใน DB | 200 | "อีเมลหรือรหัสผ่านไม่ถูกต้อง" | rate_limits +1 |
| Password ไม่ตรง | 200 | "อีเมลหรือรหัสผ่านไม่ถูกต้อง" | rate_limits +1 |
| Rate limit exceeded (>5 ครั้ง) | 200 | "ลองผิดหลายครั้งเกินไป กรุณารอ 15 นาที" | ไม่มี |

### 9) Edge Cases

| Case | พฤติกรรมที่คาดหวัง |
|------|-------------------|
| **Login ซ้ำขณะ login อยู่** | redirect ออกทันที ไม่แสดง form |
| **Multi-tab login** | ทั้งสอง tab ใช้ session เดียวกัน — login tab แรกสำเร็จ, tab ที่ 2 จะ redirect |
| **Retry หลัง rate limit** | ต้องรอ 15 นาที ถึงจะลองใหม่ได้ |
| **User enumeration** | Error message เหมือนกันทั้ง email ไม่พบ + password ผิด |
| **Session fixation** | `session_regenerate_id(true)` สร้าง session ID ใหม่หลัง login สำเร็จ |

---

## Flow 2: Register

### 1) Flow Name
**User Registration (สมัครสมาชิก)**

### 2) Goal
ผู้ใช้ใหม่สมัครเป็น member (role='member' เท่านั้น)

### 3) Preconditions
- **Login state:** ไม่ได้ login (ถ้า login อยู่จะ redirect ไป index.php)
- **Database state:** email ต้องไม่ซ้ำกับที่มีอยู่ใน `users` table

### 4) Trigger
- **Endpoint:** `POST /register.php`
- **Method:** POST (form submit)

### 5) Inputs

| Parameter | Source | Required | Validation |
|-----------|--------|----------|------------|
| `csrf_token` | POST body | ✅ | ต้องตรงกับ session |
| `name` | POST body | ✅ | ไม่ว่าง, ≤100 chars |
| `email` | POST body | ✅ | valid email format, unique |
| `phone` | POST body | ❌ | 9-10 digits (ถ้ากรอก) |
| `password` | POST body | ✅ | ≥ 6 chars (MIN_PASSWORD_LENGTH) |
| `confirm_password` | POST body | ✅ | ต้อง = password |

### 6) Steps

```
1. POST /register.php ด้วย csrf_token + ข้อมูลสมาชิก
2. validateCSRFToken() → 403 ถ้าไม่ผ่าน
3. checkRateLimit('register') → global key, ไม่ใช่ per-email
4. incrementRateLimit('register') ← นับก่อน validate
5. validateMemberData() → ตรวจ name, email format, phone format
6. ตรวจ confirm_password == password
7. AuthService::register()
   → MemberService::createMember()
   → ตรวจ email unique ใน DB
   → hashPassword()
   → UserRepository::create() (role='member')
8. สำเร็จ → redirect /login.php + flash "สมัครสมาชิกสำเร็จ"
9. ล้มเหลว → แสดง errors + เก็บค่าเดิมใน form
```

### 7) Expected Results

**Success:**
- HTTP: `302 → /login.php`
- Flash: "สมัครสมาชิกสำเร็จ กรุณาเข้าสู่ระบบ"
- DB: `users` +1 row (role='member', password=bcrypt hash)

### 8) Failure Paths

| Condition | HTTP | Response |
|-----------|------|----------|
| CSRF invalid | 302 → register.php | flash error "คำขอไม่ถูกต้อง" |
| Name ว่าง | 200 | error "กรุณากรอกชื่อ" |
| Email format ผิด | 200 | error "รูปแบบอีเมลไม่ถูกต้อง" |
| Email ซ้ำ | 200 | error "อีเมลนี้ถูกใช้งานแล้ว" |
| Password < 6 chars | 200 | error "รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร" |
| Confirm ≠ password | 200 | error "รหัสผ่านไม่ตรงกัน" |
| Rate limit | 200 | error "ลองหลายครั้งเกินไป" |

### 9) Edge Cases

| Case | พฤติกรรมที่คาดหวัง |
|------|-------------------|
| **Duplicate submit** | ครั้งที่ 2 ได้ "อีเมลนี้ถูกใช้งานแล้ว" (email UNIQUE) |
| **Rate limit bypass ด้วย invalid data** | incrementRateLimit() ก่อน validate → ยังนับ |
| **Register ขณะ login อยู่** | redirect ไป index.php ทันที |
| **Register เป็น admin/staff** | ไม่ได้ — สร้างได้แค่ role='member' |

---

## Flow 3: Create Borrow

### 1) Flow Name
**Create Borrow (บันทึกการยืมหนังสือ)**

### 2) Goal
Staff บันทึกการยืมหนังสือให้ member — ลด stock, สร้าง borrow record (รองรับหลายเล่มพร้อมกัน)

### 3) Preconditions
- **Login state:** login เป็น staff หรือ admin
- **Database state:**
  - Member ที่ต้องยืมให้ต้องมีอยู่ใน `users` (role='member')
  - หนังสือแต่ละเล่มต้อง `available > 0`
  - Member ยืมอยู่ < MAX_BORROW_BOOKS (3)

### 4) Trigger
- **Endpoint:** `POST /admin/borrow_form.php`
- **Method:** POST (form submit)

### 5) Inputs

| Parameter | Source | Required | Validation |
|-----------|--------|----------|------------|
| `csrf_token` | POST body | ✅ | ต้องตรง |
| `user_id` | POST body | ✅ | int > 0, ต้องเป็น member |
| `book_ids[]` | POST body (array) | ✅ | ไม่ว่าง, แต่ละ id > 0 |
| `borrow_days` | POST body | ❌ | 1–30, default=7 (DEFAULT_BORROW_DAYS) |

### 6) Steps

```
1. POST /admin/borrow_form.php ด้วย csrf_token, user_id, book_ids[], borrow_days
2. requireStaff() → 302 login.php ถ้าไม่ใช่ staff
3. validateCSRFToken()
4. ตรวจ idempotency key: borrow_{userId}_{md5(bookIds)}
   → ถ้าซ้ำ → redirect + "รายการนี้ถูกบันทึกไปแล้ว"
5. BorrowService::createBorrow(userId, bookIds, borrowDays)
   5.1 validate inputs
   5.2 ตรวจ user เป็น member จริง
   5.3 BEGIN TRANSACTION
   5.4 Lock user row (FOR UPDATE)
   5.5 countActiveBorrowsForUpdate() → ตรวจ quota
   5.6 Loop แต่ละ book:
       - findByIdForUpdate() → lock book
       - ตรวจ available > 0
       - ตรวจ isAlreadyBorrowing() → ห้ามยืมเล่มเดิมซ้ำ
       - decrementAvailable() → WHERE available > 0
       - BorrowRepository::create()
   5.7 COMMIT
6. บันทึก idempotency key ใน session
7. redirect /admin/borrows.php + flash
```

### 7) Expected Results

**Success (ทุกเล่ม):**
- HTTP: `302 → /admin/borrows.php`
- Flash: "บันทึกการยืมสำเร็จ N เล่ม | กำหนดคืน: DD/MM/YYYY"
- DB: `books.available` ลดลง 1 ต่อเล่ม, `borrows` +N rows (status='borrowing')

**Partial success (บางเล่ม skip):**
- Flash: "บันทึกการยืมสำเร็จ N เล่ม (ข้าม: ชื่อหนังสือ (เหตุผล))"
- DB: เฉพาะเล่มที่สำเร็จ

### 8) Failure Paths

| Condition | HTTP | Response | DB Change |
|-----------|------|----------|-----------|
| ไม่ใช่ staff | 302 → login.php | — | ไม่มี |
| CSRF invalid | 302 → borrow_form.php | flash error | ไม่มี |
| user_id ไม่ใช่ member | 200 | error message | ไม่มี |
| book_ids ว่าง | 200 | error "กรุณาเลือกหนังสือ" | ไม่มี |
| Quota เต็ม | 200 | "ผู้ยืมถึงจำนวนสูงสุดแล้ว (3 เล่ม)" | rollback ทั้งหมด |
| Book available = 0 | — | เล่มนั้นถูก skip | เฉพาะเล่มที่สำเร็จ |
| ยืมเล่มเดิมซ้ำ | — | เล่มนั้นถูก skip "(ยืมอยู่แล้ว)" | เฉพาะเล่มอื่น |

### 9) Edge Cases

| Case | พฤติกรรมที่คาดหวัง |
|------|-------------------|
| **Double submit (กด Back + Submit)** | idempotency key catch → "รายการนี้ถูกบันทึกไปแล้ว" |
| **Concurrent borrow สำเนาสุดท้าย** | FOR UPDATE lock → คนที่ 2 รอ → available=0 → skip |
| **ยืม 4 เล่ม ขณะมี 0 ยืมอยู่ (MAX=3)** | ได้แค่ 3 เล่ม, เล่มที่ 4 ถูก skip (หรือ exception ตาม logic) |
| **Member ถูกลบระหว่าง submit** | Service ตรวจ user exists ใน TX → error |

---

## Flow 4: Return Book

### 1) Flow Name
**Return Book (คืนหนังสือ)**

### 2) Goal
Staff บันทึกคืนหนังสือ → คำนวณค่าปรับอัตโนมัติ → คืน stock → เลือกชำระทันทีหรือค้างได้

### 3) Preconditions
- **Login state:** login เป็น staff/admin
- **Database state:** มี borrow record ที่ status='borrowing'

### 4) Trigger
- **Endpoint:** `POST /admin/borrows.php`
- **Method:** POST (action=return)

### 5) Inputs

| Parameter | Source | Required | Validation |
|-----------|--------|----------|------------|
| `csrf_token` | POST body | ✅ | ต้องตรง |
| `action` | POST body | ✅ | = 'return' |
| `borrow_id` | POST body | ✅ | int > 0 |
| `pay_now` | POST body (checkbox) | ❌ | ถ้ามี = ชำระทันที |

### 6) Steps

```
1. POST /admin/borrows.php ด้วย action=return, borrow_id, csrf_token
2. requireStaff()
3. validateCSRFToken()
4. ตรวจ idempotency key: return_{borrowId}
5. BorrowService::returnBook(borrowId, payNow, staffId)
   5.1 BEGIN TRANSACTION
   5.2 findByIdForUpdate(borrowId) → WHERE status='borrowing' FOR UPDATE
       → null = คืนไปแล้วหรือไม่พบ
   5.3 calculateFine(due_date, today)
       → overdue: days × FINE_PER_DAY (10)
       → ไม่เกิน: {days:0, amount:0}
   5.4 markAsReturned(borrowId, fineAmount)
       → UPDATE status='returned', return_date=NOW, fine_amount
   5.5 incrementAvailable(bookId) → available + 1
   5.6 ถ้า payNow && fine > 0:
       → PaymentRepository::create(borrowId, fineAmount, staffId)
   5.7 COMMIT
6. บันทึก idempotency key
7. redirect /admin/borrows.php + flash
```

### 7) Expected Results

**Success (ไม่มีค่าปรับ):**
- HTTP: `302 → /admin/borrows.php`
- Flash (success): "บันทึกการคืนหนังสือสำเร็จ"
- DB: `borrows.status`='returned', `borrows.fine_amount`=0, `books.available` +1

**Success (มีค่าปรับ + จ่ายทันที):**
- Flash (success): "ค่าปรับ: X บาท (เกิน Y วัน) [รับชำระเงินแล้ว]"
- DB: เพิ่ม `borrows.fine_amount`=X, `payments` +1 row, `books.available` +1

**Success (มีค่าปรับ + ไม่จ่าย):**
- Flash (warning): "ค่าปรับ: X บาท (เกิน Y วัน) [ยังไม่จ่าย]"
- DB: `borrows.fine_amount`=X, ไม่มี payment row, `books.available` +1

### 8) Failure Paths

| Condition | HTTP | Response |
|-----------|------|----------|
| ไม่ใช่ staff | 302 → login.php | — |
| CSRF invalid | 302 → borrows.php | flash error |
| borrow ไม่พบ / คืนแล้ว | 302 → borrows.php | "ไม่พบรายการยืมหรือคืนหนังสือแล้ว" |

### 9) Edge Cases

| Case | พฤติกรรมที่คาดหวัง |
|------|-------------------|
| **Double submit** | idempotency key → "รายการนี้ถูกบันทึกไปแล้ว" |
| **Concurrent return** | FOR UPDATE + status='borrowing' → คนที่ 2 ได้ null → error |
| **คืนวันเดียวกับ due_date** | fine = 0 (ไม่เกิน) |
| **คืนเลย 1 วัน** | fine = 1 × FINE_PER_DAY = 10 บาท |
| **pay_now + fine=0** | ไม่สร้าง payment row (ตรวจ fine > 0 ก่อน) |

---

## Flow 5: Reserve Book (AJAX)

### 1) Flow Name
**Reserve Book (จองหนังสือผ่าน AJAX)**

### 2) Goal
Member จองหนังสือเพื่อมารับทีหลัง — stock ถูกกันทันที, หมดอายุใน 2 วัน

### 3) Preconditions
- **Login state:** login แล้ว (ทุก role)
- **Database state:** หนังสือที่จอง `available > 0`, ไม่มี pending reservation ซ้ำ

### 4) Trigger
- **Endpoint:** `POST /api/reserve_book.php`
- **Method:** POST
- **Response format:** JSON

### 5) Inputs

| Parameter | Source | Required | Validation |
|-----------|--------|----------|------------|
| `csrf_token` | POST body | ✅ | ต้องตรง |
| `book_id` | POST body | ✅ | int > 0 |
| (user_id) | `$_SESSION['user_id']` | — | ห้ามรับจาก POST |

### 6) Steps

```
1. POST /api/reserve_book.php ด้วย csrf_token + book_id
2. isLoggedIn() → 401 JSON ถ้าไม่
3. ตรวจ method = POST → 405 JSON
4. validateCSRFToken() → 403 JSON
5. Validate book_id > 0 → 400 JSON
6. ReservationService::createReservation(userId, bookId)
   6.1 BEGIN TRANSACTION
   6.2 expireOverdueByBook(bookId) ← lazy expiration (คืน stock ของจองหมดอายุ)
   6.3 hasPendingReservation(userId, bookId) → ห้ามจองซ้ำ
   6.4 findByIdForUpdate(bookId) → lock book
   6.5 ตรวจ available > 0
   6.6 decrementAvailable(bookId) → กัน stock
   6.7 ReservationRepository::create() → status='pending', expires_at=+2 days
   6.8 COMMIT
7. echo JSON success
```

### 7) Expected Results

**Success:**
- HTTP: `200`
- Body: `{"success": true, "message": "จองสำเร็จ! กรุณามารับหนังสือภายใน 2 วัน"}`
- DB: `books.available` -1, `reservations` +1 row (status='pending', expires_at)

### 8) Failure Paths

| Condition | HTTP | JSON Response |
|-----------|------|---------------|
| ไม่ได้ login | 401 | `{"success": false, "message": "กรุณาเข้าสู่ระบบ"}` |
| Method ≠ POST | 405 | `{"success": false, "message": "Method not allowed"}` |
| CSRF invalid | 403 | `{"success": false, "message": "..."}` |
| book_id ≤ 0 | 400 | `{"success": false, "message": "Invalid book ID"}` |
| จองซ้ำ | 400 | `{"success": false, "message": "คุณได้จองหนังสือเล่มนี้ไว้แล้ว"}` |
| available = 0 | 400 | `{"success": false, "message": "หนังสือหมด"}` |

### 9) Edge Cases

| Case | พฤติกรรมที่คาดหวัง |
|------|-------------------|
| **Concurrent reserve สำเนาสุดท้าย** | FOR UPDATE → คนที่ 2 รอ → available=0 → "หนังสือหมด" |
| **Lazy expiration ปลด stock** | ถ้ามี reservation หมดอายุ → stock กลับมา → อาจจองสำเร็จ |
| **จองแล้วจอง tab อื่น** | hasPendingReservation() catch → "จองไว้แล้ว" |
| **ส่ง user_id ปลอมใน POST** | ไม่มีผล — ใช้ session เท่านั้น |

---

## Flow 6: Cancel Reservation (User)

### 1) Flow Name
**Cancel Reservation by User (ยกเลิกการจอง — ผู้ใช้)**

### 2) Goal
ผู้ใช้ยกเลิกการจองของตัวเอง → คืน stock

### 3) Preconditions
- **Login state:** login แล้ว
- **Database state:** มี reservation ที่ status='pending' และเป็นของ user นั้น

### 4) Trigger
- **Endpoint:** `POST /api/cancel_reservation.php`
- **Method:** POST
- **Response format:** redirect (ไม่ใช่ JSON)

### 5) Inputs

| Parameter | Source | Required | Validation |
|-----------|--------|----------|------------|
| `csrf_token` | POST body | ✅ | ต้องตรง |
| `reservation_id` | POST body | ✅ | int > 0 |

### 6) Steps

```
1. POST /api/cancel_reservation.php
2. isLoggedIn() → redirect login ถ้าไม่
3. ตรวจ method = POST → redirect ถ้าไม่
4. validateCSRFToken() → redirect + flash error
5. Validate reservation_id > 0
6. ReservationService::cancelReservation(reservationId, userId)
   6.1 BEGIN TRANSACTION
   6.2 findPendingForUpdate(reservationId) → lock + status='pending'
   6.3 ตรวจ owner: reservation.user_id == userId
   6.4 markCancelled(reservationId) → status='cancelled'
   6.5 incrementAvailable(bookId) → คืน stock
   6.6 COMMIT
7. redirect /my_reservations.php + flash
```

### 7) Expected Results

**Success:**
- HTTP: `302 → /my_reservations.php`
- Flash (success): "ยกเลิกการจองเรียบร้อยแล้ว"
- DB: `reservations.status`='cancelled', `books.available` +1

### 8) Failure Paths

| Condition | HTTP | Response |
|-----------|------|----------|
| ไม่ได้ login | 302 → login.php | flash error |
| CSRF invalid | 302 → my_reservations.php | flash error |
| reservation_id ≤ 0 | 302 → my_reservations.php | "รหัสการจองไม่ถูกต้อง" |
| ไม่ใช่เจ้าของ | 302 → my_reservations.php | Exception message |
| status ≠ pending | 302 → my_reservations.php | Exception "ไม่พบการจอง..." |

### 9) Edge Cases

| Case | พฤติกรรมที่คาดหวัง |
|------|-------------------|
| **ยกเลิกจองของคนอื่น** | ownership check fail → Exception |
| **ยกเลิกจองที่ approved แล้ว** | findPendingForUpdate returns null → error |
| **ยกเลิกจองที่ expired แล้ว** | เช่นกัน — status ≠ pending |
| **Concurrent cancel** | FOR UPDATE → คนที่ 2 ได้ null → error |

---

## Flow 7: Fulfill Reservation (Admin)

### 1) Flow Name
**Fulfill Reservation (อนุมัติการจอง — Staff)**

### 2) Goal
Staff อนุมัติการจอง → สร้าง borrow record อัตโนมัติ (stock ไม่ถูกหักเพิ่ม)

### 3) Preconditions
- **Login state:** login เป็น staff/admin
- **Database state:** มี reservation ที่ status='pending', member ยืมอยู่ < MAX_BORROW_BOOKS

### 4) Trigger
- **Endpoint:** `POST /admin/reservations.php`
- **Method:** POST (action=approve)

### 5) Inputs

| Parameter | Source | Required | Validation |
|-----------|--------|----------|------------|
| `csrf_token` | POST body | ✅ | ต้องตรง |
| `action` | POST body | ✅ | = 'approve' |
| `id` | POST body | ✅ | reservation_id, int > 0 |

### 6) Steps

```
1. POST /admin/reservations.php ด้วย action=approve, id, csrf_token
2. requireStaff()
3. validateCSRFToken()
4. ตรวจ idempotency key: reservation_approve_{id}
5. ReservationService::fulfillReservation(reservationId)
   5.1 BEGIN TRANSACTION
   5.2 findPendingForUpdate(reservationId)
       → null = ไม่ใช่ pending / ไม่พบ
   5.3 countActiveBorrowsForUpdate(userId) → ตรวจ quota
   5.4 BorrowRepository::create() → สร้าง borrow record
   5.5 updateStatusWithBorrow($id, 'fulfilled', $borrowId)
   5.6 COMMIT
6. บันทึก idempotency key
7. redirect /admin/reservations.php + flash
```

### 7) Expected Results

**Success:**
- HTTP: `302 → /admin/reservations.php`
- Flash (success): "อนุมัติการจองสำเร็จ! สร้างรายการยืมแล้ว"
- DB: `reservations.status`='fulfilled', `reservations.borrow_id`=new ID, `borrows` +1 row
- **สำคัญ:** `books.available` ไม่เปลี่ยน (หักไปแล้วตอนจอง)

### 8) Failure Paths

| Condition | HTTP | Response |
|-----------|------|----------|
| ไม่ใช่ staff | 302 → login.php | — |
| CSRF invalid | 302 → reservations.php | flash error |
| reservation ไม่ใช่ pending | 302 | Exception message |
| Member quota เต็ม | 302 | Exception "เกินโควต้า" |

### 9) Edge Cases

| Case | พฤติกรรมที่คาดหวัง |
|------|-------------------|
| **Double approve** | idempotency key catch → "รายการนี้ถูกดำเนินการไปแล้ว" |
| **Concurrent approve** | FOR UPDATE → คนที่ 2 ได้ null (status เปลี่ยนไปแล้ว) → error |
| **Quota เต็มระหว่างรอ** | member ยืมเพิ่มขณะ pending → fulfill ถูก reject เพราะ quota |
| **Approve หลัง expire** | reservation.status='expired' → findPending returns null |

---

## Flow 8: Cancel Reservation (Admin)

### 1) Flow Name
**Cancel Reservation by Admin (ยกเลิกการจอง — Staff)**

### 2) Goal
Staff ยกเลิกการจองของ member → คืน stock

### 3) Preconditions
- **Login state:** login เป็น staff/admin
- **Database state:** มี reservation ที่ status='pending'

### 4) Trigger
- **Endpoint:** `POST /admin/reservations.php`
- **Method:** POST (action=cancel)

### 5) Inputs

| Parameter | Source | Required | Validation |
|-----------|--------|----------|------------|
| `csrf_token` | POST body | ✅ | ต้องตรง |
| `action` | POST body | ✅ | = 'cancel' |
| `id` | POST body | ✅ | reservation_id, int > 0 |

### 6) Steps

```
1. POST /admin/reservations.php ด้วย action=cancel, id, csrf_token
2. requireStaff()
3. validateCSRFToken()
4. ตรวจ idempotency key: reservation_cancel_{id}
5. ReservationService::cancelReservation(reservationId)
   → ไม่ส่ง userId (admin ไม่ต้อง ownership check)
   5.1 BEGIN TRANSACTION
   5.2 findPendingForUpdate() → lock + status='pending'
   5.3 markCancelled(reservationId)
   5.4 incrementAvailable(bookId) → คืน stock
   5.5 COMMIT
6. บันทึก idempotency key
7. redirect /admin/reservations.php + flash
```

### 7) Expected Results

**Success:**
- HTTP: `302 → /admin/reservations.php`
- Flash (success): "ยกเลิกการจองและคืนสต็อกหนังสือเรียบร้อยแล้ว"
- DB: `reservations.status`='cancelled', `books.available` +1

### 8) Failure Paths

| Condition | HTTP | Response |
|-----------|------|----------|
| ไม่ใช่ staff | 302 → login.php | — |
| CSRF invalid | 302 → reservations.php | flash error |
| reservation ≠ pending | 302 | Exception message |

### 9) Edge Cases

| Case | พฤติกรรมที่คาดหวัง |
|------|-------------------|
| **Double cancel** | idempotency key → "รายการนี้ถูกดำเนินการไปแล้ว" |
| **Cancel หลัง expire** | status='expired' → not pending → error |
| **Admin cancel ≠ User cancel** | admin ไม่มี ownership check (user cancel ต้องตรวจ) |

---

## Flow 9: Pay Fine

### 1) Flow Name
**Pay Fine (ชำระค่าปรับ — ภายหลัง)**

### 2) Goal
Staff บันทึกการชำระค่าปรับของ borrow ที่คืนแล้วแต่ยังไม่จ่ายตอนคืน

### 3) Preconditions
- **Login state:** login เป็น staff/admin
- **Database state:** มี borrow ที่ `fine_amount > 0` และยังไม่มี row ใน `payments` table

### 4) Trigger
- **Endpoint:** `POST /admin/payments.php`
- **Method:** POST (action=pay_fine)

### 5) Inputs

| Parameter | Source | Required | Validation |
|-----------|--------|----------|------------|
| `csrf_token` | POST body | ✅ | ต้องตรง |
| `action` | POST body | ✅ | = 'pay_fine' |
| `borrow_id` | POST body | ✅ | int > 0 |

### 6) Steps

```
1. POST /admin/payments.php ด้วย action=pay_fine, borrow_id, csrf_token
2. requireStaff()
3. validateCSRFToken()
4. ตรวจ idempotency key: pay_fine_{borrowId}
5. BorrowService::payFine(borrowId, staffId)
   5.1 BEGIN TRANSACTION
   5.2 findByIdForUpdateAnyStatus(borrowId) → lock (ทุก status)
   5.3 ตรวจ fine_amount > 0 → "ไม่มีค่าปรับ"
   5.4 findPaymentByBorrowId(borrowId) → ตรวจว่าชำระแล้วหรือยัง
       → ถ้ามี → "ชำระค่าปรับแล้ว"
   5.5 PaymentRepository::create(borrowId, fineAmount, staffId)
       → UNIQUE constraint บน borrow_id
   5.6 COMMIT
6. บันทึก idempotency key
7. redirect /admin/payments.php + flash
```

### 7) Expected Results

**Success:**
- HTTP: `302 → /admin/payments.php`
- Flash (success): "รับชำระค่าปรับ X บาท เรียบร้อยแล้ว"
- DB: `payments` +1 row (borrow_id, amount, recorded_by=staffId)

### 8) Failure Paths

| Condition | HTTP | Response |
|-----------|------|----------|
| ไม่ใช่ staff | 302 → login.php | — |
| CSRF invalid | 302 → payments.php | flash error |
| ไม่พบ borrow | 302 | "ไม่พบรายการยืม" |
| fine_amount = 0 | 302 | "รายการนี้ไม่มีค่าปรับ" |
| ชำระแล้ว (app check) | 302 | "รายการนี้ชำระค่าปรับแล้ว" |
| ชำระซ้ำ (DB UNIQUE) | 302 | PDO Exception → error |

### 9) Edge Cases

| Case | พฤติกรรมที่คาดหวัง |
|------|-------------------|
| **Double submit** | idempotency key → "รายการนี้ถูกบันทึกไปแล้ว" |
| **2 staff กดชำระพร้อมกัน** | FOR UPDATE → คนที่ 2 รอ → findPayment พบ → "ชำระแล้ว" |
| **UNIQUE constraint (DB level)** | ถ้า app check พลาด → DB ดักไว้ → INSERT fail |
| **Borrow ที่ยังไม่ได้คืน** | ยังชำระได้ (ใช้ AnyStatus lock) — แต่ปกติไม่ควรเกิด |

---

## Flow 10: Create Book

### 1) Flow Name
**Create Book (เพิ่มหนังสือ)**

### 2) Goal
Staff เพิ่มหนังสือใหม่เข้าระบบ พร้อม upload รูปปก (optional)

### 3) Preconditions
- **Login state:** login เป็น staff/admin
- **Database state:** ISBN (ถ้ากรอก) ต้องไม่ซ้ำ

### 4) Trigger
- **Endpoint:** `POST /admin/book_form.php` (ไม่มี ?id)
- **Method:** POST
- **Encoding:** `multipart/form-data` (เพราะมี file upload)

### 5) Inputs

| Parameter | Source | Required | Validation |
|-----------|--------|----------|------------|
| `csrf_token` | POST body | ✅ | ต้องตรง |
| `title` | POST body | ✅ | ไม่ว่าง, ≤200 chars |
| `author` | POST body | ✅ | ไม่ว่าง, ≤100 chars |
| `isbn` | POST body | ❌ | unique ถ้ากรอก |
| `category_id` | POST body | ❌ | int, must exist |
| `quantity` | POST body | ✅ | int ≥ 1 |
| `description` | POST body | ❌ | text |
| `cover_image` | FILE upload | ❌ | JPEG/PNG/GIF/WEBP, ≤2MB |

### 6) Steps

```
1. POST /admin/book_form.php (multipart/form-data)
2. requireStaff()
3. validateCSRFToken()
4. Validate inputs (title, author, quantity)
5. ถ้ามี file upload:
   5.1 finfo_file() ตรวจ MIME จาก content (ไม่ใช่ $_FILES['type'])
   5.2 ตรวจ size ≤ 2MB
   5.3 สร้างชื่อใหม่: cover_{timestamp}_{uniqid}.{ext}
   5.4 move_uploaded_file() → uploads/covers/
6. BookService::createBook(data)
   → BookRepository::create() → available = quantity
7. redirect /admin/books.php + flash
```

### 7) Expected Results

**Success:**
- HTTP: `302 → /admin/books.php`
- Flash (success): "เพิ่มหนังสือสำเร็จ"
- DB: `books` +1 row (`available = quantity`)
- File: `uploads/covers/cover_*.ext` (ถ้ามี upload)

### 8) Failure Paths

| Condition | HTTP | Response |
|-----------|------|----------|
| ไม่ใช่ staff | 302 → login.php | — |
| CSRF invalid | 302 | flash error |
| Title ว่าง | 200 | error "กรุณากรอกชื่อหนังสือ" |
| ISBN ซ้ำ | 200 | error "ISBN นี้มีในระบบแล้ว" |
| File > 2MB | 200 | error "ไฟล์ใหญ่เกินไป" |
| File MIME ไม่ใช่รูป | 200 | error "ไฟล์ต้องเป็นรูปภาพ" |

### 9) Edge Cases

| Case | พฤติกรรมที่คาดหวัง |
|------|-------------------|
| **Upload ไฟล์ .php เปลี่ยนนามสกุลเป็น .jpg** | finfo ตรวจ content → ไม่ใช่รูป → reject |
| **Quantity = 0** | ≥ 1 validation fail |
| **ISBN ว่าง** | อนุญาต (optional field) |
| **Submit ซ้ำ** | สร้างหนังสือซ้ำ (ไม่มี idempotency — แต่ ISBN unique ดัก) |

---

## Flow 11: Delete Book

### 1) Flow Name
**Delete Book (ลบหนังสือ)**

### 2) Goal
Staff ลบหนังสือออกจากระบบ (ต้องผ่าน 3 integrity checks)

### 3) Preconditions
- **Login state:** login เป็น staff/admin
- **Database state:**
  - หนังสือต้องไม่มีคนยืมอยู่ (status='borrowing')
  - หนังสือต้องไม่มีประวัติการยืมใดๆ
  - หนังสือต้องไม่มี pending reservation

### 4) Trigger
- **Endpoint:** `POST /admin/books.php`
- **Method:** POST (action=delete)

### 5) Inputs

| Parameter | Source | Required | Validation |
|-----------|--------|----------|------------|
| `csrf_token` | POST body | ✅ | ต้องตรง |
| `action` | POST body | ✅ | = 'delete' |
| `id` | POST body | ✅ | int > 0 |

### 6) Steps

```
1. POST /admin/books.php ด้วย action=delete, id, csrf_token
2. requireStaff()
3. validateCSRFToken()
4. BookService::deleteBook(id)
   4.1 BEGIN TRANSACTION
   4.2 findByIdForUpdate(id) → lock row
   4.3 Guard #1: isBeingBorrowed(id) → มีคนยืมอยู่ไหม
   4.4 Guard #2: hasBorrowHistory(id) → มีประวัติการยืมไหม
   4.5 Guard #3: countPendingByBook(id) > 0 → มีจอง pending ไหม
   4.6 BookRepository::delete(id) → DELETE
   4.7 COMMIT
   4.8 ลบรูปปกจาก disk (หลัง commit)
5. redirect /admin/books.php + flash
```

### 7) Expected Results

**Success:**
- HTTP: `302 → /admin/books.php`
- Flash (success): "ลบหนังสือสำเร็จ"
- DB: `books` row ถูกลบ
- File: รูปปกถูกลบจาก `uploads/covers/` (ถ้ามี)

### 8) Failure Paths

| Condition | HTTP | Response |
|-----------|------|----------|
| ไม่ใช่ staff | 302 → login.php | — |
| CSRF invalid | 302 → books.php | flash error |
| ไม่พบหนังสือ | 302 | "ไม่พบหนังสือที่ต้องการลบ" |
| มีคนยืมอยู่ | 302 | "ไม่สามารถลบได้ หนังสือเล่มนี้กำลังถูกยืมอยู่" |
| มีประวัติการยืม | 302 | "ไม่สามารถลบได้ หนังสือเล่มนี้มีประวัติการยืม" |
| มี pending reservation | 302 | "ไม่สามารถลบได้ มีการจองที่รอดำเนินการอยู่" |

### 9) Edge Cases

| Case | พฤติกรรมที่คาดหวัง |
|------|-------------------|
| **ลบหนังสือที่เคยยืมแล้วคืนหมดแล้ว** | hasBorrowHistory = true → ลบไม่ได้ (เก็บประวัติ) |
| **ลบหนังสือที่ไม่เคยถูกยืม + ไม่มีจอง** | ลบสำเร็จ |
| **Concurrent delete** | FOR UPDATE → คนที่ 2 ได้ null → "ไม่พบหนังสือ" |
| **Cover file ถูกลบหลัง commit** | ป้องกัน orphan file — ถ้า DB rollback ไฟล์ยังอยู่ |

---

## Flow 12: Search Books (API)

### 1) Flow Name
**Search Books API (ค้นหาหนังสือ)**

### 2) Goal
ค้นหาหนังสือด้วย keyword/category/status → return HTML partial สำหรับ AJAX update

### 3) Preconditions
- **Login state:** ไม่ต้อง login (public API)
- **Database state:** ไม่มีเงื่อนไขพิเศษ

### 4) Trigger
- **Endpoint:** `GET /api/search_books.php`
- **Method:** GET
- **Response format:** HTML partial (ไม่ใช่ JSON)

### 5) Inputs

| Parameter | Source | Required | Validation |
|-----------|--------|----------|------------|
| `search` | Query string | ❌ | string, trimmed |
| `category` | Query string | ❌ | int (category_id) |
| `status` | Query string | ❌ | 'available' only |

- **ไม่มี CSRF** (GET request, ไม่มี state change)
- **Rate limit:** 60 requests / 5 นาที

### 6) Steps

```
1. GET /api/search_books.php?search=xxx&category=1&status=available
2. ตรวจ method = GET → 405 ถ้าไม่
3. checkRateLimit('search_books', 60, 5) → 429 ถ้าเกิน
4. รับ + trim search, category, status
5. สร้าง filters array
6. BookRepository::findAll(filters) → SQL query
7. render HTML partial (book_grid.php)
```

### 7) Expected Results

**Success:**
- HTTP: `200`
- Content-Type: `text/html; charset=utf-8`
- Body: HTML partial ของ book cards (ใช้ inject เข้า DOM)

**ไม่พบผลลัพธ์:**
- HTTP: `200`
- Body: HTML ที่แสดงข้อความ "ไม่พบหนังสือ" หรือ grid ว่าง

### 8) Failure Paths

| Condition | HTTP | Response |
|-----------|------|----------|
| Method ≠ GET | 405 | (empty) |
| Rate limit exceeded | 429 | HTML: "Too many requests. Please wait." |

### 9) Edge Cases

| Case | พฤติกรรมที่คาดหวัง |
|------|-------------------|
| **search ว่าง + ไม่มี filter** | return ทุกเล่ม |
| **search มี SQL injection** | Prepared statement ป้องกัน → ไม่พบผลลัพธ์ |
| **Rate limit 61 ครั้งใน 5 นาที** | ครั้งที่ 61 ได้ 429 |
| **Category ไม่มีอยู่** | return ว่าง (ไม่ error) |
| **XSS ใน search** | BookRepository ใช้ prepared stmt, output ใช้ e() escape |

---

## ภาคผนวก

### A. สรุป Security Checks ทุก Flow

| Flow | Auth | CSRF | Rate Limit | Idempotency | Row Lock |
|------|------|------|------------|-------------|----------|
| Login | ❌ | ❌ | ✅ (per email) | ❌ | ❌ |
| Register | ❌ | ✅ | ✅ (global) | ❌ | ❌ |
| Create Borrow | staff | ✅ | ❌ | ✅ | ✅ (user+books) |
| Return Book | staff | ✅ | ❌ | ✅ | ✅ (borrow) |
| Reserve Book | login | ✅ | ❌ | ❌ | ✅ (book) |
| Cancel Reservation (User) | login | ✅ | ❌ | ❌ | ✅ (reservation) |
| Fulfill Reservation | staff | ✅ | ❌ | ✅ | ✅ (reservation) |
| Cancel Reservation (Admin) | staff | ✅ | ❌ | ✅ | ✅ (reservation) |
| Pay Fine | staff | ✅ | ❌ | ✅ | ✅ (borrow) |
| Create Book | staff | ✅ | ❌ | ❌ | ❌ |
| Delete Book | staff | ✅ | ❌ | ❌ | ✅ (book) |
| Search Books | ❌ | ❌ | ✅ (IP) | ❌ | ❌ |

### B. Stock Lifecycle (ตรวจสอบ available balance)

```
สร้างหนังสือ:   available = quantity
ยืม:            available - 1  (BorrowService::createBorrow)
คืน:            available + 1  (BorrowService::returnBook)
จอง:            available - 1  (ReservationService::createReservation)
อนุมัติจอง:     ไม่เปลี่ยน     (stock หักไปตอนจอง)
ยกเลิกจอง:      available + 1  (ReservationService::cancelReservation)
จองหมดอายุ:     available + 1  (ReservationRepository::expireOverdue)
```

**Invariant:** `books.available >= 0` ตลอดเวลา  
**Guard:** `decrementAvailable()` มี `WHERE available > 0` (atomic)

### C. วิธีเตรียม Test Data

```sql
-- สร้าง test member
INSERT INTO users (name, email, password, role)
VALUES ('Test Member', 'test@test.com', '$2y$10$...bcrypt_hash...', 'member');

-- สร้าง test book (available = 1 สำหรับทดสอบ concurrent)
INSERT INTO books (title, author, quantity, available)
VALUES ('Test Book', 'Author', 1, 1);

-- สร้าง overdue borrow (สำหรับทดสอบค่าปรับ)
INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status)
VALUES (1, 1, DATE_SUB(NOW(), INTERVAL 14 DAY), DATE_SUB(NOW(), INTERVAL 7 DAY), 'borrowing');
```

### D. curl Quick Reference

```bash
# Login (เก็บ cookies)
curl -X POST "http://localhost/book_borrowing/login.php" \
  -d "email=admin@library.com&password=123456" \
  -c cookies.txt -L -v

# Search (public, GET)
curl "http://localhost/book_borrowing/api/search_books.php?search=php"

# Reserve (ต้อง login + CSRF)
curl -X POST "http://localhost/book_borrowing/api/reserve_book.php" \
  -d "book_id=1&csrf_token=TOKEN" \
  -b cookies.txt

# ดู member history (staff only)
curl "http://localhost/book_borrowing/api/member_history.php?id=1" \
  -b cookies.txt
```

---

*เอกสารนี้อ้างอิงจากโค้ดจริงทั้งหมด — ไม่มีการเดาหรือเสนอ feature ใหม่*
