# Flow Test Guide V2 - คู่มือทดสอบ Flow ระบบยืมคืนหนังสือ

เอกสารนี้ใช้สำหรับ **Manual Testing** และเป็นฐานสำหรับเขียน **Automated Tests**

---

## สารบัญ

1. [User Login](#flow-1-user-login)
2. [User Registration](#flow-2-user-registration)
3. [Create Borrow](#flow-3-create-borrow-ยืมหนังสือ)
4. [Return Book](#flow-4-return-book-คืนหนังสือ)
5. [Pay Fine](#flow-5-pay-fine-ชำระค่าปรับ)
6. [Create Reservation](#flow-6-create-reservation-จองหนังสือ)
7. [Fulfill Reservation](#flow-7-fulfill-reservation-อนุมัติการจอง)
8. [Cancel Reservation](#flow-8-cancel-reservation-ยกเลิกการจอง)
9. [Create Book](#flow-9-create-book-เพิ่มหนังสือ)
10. [Update Book](#flow-10-update-book-แก้ไขหนังสือ)
11. [Delete Book](#flow-11-delete-book-ลบหนังสือ)
12. [Quick Add Member](#flow-12-quick-add-member-api)

---

## Flow 1: User Login

### 1) Flow Name
**User Login** - เข้าสู่ระบบ

### 2) Goal
ให้ผู้ใช้สามารถ authenticate ด้วย email/password และสร้าง session เพื่อเข้าใช้งานระบบ

### 3) Preconditions

| Condition | Required State |
|-----------|----------------|
| Login State | ต้อง **ไม่ได้ login** (ถ้า login อยู่จะ redirect ไป index.php) |
| Database | ต้องมี user record ใน `users` table ที่ตรงกับ email |

### 4) Trigger

| Item | Value |
|------|-------|
| Endpoint | `/login.php` |
| Method | `POST` |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `email` | string | Yes | ไม่ว่าง |
| `password` | string | Yes | ไม่ว่าง |

**Headers/Session:**
- Session ต้องเริ่มต้นแล้ว (ผ่าน `bootstrap.php`)
- ไม่ต้องใช้ CSRF token

### 6) Steps

```
1. User เปิดหน้า GET /login.php
2. User กรอก email และ password
3. User กด Submit (POST /login.php)
4. System ตรวจ rate limit (checkRateLimit)
5. System validate input (ไม่ว่าง)
6. System เรียก AuthService::login($email, $password)
   - UserRepository::findByEmail($email)
   - password_verify($password, $user['password'])
7. ถ้าสำเร็จ:
   - resetRateLimit()
   - session_regenerate_id(true)
   - เก็บ user_id, user_name, role ใน $_SESSION
   - setFlash('success', ...)
   - redirect ตาม role (admin/staff → /admin/, member → /index.php)
8. ถ้าไม่สำเร็จ:
   - incrementRateLimit()
   - แสดง error "อีเมลหรือรหัสผ่านไม่ถูกต้อง"
```

### 7) Expected Results

**Success Case:**

| Item | Expected |
|------|----------|
| HTTP Status | 302 Redirect |
| Redirect To | `/admin/` (staff/admin) หรือ `/index.php` (member) |
| Session | `$_SESSION['user_id']`, `$_SESSION['role']` ถูกตั้งค่า |
| Flash Message | "เข้าสู่ระบบสำเร็จ ยินดีต้อนรับ {name}" |
| DB Changes | ไม่มี |

### 8) Failure Paths

| Failure | Trigger | Response |
|---------|---------|----------|
| Empty email | `email = ""` | Error: "กรุณากรอกอีเมล" |
| Empty password | `password = ""` | Error: "กรุณากรอกรหัสผ่าน" |
| Wrong email | Email ไม่มีในระบบ | Error: "อีเมลหรือรหัสผ่านไม่ถูกต้อง" |
| Wrong password | Password ไม่ตรง | Error: "อีเมลหรือรหัสผ่านไม่ถูกต้อง" |
| Rate limit | > 5 attempts ใน 15 นาที | Error: "ลองผิดหลายครั้งเกินไป กรุณารอ 15 นาที" |

### 9) Edge Cases

| Case | Behavior |
|------|----------|
| **Already logged in** | GET /login.php → redirect ไป index.php ทันที |
| **Multi-tab** | Tab หนึ่ง login สำเร็จ, tab อื่น refresh → redirect ไป index |
| **Session hijack** | session_regenerate_id(true) ป้องกัน session fixation |
| **Rate limit per email** | ใช้ key `login_` + md5(email) นับแยกแต่ละ email |

---

## Flow 2: User Registration

### 1) Flow Name
**User Registration** - สมัครสมาชิก

### 2) Goal
ให้ผู้ใช้ใหม่สามารถสร้าง account เป็น member เพื่อยืมหนังสือ

### 3) Preconditions

| Condition | Required State |
|-----------|----------------|
| Login State | ต้อง **ไม่ได้ login** |
| Database | Email ที่สมัครต้อง **ไม่ซ้ำ** กับที่มีอยู่ |

### 4) Trigger

| Item | Value |
|------|-------|
| Endpoint | `/register.php` |
| Method | `POST` |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `name` | string | Yes | ไม่ว่าง, ≤100 chars |
| `email` | string | Yes | valid email format |
| `password` | string | Yes | ≥6 chars |
| `confirm_password` | string | Yes | = password |
| `phone` | string | No | 9-10 digits (ถ้ากรอก) |

### 6) Steps

```
1. User เปิดหน้า GET /register.php
2. User กรอกข้อมูล
3. User กด Submit (POST /register.php)
4. System ตรวจ rate limit (global key "register")
5. incrementRateLimit() ก่อน validate
6. System validate ทุก field
7. System เรียก AuthService::register($data)
   - ตรวจ email duplicate
   - password_hash($password, PASSWORD_DEFAULT)
   - UserRepository::create() with role='member'
8. ถ้าสำเร็จ:
   - setFlash('success', 'สมัครสมาชิกสำเร็จ กรุณาเข้าสู่ระบบ')
   - redirect ไป /login.php
9. ถ้าไม่สำเร็จ:
   - แสดง error และ repopulate form
```

### 7) Expected Results

**Success Case:**

| Item | Expected |
|------|----------|
| HTTP Status | 302 Redirect |
| Redirect To | `/login.php` |
| DB Changes | INSERT 1 row ใน `users` (role='member') |
| Flash Message | "สมัครสมาชิกสำเร็จ กรุณาเข้าสู่ระบบ" |

**Database Record:**
```sql
INSERT INTO users (name, email, password, phone, role)
VALUES ('...', '...', '$2y$...', '...', 'member')
```

### 8) Failure Paths

| Failure | Trigger | Response |
|---------|---------|----------|
| Empty name | `name = ""` | Error: "กรุณากรอกชื่อ-นามสกุล" |
| Name too long | > 100 chars | Error: "ชื่อต้องไม่เกิน 100 ตัวอักษร" |
| Invalid email | `email = "abc"` | Error: "รูปแบบอีเมลไม่ถูกต้อง" |
| Email exists | ซ้ำกับที่มีอยู่ | Error: "อีเมลนี้ถูกใช้งานแล้ว" |
| Short password | < 6 chars | Error: "รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร" |
| Password mismatch | confirm ≠ password | Error: "รหัสผ่านไม่ตรงกัน" |
| Invalid phone | ไม่ใช่ 9-10 digits | Error: "เบอร์โทรต้องเป็นตัวเลข 9-10 หลัก" |
| Rate limit | Global limit exceeded | Error: "ลองหลายครั้งเกินไป..." |

### 9) Edge Cases

| Case | Behavior |
|------|----------|
| **Duplicate email (race)** | DB UNIQUE constraint จะ throw exception |
| **Rate limit global** | ใช้ global key เพราะ attacker สามารถใช้ email ใหม่ทุกครั้ง |
| **Already logged in** | redirect ไป index.php ทันที |

---

## Flow 3: Create Borrow (ยืมหนังสือ)

### 1) Flow Name
**Create Borrow** - บันทึกการยืมหนังสือ

### 2) Goal
Staff บันทึกการยืมหนังสือให้ member โดยลด stock และสร้าง borrow record

### 3) Preconditions

| Condition | Required State |
|-----------|----------------|
| Login State | Login เป็น **staff** หรือ **admin** |
| User | user_id ที่เลือกต้องเป็น role='member' |
| Books | หนังสือที่เลือกต้องมี `available > 0` |
| Quota | User ต้องยืมอยู่ < MAX_BORROW_BOOKS (3) |

### 4) Trigger

| Item | Value |
|------|-------|
| Endpoint | `/admin/borrow_form.php` |
| Method | `POST` |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `csrf_token` | string | Yes | ต้องตรงกับ session |
| `user_id` | int | Yes | > 0, must be member |
| `book_ids[]` | array | Yes | ไม่ว่าง, แต่ละ id > 0 |
| `borrow_days` | int | No | 1-30 (default: 7) |

### 6) Steps

```
1. Staff เปิดหน้า GET /admin/borrow_form.php
2. Staff เลือก user และ books
3. Staff กด Submit (POST)
4. System ตรวจ CSRF token
5. System validate inputs
6. System ตรวจ idempotency key (ป้องกัน double submit)
7. System เรียก BorrowService::createBorrow()
   BEGIN TRANSACTION
   - Lock user row (FOR UPDATE)
   - ตรวจ quota: countActiveBorrowsForUpdate($userId)
   - Loop แต่ละ book:
     - Lock book row (FOR UPDATE)
     - ตรวจ available > 0
     - ตรวจ ยังไม่ยืมเล่มนี้อยู่
     - decrementAvailable($bookId) WHERE available > 0
     - INSERT INTO borrows
   COMMIT
8. บันทึก idempotency key ใน session
9. setFlash + redirect ไป borrows.php
```

### 7) Expected Results

**Success Case:**

| Item | Expected |
|------|----------|
| HTTP Status | 302 Redirect |
| Redirect To | `/admin/borrows.php` |
| DB Changes | INSERT `borrows` (per book), UPDATE `books.available` - 1 |
| Flash Message | "บันทึกการยืมสำเร็จ X เล่ม \| กำหนดคืน: dd/mm/yyyy" |

**Borrow Record:**
```sql
INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status)
VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'borrowing')
```

### 8) Failure Paths

| Failure | Trigger | Response |
|---------|---------|----------|
| Invalid CSRF | Token ไม่ตรง | Error + redirect |
| No user selected | `user_id = 0` | Error: "กรุณาเลือกผู้ยืม" |
| No books selected | `book_ids` ว่าง | Error: "กรุณาเลือกหนังสืออย่างน้อย 1 เล่ม" |
| Invalid days | < 1 หรือ > 30 | Error: "จำนวนวันยืมต้องอยู่ระหว่าง 1-30 วัน" |
| User not member | role ≠ 'member' | Error: "ไม่พบสมาชิกที่เลือก" |
| Quota exceeded | current + new > 3 | Error: "ผู้ยืมถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว" |
| Book out of stock | available = 0 | Skipped with reason in message |
| Already borrowing | มี active borrow อยู่ | Skipped with reason |

### 9) Edge Cases

| Case | Behavior |
|------|----------|
| **Double submit** | Idempotency key ป้องกัน - redirect พร้อม info message |
| **Concurrent borrow** | FOR UPDATE lock ป้องกัน race condition |
| **Stock goes 0 mid-tx** | `WHERE available > 0` ใน decrementAvailable - book ถูก skip |
| **Partial success** | บาง books ยืมได้ บางเล่ม skip - รายงานแยก |

---

## Flow 4: Return Book (คืนหนังสือ)

### 1) Flow Name
**Return Book** - บันทึกการคืนหนังสือ

### 2) Goal
Staff บันทึกการคืนหนังสือ คำนวณค่าปรับ (ถ้าเกินกำหนด) และคืน stock

### 3) Preconditions

| Condition | Required State |
|-----------|----------------|
| Login State | Login เป็น **staff** หรือ **admin** |
| Borrow | borrow record ต้อง status='borrowing' |

### 4) Trigger

| Item | Value |
|------|-------|
| Endpoint | `/admin/borrows.php` |
| Method | `POST` |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `csrf_token` | string | Yes | ต้องตรง |
| `action` | string | Yes | = 'return' |
| `borrow_id` | int | Yes | > 0 |
| `pay_now` | checkbox | No | ถ้ามี = รับชำระทันที |

### 6) Steps

```
1. Staff เปิดหน้า borrows.php (GET)
2. Staff กดปุ่ม "คืน" → เปิด modal ยืนยัน
3. Staff กดยืนยัน (POST)
4. System ตรวจ CSRF token
5. System ตรวจ idempotency key
6. System เรียก BorrowService::returnBook()
   BEGIN TRANSACTION
   - Lock borrow row (findByIdForUpdate)
   - ตรวจ status = 'borrowing'
   - calculateFine(due_date, today)
   - UPDATE borrows SET status='returned', return_date, fine_amount
   - UPDATE books SET available = available + 1
   - ถ้า pay_now && fine > 0: INSERT INTO payments
   COMMIT
7. บันทึก idempotency key
8. setFlash + redirect
```

### 7) Expected Results

**Success Case (no fine):**

| Item | Expected |
|------|----------|
| HTTP Status | 302 Redirect |
| DB Changes | UPDATE `borrows`, UPDATE `books.available` + 1 |
| Flash | "บันทึกการคืนหนังสือสำเร็จ" |

**Success Case (with fine, pay now):**

| Item | Expected |
|------|----------|
| DB Changes | + INSERT `payments` |
| Flash | "...ค่าปรับ: X บาท (เกิน Y วัน) [รับชำระเงินแล้ว]" |

**Fine Calculation:**
```
fine_amount = days_overdue × FINE_PER_DAY (default: 10)
days_overdue = DATEDIFF(CURDATE(), due_date)
```

### 8) Failure Paths

| Failure | Trigger | Response |
|---------|---------|----------|
| Invalid CSRF | Token ไม่ตรง | Error + redirect |
| Borrow not found | borrow_id ไม่มี | Error: "ไม่พบรายการยืมหรือคืนหนังสือแล้ว" |
| Already returned | status = 'returned' | Error: "ไม่พบรายการยืมหรือคืนหนังสือแล้ว" |

### 9) Edge Cases

| Case | Behavior |
|------|----------|
| **Double submit** | Idempotency key ป้องกัน |
| **Concurrent return** | FOR UPDATE lock - คนที่ 2 ได้ error |
| **Fine but don't pay** | บันทึก fine_amount แต่ไม่สร้าง payment - ค้างชำระ |

---

## Flow 5: Pay Fine (ชำระค่าปรับ)

### 1) Flow Name
**Pay Fine** - รับชำระค่าปรับทีหลัง

### 2) Goal
Staff รับชำระค่าปรับสำหรับรายการที่คืนแล้วแต่ยังไม่ได้จ่าย

### 3) Preconditions

| Condition | Required State |
|-----------|----------------|
| Login State | Login เป็น **staff** หรือ **admin** |
| Borrow | `fine_amount > 0` และ ยังไม่มี payment record |

### 4) Trigger

| Item | Value |
|------|-------|
| Endpoint | `/admin/payments.php` |
| Method | `POST` |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `csrf_token` | string | Yes | ต้องตรง |
| `action` | string | Yes | = 'pay_fine' |
| `borrow_id` | int | Yes | > 0 |

### 6) Steps

```
1. Staff เปิดหน้า payments.php (GET)
2. Staff เห็นรายการค้างชำระ
3. Staff กดปุ่ม "รับชำระ" → modal ยืนยัน
4. Staff กดยืนยัน (POST)
5. System ตรวจ CSRF + idempotency
6. System เรียก BorrowService::payFine()
   BEGIN TRANSACTION
   - Lock borrow row (findByIdForUpdateAnyStatus)
   - ตรวจ fine_amount > 0
   - ตรวจว่ายังไม่มี payment (findByBorrowId)
   - INSERT INTO payments (borrow_id, amount, recorded_by)
   COMMIT
7. บันทึก idempotency key
8. setFlash('success', 'รับชำระค่าปรับ X บาท เรียบร้อยแล้ว')
```

### 7) Expected Results

| Item | Expected |
|------|----------|
| HTTP Status | 302 Redirect |
| DB Changes | INSERT 1 row ใน `payments` |
| Flash | "รับชำระค่าปรับ X บาท เรียบร้อยแล้ว" |

### 8) Failure Paths

| Failure | Trigger | Response |
|---------|---------|----------|
| Borrow not found | borrow_id ไม่มี | Error: "ไม่พบรายการยืม" |
| No fine | fine_amount = 0 | Error: "รายการนี้ไม่มีค่าปรับ" |
| Already paid | มี payment แล้ว | Error: "รายการนี้ชำระค่าปรับแล้ว" |

### 9) Edge Cases

| Case | Behavior |
|------|----------|
| **Double pay** | FOR UPDATE + check existing payment ป้องกัน |
| **Concurrent pay** | Lock ป้องกัน race - คนที่ 2 ได้ error |

---

## Flow 6: Create Reservation (จองหนังสือ)

### 1) Flow Name
**Create Reservation** - จองหนังสือ (สำหรับ member)

### 2) Goal
Member จองหนังสือเพื่อมารับทีหลัง โดย stock ถูกกันไว้ทันที

### 3) Preconditions

| Condition | Required State |
|-----------|----------------|
| Login State | Login เป็น **member** (หรือ role อื่นก็ได้) |
| Book | `available > 0` |
| Existing | ต้อง **ไม่มี** pending reservation เล่มเดียวกัน |

### 4) Trigger

| Item | Value |
|------|-------|
| Endpoint | `/api/reserve_book.php` |
| Method | `POST` |
| Response | JSON |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `csrf_token` | string | Yes | ต้องตรง |
| `book_id` | int | Yes | > 0 |

**Note:** `user_id` มาจาก `$_SESSION['user_id']` เท่านั้น (ไม่รับจาก POST)

### 6) Steps

```
1. User อยู่หน้า book detail
2. User กดปุ่ม "จอง" (AJAX POST)
3. System ตรวจ isLoggedIn()
4. System ตรวจ method = POST
5. System ตรวจ CSRF token
6. System validate book_id > 0
7. System เรียก ReservationService::createReservation()
   BEGIN TRANSACTION
   - ตรวจ hasPending($userId, $bookId)
   - Lock book row (FOR UPDATE)
   - ตรวจ available > 0
   - INSERT INTO reservations (status='pending', expires_at=+2 days)
   - UPDATE books SET available = available - 1
   COMMIT
8. Return JSON response
```

### 7) Expected Results

**Success Case:**

```json
{
  "success": true,
  "message": "จองสำเร็จ! กรุณามารับหนังสือ \"...\" ภายในวันที่ dd/mm/yyyy"
}
```

| Item | Expected |
|------|----------|
| HTTP Status | 200 |
| DB Changes | INSERT `reservations`, UPDATE `books.available` - 1 |

### 8) Failure Paths

| Failure | HTTP | Response |
|---------|------|----------|
| Not logged in | 401 | `{"success": false, "message": "กรุณาเข้าสู่ระบบ..."}` |
| Invalid method | 405 | `{"success": false, "message": "Method not allowed"}` |
| Invalid CSRF | 403 | `{"success": false, "message": "Invalid token"}` |
| Invalid book_id | 400 | `{"success": false, "message": "ข้อมูลไม่ถูกต้อง"}` |
| Already reserved | 400 | `{"success": false, "message": "คุณได้จองหนังสือเล่มนี้ไว้แล้ว..."}` |
| Out of stock | 400 | `{"success": false, "message": "หนังสือหมด ไม่สามารถจองได้"}` |
| Book not found | 400 | `{"success": false, "message": "ไม่พบหนังสือ"}` |

### 9) Edge Cases

| Case | Behavior |
|------|----------|
| **Concurrent reserve last copy** | FOR UPDATE lock - คนที่ 2 ได้ "หนังสือหมด" |
| **Reserve same book twice** | hasPending check ป้องกัน |
| **Stock already 0** | Error ก่อน lock จะผ่านไม่ได้ |

---

## Flow 7: Fulfill Reservation (อนุมัติการจอง)

### 1) Flow Name
**Fulfill Reservation** - อนุมัติการจอง → สร้าง borrow

### 2) Goal
Staff อนุมัติการจองและสร้าง borrow record อัตโนมัติ

### 3) Preconditions

| Condition | Required State |
|-----------|----------------|
| Login State | Login เป็น **staff** หรือ **admin** |
| Reservation | status = 'pending' |
| User Quota | user ต้องยืมอยู่ < MAX_BORROW_BOOKS |

### 4) Trigger

| Item | Value |
|------|-------|
| Endpoint | `/admin/reservations.php` |
| Method | `POST` |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `csrf_token` | string | Yes | ต้องตรง |
| `action` | string | Yes | = 'approve' |
| `id` | int | Yes | reservation_id > 0 |

### 6) Steps

```
1. Staff เปิดหน้า reservations.php
2. Staff กดปุ่ม "อนุมัติ"
3. System ตรวจ CSRF + idempotency
4. System เรียก ReservationService::fulfillReservation()
   BEGIN TRANSACTION
   - Lock reservation row (findPendingForUpdate)
   - ตรวจ status = 'pending'
   - ตรวจ user quota
   - INSERT INTO borrows
   - UPDATE reservations SET status='fulfilled', borrow_id=...
   COMMIT
5. setFlash + redirect
```

### 7) Expected Results

| Item | Expected |
|------|----------|
| HTTP Status | 302 Redirect |
| DB Changes | INSERT `borrows`, UPDATE `reservations` (status='fulfilled') |
| Flash | "อนุมัติการจองสำเร็จ! สร้างรายการยืมแล้ว กำหนดคืน: ..." |

**Note:** ไม่ต้อง update books.available เพราะหักไปแล้วตอนจอง

### 8) Failure Paths

| Failure | Trigger | Response |
|---------|---------|----------|
| Not pending | status ≠ 'pending' | Error: "ไม่พบรายการจองหรือไม่อยู่ในสถานะรอรับ" |
| Quota exceeded | user ยืมครบ 3 เล่มแล้ว | Error: "ผู้จองถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว" |

### 9) Edge Cases

| Case | Behavior |
|------|----------|
| **Double approve** | Idempotency + status check ป้องกัน |
| **Concurrent approve** | FOR UPDATE lock ป้องกัน |

---

## Flow 8: Cancel Reservation (ยกเลิกการจอง)

### 1) Flow Name
**Cancel Reservation** - ยกเลิกการจอง + คืน stock

### 2) Goal
Staff ยกเลิกการจองและคืน stock กลับ

### 3) Preconditions

| Condition | Required State |
|-----------|----------------|
| Login State | Login เป็น **staff** หรือ **admin** |
| Reservation | status = 'pending' |

### 4) Trigger

| Item | Value |
|------|-------|
| Endpoint | `/admin/reservations.php` |
| Method | `POST` |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `csrf_token` | string | Yes | ต้องตรง |
| `action` | string | Yes | = 'cancel' |
| `id` | int | Yes | reservation_id > 0 |

### 6) Steps

```
1. Staff กดปุ่ม "ยกเลิก" บนหน้า reservations.php
2. System ตรวจ CSRF + idempotency
3. System เรียก ReservationService::cancelReservation()
   BEGIN TRANSACTION
   - Lock reservation row
   - ตรวจ status = 'pending'
   - UPDATE reservations SET status='cancelled'
   - UPDATE books SET available = available + 1
   COMMIT
4. setFlash + redirect
```

### 7) Expected Results

| Item | Expected |
|------|----------|
| HTTP Status | 302 Redirect |
| DB Changes | UPDATE `reservations` (status='cancelled'), UPDATE `books.available` + 1 |
| Flash | "ยกเลิกการจองและคืนสต็อกหนังสือเรียบร้อยแล้ว" |

### 8) Failure Paths

| Failure | Trigger | Response |
|---------|---------|----------|
| Not pending | status ≠ 'pending' | Error: "ไม่พบรายการจองหรือยกเลิกไปแล้ว" |

### 9) Edge Cases

| Case | Behavior |
|------|----------|
| **Double cancel** | Idempotency + status check ป้องกัน |

---

## Flow 9: Create Book (เพิ่มหนังสือ)

### 1) Flow Name
**Create Book** - เพิ่มหนังสือใหม่

### 2) Goal
Staff เพิ่มหนังสือใหม่เข้าระบบ

### 3) Preconditions

| Condition | Required State |
|-----------|----------------|
| Login State | Login เป็น **staff** หรือ **admin** |
| ISBN | ถ้ากรอก ต้องไม่ซ้ำ |

### 4) Trigger

| Item | Value |
|------|-------|
| Endpoint | `/admin/book_form.php` |
| Method | `POST` |
| Encoding | `multipart/form-data` (เพราะมี file upload) |

### 5) Inputs

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

### 6) Steps

```
1. Staff เปิดหน้า GET /admin/book_form.php (ไม่มี ?id)
2. Staff กรอกข้อมูล + เลือกรูป (optional)
3. Staff กด Submit (POST)
4. System ตรวจ CSRF token
5. System validate ทุก field
6. ถ้ามี file upload:
   - ตรวจ MIME type จาก content (finfo)
   - ตรวจขนาด ≤ 2MB
   - สร้างชื่อไฟล์ใหม่ (cover_timestamp_uniqid.ext)
   - move_uploaded_file
7. System เรียก BookRepository::create()
   INSERT INTO books (title, author, isbn, category_id, description, cover_image, quantity, available)
   -- available = quantity
8. setFlash + redirect ไป books.php
```

### 7) Expected Results

| Item | Expected |
|------|----------|
| HTTP Status | 302 Redirect |
| Redirect To | `/admin/books.php` |
| DB Changes | INSERT 1 row ใน `books` |
| File | `uploads/covers/cover_*.*` (ถ้า upload) |
| Flash | "เพิ่มหนังสือสำเร็จ" |

### 8) Failure Paths

| Failure | Trigger | Response |
|---------|---------|----------|
| Empty title | `title = ""` | Error: "กรุณากรอกชื่อหนังสือ" |
| Title too long | > 200 chars | Error: "ชื่อหนังสือต้องไม่เกิน 200 ตัวอักษร" |
| Empty author | `author = ""` | Error: "กรุณากรอกชื่อผู้แต่ง" |
| ISBN exists | ซ้ำ | Error: "ISBN นี้มีในระบบแล้ว" |
| Invalid file type | PDF, exe, etc. | Error: "รองรับเฉพาะไฟล์รูปภาพ..." |
| File too large | > 2MB | Error: "ขนาดไฟล์ต้องไม่เกิน 2MB" |

### 9) Edge Cases

| Case | Behavior |
|------|----------|
| **Duplicate ISBN (race)** | isbnExists() check + DB might throw on duplicate |
| **Upload fail** | Error message, book not created |
| **No file** | ใช้ cover_image = null |

---

## Flow 10: Update Book (แก้ไขหนังสือ)

### 1) Flow Name
**Update Book** - แก้ไขข้อมูลหนังสือ

### 2) Goal
Staff แก้ไขข้อมูลหนังสือที่มีอยู่

### 3) Preconditions

| Condition | Required State |
|-----------|----------------|
| Login State | Login เป็น **staff** หรือ **admin** |
| Book | book_id ต้องมีอยู่ในระบบ |

### 4) Trigger

| Item | Value |
|------|-------|
| Endpoint | `/admin/book_form.php?id={book_id}` |
| Method | `POST` |

### 5) Inputs

เหมือน Create Book + `id` (hidden field)

### 6) Steps

```
1. Staff เปิดหน้า GET /admin/book_form.php?id=123
2. System ดึงข้อมูล book เดิม
3. Staff แก้ไขข้อมูล
4. Staff กด Submit (POST)
5. System ตรวจ CSRF + validate
6. ถ้า upload รูปใหม่ → ลบรูปเก่า
7. System เรียก BookRepository::update()
8. setFlash + redirect
```

### 7) Expected Results

| Item | Expected |
|------|----------|
| DB Changes | UPDATE `books` WHERE id = ? |
| Flash | "อัปเดตหนังสือสำเร็จ" |

**Note:** เมื่อแก้ quantity, available จะถูกปรับตาม:
```
new_available = old_available + (new_quantity - old_quantity)
```

### 8) Failure Paths

เหมือน Create Book + "ไม่พบหนังสือที่ต้องการแก้ไข"

### 9) Edge Cases

| Case | Behavior |
|------|----------|
| **Reduce quantity below borrowed** | available อาจติดลบ (ควรระวัง) |
| **Change cover image** | ลบไฟล์เก่า, upload ไฟล์ใหม่ |

---

## Flow 11: Delete Book (ลบหนังสือ)

### 1) Flow Name
**Delete Book** - ลบหนังสือ

### 2) Goal
Staff ลบหนังสือออกจากระบบ (ถ้าไม่มีการยืม)

### 3) Preconditions

| Condition | Required State |
|-----------|----------------|
| Login State | Login เป็น **staff** หรือ **admin** |
| Book | `available = quantity` (ไม่มีใครยืมอยู่) |
| History | ไม่มี borrow history |

### 4) Trigger

| Item | Value |
|------|-------|
| Endpoint | `/admin/books.php` |
| Method | `POST` |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `csrf_token` | string | Yes | ต้องตรง |
| `action` | string | Yes | = 'delete' |
| `id` | int | Yes | book_id > 0 |

### 6) Steps

```
1. Staff กดปุ่ม "ลบ" บนหน้า books.php
2. System ตรวจ CSRF token
3. System เรียก BookService::deleteBook()
   BEGIN TRANSACTION
   - Lock book row (FOR UPDATE)
   - ตรวจ available = quantity
   - ตรวจ ไม่มี borrow history
   - DELETE FROM books WHERE id = ?
   COMMIT
4. ลบไฟล์ cover_image (ถ้ามี)
5. setFlash + redirect
```

### 7) Expected Results

| Item | Expected |
|------|----------|
| DB Changes | DELETE 1 row from `books` |
| File | ลบ `uploads/covers/cover_*.*` (ถ้ามี) |
| Flash | "ลบหนังสือสำเร็จ" |

### 8) Failure Paths

| Failure | Trigger | Response |
|---------|---------|----------|
| Being borrowed | available < quantity | Error: "ไม่สามารถลบได้ หนังสือเล่มนี้กำลังถูกยืมอยู่" |
| Has history | มี rows ใน borrows | Error: "ไม่สามารถลบได้ หนังสือเล่มนี้มีประวัติการยืม" |
| Not found | book_id ไม่มี | Error: "ไม่พบหนังสือที่ต้องการลบ" |

### 9) Edge Cases

| Case | Behavior |
|------|----------|
| **Book returned during delete** | FOR UPDATE lock ป้องกัน race |
| **UI hides button** | ถ้า available ≠ quantity ปุ่มลบจะ disabled |

---

## Flow 12: Quick Add Member (API)

### 1) Flow Name
**Quick Add Member** - เพิ่มสมาชิกด่วน (AJAX)

### 2) Goal
Staff เพิ่มสมาชิกใหม่แบบรวดเร็วจากหน้า borrow_form

### 3) Preconditions

| Condition | Required State |
|-----------|----------------|
| Login State | Login เป็น **staff** หรือ **admin** |
| Email | ต้องไม่ซ้ำ |

### 4) Trigger

| Item | Value |
|------|-------|
| Endpoint | `/api/add_member.php` |
| Method | `POST` |
| Response | JSON |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `csrf_token` | string | Yes | ต้องตรง |
| `name` | string | Yes | ไม่ว่าง |
| `email` | string | Yes | valid email, unique |
| `phone` | string | No | 9-10 digits |

### 6) Steps

```
1. Staff อยู่หน้า borrow_form.php
2. Staff กดปุ่ม "เพิ่มสมาชิกใหม่" → modal
3. Staff กรอกข้อมูล + กด Submit (AJAX POST)
4. System ตรวจ method = POST
5. System ตรวจ isStaff() || isAdmin()
6. System ตรวจ CSRF token
7. System เรียก MemberService::createMember()
   - validate
   - ตรวจ email duplicate
   - สร้าง auto password
   - INSERT INTO users (role='member')
8. Return JSON response
```

### 7) Expected Results

**Success Case:**

```json
{
  "success": true,
  "message": "เพิ่มสมาชิกสำเร็จ",
  "member": {
    "id": 123,
    "name": "...",
    "email": "...",
    "phone": "..."
  }
}
```

| Item | Expected |
|------|----------|
| HTTP Status | 200 |
| DB Changes | INSERT 1 row ใน `users` |

### 8) Failure Paths

| Failure | HTTP | Response |
|---------|------|----------|
| Not staff | 403 | `{"success": false, "message": "Unauthorized"}` |
| Invalid method | 405 | `{"success": false, "message": "Method not allowed"}` |
| Invalid CSRF | 403 | `{"success": false, "message": "Invalid token"}` |
| Validation fail | 400 | `{"success": false, "message": "..."}` |
| Email exists | 400 | `{"success": false, "message": "อีเมลนี้ถูกใช้งานแล้ว"}` |

### 9) Edge Cases

| Case | Behavior |
|------|----------|
| **Duplicate email (race)** | DB UNIQUE constraint จะ throw |

---

## Test Environment Setup

### Database State สำหรับทดสอบ

```sql
-- Admin user (for staff operations)
INSERT INTO users (name, email, password, role) VALUES
('Admin', 'admin@test.com', '$2y$10$...', 'admin');

-- Staff user
INSERT INTO users (name, email, password, role) VALUES
('Staff', 'staff@test.com', '$2y$10$...', 'staff');

-- Member user (for borrowing)
INSERT INTO users (name, email, password, role) VALUES
('Member', 'member@test.com', '$2y$10$...', 'member');

-- Test books
INSERT INTO books (title, author, quantity, available) VALUES
('Book A', 'Author A', 3, 3),
('Book B', 'Author B', 1, 1),
('Book C', 'Author C', 1, 0);  -- out of stock

-- Category
INSERT INTO categories (name) VALUES ('Fiction');
```

### Test Credentials

| Role | Email | Password |
|------|-------|----------|
| Admin | admin@library.com | 123456 |

---

## Checklist สำหรับ Manual Testing

```
□ Login
  □ Success case (admin/staff/member)
  □ Wrong password
  □ Rate limit after 5 attempts

□ Registration
  □ Success case
  □ Email duplicate
  □ Validation errors

□ Borrow
  □ Single book
  □ Multiple books
  □ Quota limit
  □ Double submit protection

□ Return
  □ No fine (on time)
  □ With fine + pay now
  □ With fine + don't pay

□ Pay Fine
  □ Pay from unpaid list
  □ Already paid error

□ Reservation
  □ Create → Fulfill → Borrow created
  □ Create → Cancel → Stock returned
  □ Duplicate reservation blocked

□ Book CRUD
  □ Create with image
  □ Update change quantity
  □ Delete (no history)
  □ Delete blocked (has borrows)
```

---

*เอกสารนี้สร้างจากโค้ดจริงในโปรเจกต์ ไม่มีการเดาหรือแต่งเพิ่ม*
