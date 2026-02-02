# Flow Test Guide - คู่มือทดสอบระบบยืมคืนหนังสือ

เอกสารนี้สำหรับทดสอบระบบแบบ manual และเป็นฐานสำหรับเขียน automated tests

---

## สารบัญ

1. [User Login](#flow-1-user-login)
2. [User Registration](#flow-2-user-registration)
3. [Create Borrow](#flow-3-create-borrow)
4. [Return Book](#flow-4-return-book)
5. [Create Reservation](#flow-5-create-reservation)
6. [Fulfill Reservation](#flow-6-fulfill-reservation)
7. [Cancel Reservation](#flow-7-cancel-reservation)
8. [Create Book](#flow-8-create-book)
9. [Delete Book](#flow-9-delete-book)
10. [Create Member](#flow-10-create-member)
11. [Pay Fine](#flow-11-pay-fine)
12. [Search Books API](#flow-12-search-books-api)

---

## Flow 1: User Login

### 1) Flow Name
**User Login** - เข้าสู่ระบบ

### 2) Goal
ตรวจสอบ credentials และสร้าง authenticated session เพื่อให้ผู้ใช้เข้าถึงฟีเจอร์ตาม role

### 3) Preconditions

| Condition | Required State |
|-----------|---------------|
| Login State | ❌ ต้องไม่ login อยู่ (ถ้า login อยู่จะ redirect ไป index.php) |
| Database | ต้องมี user record ใน `users` table ที่ email ตรงกัน |
| Rate Limit | ต้องไม่เกิน 5 attempts ใน 15 นาที |

### 4) Trigger

| Property | Value |
|----------|-------|
| Endpoint | `/login.php` |
| Method | `POST` |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `email` | string | ✅ | ไม่ว่าง |
| `password` | string | ✅ | ไม่ว่าง |

**Headers/Session:** ไม่ต้องมี CSRF token, Session ต้อง start แล้ว

### 6) Steps

```
1. เปิด browser ไปที่ /login.php
2. กรอก email: admin@library.com
3. กรอก password: 123456
4. กดปุ่ม "เข้าสู่ระบบ"
5. ตรวจสอบ redirect ไปยังหน้าที่ถูกต้อง
6. ตรวจ $_SESSION['user_id'] มีค่า
7. ตรวจ $_SESSION['role'] ตรงกับ user
```

### 7) Expected Results

| Aspect | Expected |
|--------|----------|
| HTTP Status | 302 (redirect) |
| Redirect URL | `/admin/` (staff/admin) หรือ `/index.php` (member) |
| Session Changes | `user_id`, `user_name`, `user_email`, `role` ถูกตั้งค่า |
| Flash Message | "เข้าสู่ระบบสำเร็จ ยินดีต้อนรับ {name}" |
| Rate Limit | Counter reset เป็น 0 |

### 8) Failure Paths

| Scenario | Input | Expected |
|----------|-------|----------|
| Email ว่าง | `email=""` | Error: "กรุณากรอกอีเมล" |
| Password ว่าง | `password=""` | Error: "กรุณากรอกรหัสผ่าน" |
| Email ไม่มีในระบบ | `email="notexist@x.com"` | Error: "อีเมลหรือรหัสผ่านไม่ถูกต้อง" |
| Password ผิด | `password="wrong"` | Error: "อีเมลหรือรหัสผ่านไม่ถูกต้อง" |
| Rate limit exceeded | > 5 attempts | Error: "ลองผิดหลายครั้งเกินไป กรุณารอ 15 นาที" |

### 9) Edge Cases

| Case | Test Steps | Expected |
|------|------------|----------|
| **Duplicate Submit** | กด login 2 ครั้งเร็วๆ | ครั้งแรก success, ครั้งสอง redirect เพราะ login แล้ว |
| **Multi-tab** | เปิด 2 tab, login tab แรก, refresh tab สอง | Tab สองเห็นว่า login แล้ว |
| **Session Fixation** | จด session ID ก่อน login, ตรวจหลัง login | Session ID ต้องเปลี่ยน (regenerate) |
| **Concurrent Users** | 2 users login พร้อมกัน | แต่ละคนได้ session แยกกัน |

---

## Flow 2: User Registration

### 1) Flow Name
**User Registration** - สมัครสมาชิก

### 2) Goal
สร้าง user account ใหม่ที่มี role = member เพื่อให้สามารถจองหนังสือได้

### 3) Preconditions

| Condition | Required State |
|-----------|---------------|
| Login State | ❌ ต้องไม่ login อยู่ |
| Database | email ต้องไม่ซ้ำกับที่มีอยู่ |
| Rate Limit | ต้องไม่เกิน 5 attempts ใน 15 นาที (global key) |

### 4) Trigger

| Property | Value |
|----------|-------|
| Endpoint | `/register.php` |
| Method | `POST` |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `name` | string | ✅ | ไม่ว่าง, ≤100 ตัวอักษร |
| `email` | string | ✅ | format email, unique |
| `phone` | string | ❌ | 9-10 หลัก (ถ้ากรอก) |
| `password` | string | ✅ | ≥6 ตัวอักษร |
| `confirm_password` | string | ✅ | ต้องตรงกับ password |

### 6) Steps

```
1. เปิด browser ไปที่ /register.php
2. กรอกข้อมูล:
   - ชื่อ: ทดสอบ สมาชิก
   - อีเมล: test_new@example.com (ต้องไม่ซ้ำ)
   - เบอร์โทร: 0812345678
   - รหัสผ่าน: 123456
   - ยืนยันรหัสผ่าน: 123456
3. กดปุ่ม "สมัครสมาชิก"
4. ตรวจ redirect ไป /login.php
5. ตรวจ flash message "สมัครสมาชิกสำเร็จ"
6. SELECT * FROM users WHERE email='test_new@example.com'
7. ตรวจ role = 'member', password เป็น bcrypt hash
```

### 7) Expected Results

| Aspect | Expected |
|--------|----------|
| HTTP Status | 302 (redirect to /login.php) |
| Database | INSERT 1 row ใน `users` |
| Password | Hashed ด้วย bcrypt ($2y$) |
| Role | 'member' (hardcoded) |
| Flash Message | "สมัครสมาชิกสำเร็จ กรุณาเข้าสู่ระบบ" |

### 8) Failure Paths

| Scenario | Input | Expected |
|----------|-------|----------|
| ชื่อว่าง | `name=""` | Error: "กรุณากรอกชื่อ-นามสกุล" |
| ชื่อยาวเกิน | `name=` (101 chars) | Error: "ชื่อต้องไม่เกิน 100 ตัวอักษร" |
| Email format ผิด | `email="invalid"` | Error: "รูปแบบอีเมลไม่ถูกต้อง" |
| Email ซ้ำ | `email="admin@library.com"` | Error: "อีเมลนี้มีผู้ใช้งานแล้ว" |
| Phone format ผิด | `phone="123"` | Error: "เบอร์โทรต้องเป็นตัวเลข 9-10 หลัก" |
| Password สั้นเกิน | `password="123"` | Error: "รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร" |
| Password ไม่ตรงกัน | `confirm_password="different"` | Error: "รหัสผ่านไม่ตรงกัน" |

### 9) Edge Cases

| Case | Test Steps | Expected |
|------|------------|----------|
| **Duplicate Submit** | กด submit 2 ครั้งเร็วๆ | ครั้งแรก success, ครั้งสอง email ซ้ำ |
| **SQL Injection** | `email="'; DROP TABLE users;--"` | Validation error (invalid email) |
| **XSS in name** | `name="<script>alert(1)</script>"` | บันทึกได้แต่แสดงแบบ escaped |

---

## Flow 3: Create Borrow

### 1) Flow Name
**Create Borrow** - บันทึกการยืมหนังสือ

### 2) Goal
Staff บันทึกการยืมหนังสือให้สมาชิก พร้อมหักจำนวนหนังสือที่มีอยู่

### 3) Preconditions

| Condition | Required State |
|-----------|---------------|
| Login State | ✅ ต้อง login เป็น staff หรือ admin |
| User | สมาชิกต้องมีอยู่ใน DB, role='member' |
| Book | หนังสือต้องมี available > 0 |
| Quota | สมาชิกยืมอยู่ < MAX_BORROW_BOOKS (3) |

### 4) Trigger

| Property | Value |
|----------|-------|
| Endpoint | `/admin/borrow_form.php` |
| Method | `POST` |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `user_id` | int | ✅ | > 0, ต้องเป็น member |
| `book_ids[]` | array | ✅ | ไม่ว่าง |
| `borrow_days` | int | ❌ | 1-30 (default: 7) |
| `csrf_token` | string | ✅ | ต้องตรงกับ session |

### 6) Steps

```
1. ตรวจว่ามี member ในระบบ (user_id = X)
2. ตรวจว่ามีหนังสือที่ available > 0 (book_id = Y)
3. จด books.available ก่อนทดสอบ
4. Login เป็น staff/admin
5. ไปที่ /admin/borrow_form.php
6. เลือกสมาชิก (user_id = X)
7. เลือกหนังสือ (book_id = Y)
8. ตั้งวันยืม = 7 วัน
9. กดปุ่ม "บันทึกการยืม"
10. ตรวจ flash message "ยืมสำเร็จ"
11. SELECT * FROM borrows WHERE user_id=X AND book_id=Y
12. ตรวจ status = 'borrowing', due_date = วันนี้ + 7
13. ตรวจ books.available ลดลง 1
```

### 7) Expected Results

| Aspect | Expected |
|--------|----------|
| HTTP Status | 302 (redirect) |
| borrows | INSERT: status='borrowing', due_date calculated |
| books.available | -1 |
| Flash Message | "ยืมสำเร็จ X เล่ม" |

### 8) Failure Paths

| Scenario | Input | Expected |
|----------|-------|----------|
| User ไม่ใช่ member | user_id ของ staff | Error: "ไม่พบสมาชิก" |
| หนังสือหมด | book ที่ available=0 | Skip พร้อม message |
| เกินโควต้า | ยืมเล่มที่ 4 | Error: "สมาชิกยืมได้สูงสุด 3 เล่ม" |
| ยืมซ้ำ | book ที่กำลังยืมอยู่ | Skip: "กำลังยืมอยู่แล้ว" |
| CSRF invalid | wrong token | Error: "คำขอไม่ถูกต้อง" |
| Not staff | login เป็น member | Redirect to login |

### 9) Edge Cases

| Case | Test Steps | Expected |
|------|------------|----------|
| **Concurrent Borrow** | 2 staff ยืมหนังสือเดียวกันพร้อมกัน | คนแรกสำเร็จ, คนสอง stock หมด |
| **Race Condition Quota** | User มี 2 เล่ม, 2 staff ยืมให้พร้อมกัน | คนแรกสำเร็จ, คนสองเกินโควต้า |
| **Mixed Success** | เลือก 3 เล่ม, 1 หมด | สำเร็จ 2 เล่ม, skip 1 เล่ม |

---

## Flow 4: Return Book

### 1) Flow Name
**Return Book** - คืนหนังสือ

### 2) Goal
Staff บันทึกการคืนหนังสือ คำนวณค่าปรับ (ถ้ามี) และคืนจำนวนหนังสือกลับ stock

### 3) Preconditions

| Condition | Required State |
|-----------|---------------|
| Login State | ✅ ต้อง login เป็น staff หรือ admin |
| Borrow Record | ต้องมี status = 'borrowing' |

### 4) Trigger

| Property | Value |
|----------|-------|
| Endpoint | `/admin/borrows.php` |
| Method | `POST` |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `action` | string | ✅ | = 'return' |
| `borrow_id` | int | ✅ | > 0, status='borrowing' |
| `pay_now` | checkbox | ❌ | จ่ายค่าปรับทันที |
| `csrf_token` | string | ✅ | ต้องตรงกับ session |

### 6) Steps

```
[คืนปกติ - ไม่เกินกำหนด]
1. ต้องมีรายการยืมที่ status='borrowing'
2. จด books.available ก่อนทดสอบ
3. Login เป็น staff/admin
4. ไปที่ /admin/borrows.php
5. ค้นหารายการยืมที่ยังไม่เกินกำหนด
6. กดปุ่ม "คืนหนังสือ"
7. ตรวจ flash message "คืนหนังสือเรียบร้อย"
8. ตรวจ borrows.status = 'returned'
9. ตรวจ borrows.return_date = วันนี้
10. ตรวจ borrows.fine_amount = 0
11. ตรวจ books.available เพิ่มขึ้น 1

[คืนเกินกำหนด]
12. UPDATE borrows SET due_date='2024-01-01' WHERE id=?
13. กด "คืนหนังสือ"
14. ตรวจ fine_amount = วันเกิน × FINE_PER_DAY (10)
```

### 7) Expected Results

| Aspect | ปกติ | เกินกำหนด |
|--------|------|-----------|
| borrows.status | 'returned' | 'returned' |
| borrows.return_date | NOW() | NOW() |
| borrows.fine_amount | 0 | days × 10 |
| books.available | +1 | +1 |
| payments | ไม่สร้าง | สร้างถ้า pay_now=true |

### 8) Failure Paths

| Scenario | Input | Expected |
|----------|-------|----------|
| Borrow ไม่พบ | borrow_id=99999 | Error: "ไม่พบรายการยืม" |
| คืนไปแล้ว | status='returned' | Error: "รายการนี้ถูกคืนไปแล้ว" |
| CSRF invalid | wrong token | Error + redirect |
| Not staff | member login | Redirect to login |

### 9) Edge Cases

| Case | Test Steps | Expected |
|------|------------|----------|
| **Double Submit** | กด F5 หลังคืน | Idempotency: "รายการนี้ถูกบันทึกไปแล้ว" |
| **Concurrent Return** | 2 staff คืนรายการเดียวกัน | คนแรกสำเร็จ, คนสอง "ถูกคืนไปแล้ว" |

---

## Flow 5: Create Reservation

### 1) Flow Name
**Create Reservation** - จองหนังสือ

### 2) Goal
สมาชิกจองหนังสือเพื่อกัน stock ไว้ก่อนมารับจริง

### 3) Preconditions

| Condition | Required State |
|-----------|---------------|
| Login State | ✅ ต้อง login (member+) |
| Book | available > 0 |
| Existing Reservation | ไม่มี pending reservation ซ้ำ |

### 4) Trigger

| Property | Value |
|----------|-------|
| Endpoint | `/api/reserve_book.php` |
| Method | `POST` |
| Response | JSON |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `book_id` | int | ✅ | > 0 |
| `csrf_token` | string | ✅ | ต้องตรงกับ session |

**หมายเหตุ:** `user_id` ดึงจาก `$_SESSION['user_id']` (ห้ามรับจาก POST)

### 6) Steps

```
1. ต้องมีหนังสือที่ available > 0
2. จด books.available ก่อนทดสอบ
3. Login เป็น member
4. ไปที่ /book.php?id=X
5. กดปุ่ม "จองหนังสือ"
6. ตรวจ Response: {"success": true}
7. SELECT * FROM reservations WHERE user_id=? AND book_id=?
8. ตรวจ status = 'pending'
9. ตรวจ books.available ลดลง 1 (หักทันที)
```

### 7) Expected Results

| Aspect | Expected |
|--------|----------|
| HTTP Status | 200 |
| Response | `{"success": true, "message": "..."}` |
| reservations | INSERT: status='pending', expires_at set |
| books.available | -1 (หักทันที) |

### 8) Failure Paths

| Scenario | Input | Expected |
|----------|-------|----------|
| ไม่ได้ login | no session | 401: "กรุณาเข้าสู่ระบบก่อน" |
| Method ไม่ใช่ POST | GET | 405: "Method not allowed" |
| CSRF invalid | wrong token | 403: "Invalid token" |
| book_id invalid | book_id=0 | 400: "ข้อมูลไม่ถูกต้อง" |
| หนังสือหมด | available=0 | 400: "หนังสือไม่พร้อมให้จอง" |
| จองซ้ำ | pending อยู่แล้ว | 400: "คุณจองหนังสือเล่มนี้อยู่แล้ว" |

### 9) Edge Cases

| Case | Test Steps | Expected |
|------|------------|----------|
| **Race Condition** | 2 users จองหนังสือเล่มสุดท้ายพร้อมกัน | คนแรกได้, คนสอง "หนังสือหมด" |
| **Multi-tab** | เปิด 2 tab, จองใน tab แรก, กดจองใน tab สอง | Tab สอง "จองอยู่แล้ว" |

---

## Flow 6: Fulfill Reservation

### 1) Flow Name
**Fulfill Reservation** - อนุมัติการจอง

### 2) Goal
Staff อนุมัติการจอง แปลงเป็น borrow record

### 3) Preconditions

| Condition | Required State |
|-----------|---------------|
| Login State | ✅ ต้อง login เป็น staff หรือ admin |
| Reservation | status = 'pending' |
| User Quota | user ยืมอยู่ < MAX_BORROW_BOOKS |

### 4) Trigger

| Property | Value |
|----------|-------|
| Endpoint | `/admin/reservations.php` |
| Method | `POST` |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `action` | string | ✅ | = 'approve' |
| `id` | int | ✅ | reservation_id |
| `csrf_token` | string | ✅ | ต้องตรงกับ session |

### 6) Steps

```
1. ต้องมี reservation ที่ status='pending'
2. Login เป็น staff/admin
3. ไปที่ /admin/reservations.php
4. ค้นหารายการจองที่รออนุมัติ
5. กดปุ่ม "อนุมัติ"
6. ตรวจ flash message "อนุมัติสำเร็จ"
7. ตรวจ reservations.status = 'fulfilled'
8. ตรวจ borrows มี record ใหม่ (status='borrowing')
9. ตรวจ books.available ไม่เปลี่ยน (หักตอนจองแล้ว)
```

### 7) Expected Results

| Aspect | Expected |
|--------|----------|
| reservations.status | 'fulfilled' |
| borrows | INSERT new record |
| books.available | ไม่เปลี่ยน |
| Flash Message | "อนุมัติการจองเรียบร้อย" |

### 8) Failure Paths

| Scenario | Input | Expected |
|----------|-------|----------|
| ไม่ใช่ pending | status='cancelled' | Error: "ไม่สามารถอนุมัติได้" |
| User เกินโควต้า | user มี 3 เล่มแล้ว | Error: "เกินจำนวนที่ยืมได้" |
| CSRF invalid | wrong token | Error + redirect |

### 9) Edge Cases

| Case | Test Steps | Expected |
|------|------------|----------|
| **Double Submit** | กด approve 2 ครั้ง | Idempotency ป้องกัน |
| **Concurrent Approve** | 2 staff approve พร้อมกัน | คนแรกสำเร็จ, คนสองไม่ใช่ pending |

---

## Flow 7: Cancel Reservation

### 1) Flow Name
**Cancel Reservation** - ยกเลิกการจอง

### 2) Goal
Staff ยกเลิกการจองและคืน stock กลับ

### 3) Preconditions

| Condition | Required State |
|-----------|---------------|
| Login State | ✅ ต้อง login เป็น staff หรือ admin |
| Reservation | status = 'pending' |

### 4) Trigger

| Property | Value |
|----------|-------|
| Endpoint | `/admin/reservations.php` |
| Method | `POST` |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `action` | string | ✅ | = 'cancel' |
| `id` | int | ✅ | reservation_id |
| `csrf_token` | string | ✅ | ต้องตรงกับ session |

### 6) Steps

```
1. ต้องมี reservation ที่ status='pending'
2. จด books.available ก่อนยกเลิก
3. Login เป็น staff/admin
4. ไปที่ /admin/reservations.php
5. กดปุ่ม "ยกเลิก"
6. ตรวจ flash message "ยกเลิกสำเร็จ"
7. ตรวจ reservations.status = 'cancelled'
8. ตรวจ books.available เพิ่มขึ้น 1 (คืน stock)
```

### 7) Expected Results

| Aspect | Expected |
|--------|----------|
| reservations.status | 'cancelled' |
| books.available | +1 |
| Flash Message | "ยกเลิกการจองและคืนสต็อกหนังสือเรียบร้อยแล้ว" |

### 8) Failure Paths

| Scenario | Input | Expected |
|----------|-------|----------|
| ไม่ใช่ pending | status='fulfilled' | Error: "ไม่สามารถยกเลิกได้" |
| CSRF invalid | wrong token | Error + redirect |

### 9) Edge Cases

| Case | Test Steps | Expected |
|------|------------|----------|
| **Double Submit** | กด cancel 2 ครั้ง | Idempotency ป้องกัน |

---

## Flow 8: Create Book

### 1) Flow Name
**Create Book** - เพิ่มหนังสือ

### 2) Goal
Staff เพิ่มหนังสือใหม่เข้าระบบพร้อมอัปโหลดรูปปก

### 3) Preconditions

| Condition | Required State |
|-----------|---------------|
| Login State | ✅ ต้อง login เป็น staff หรือ admin |
| ISBN | ต้องไม่ซ้ำ (ถ้ากรอก) |

### 4) Trigger

| Property | Value |
|----------|-------|
| Endpoint | `/admin/book_form.php` |
| Method | `POST` |
| Encoding | `multipart/form-data` |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `title` | string | ✅ | ไม่ว่าง, ≤200 |
| `author` | string | ✅ | ไม่ว่าง, ≤100 |
| `isbn` | string | ❌ | unique |
| `category_id` | int | ❌ | ต้องมีใน categories |
| `quantity` | int | ✅ | ≥1 |
| `cover_image` | file | ❌ | JPEG/PNG/GIF/WEBP, ≤2MB |
| `csrf_token` | string | ✅ | ต้องตรงกับ session |

### 6) Steps

```
1. Login เป็น staff/admin
2. ไปที่ /admin/book_form.php
3. กรอกข้อมูล:
   - ชื่อหนังสือ: หนังสือทดสอบ
   - ผู้แต่ง: ผู้เขียน XYZ
   - ISBN: 1234567890123
   - จำนวน: 5
   - อัปโหลดรูปปก (JPEG)
4. กดบันทึก
5. ตรวจ redirect ไป /admin/books.php
6. ตรวจ flash message "เพิ่มหนังสือสำเร็จ"
7. ตรวจ quantity = 5, available = 5
8. ตรวจ cover_image มีไฟล์ใน uploads/covers/
```

### 7) Expected Results

| Aspect | Expected |
|--------|----------|
| books | INSERT with available = quantity |
| cover_image | ไฟล์บันทึกใน uploads/covers/ |
| Flash Message | "เพิ่มหนังสือสำเร็จ" |

### 8) Failure Paths

| Scenario | Input | Expected |
|----------|-------|----------|
| ชื่อว่าง | title="" | Error: "กรุณากรอกชื่อหนังสือ" |
| ISBN ซ้ำ | isbn ที่มีอยู่ | Error: "ISBN นี้มีในระบบแล้ว" |
| ไฟล์ไม่ใช่รูป | upload .txt | Error: "รองรับเฉพาะไฟล์รูปภาพ" |
| ไฟล์เกิน 2MB | large image | Error: "ขนาดไฟล์ต้องไม่เกิน 2MB" |

### 9) Edge Cases

| Case | Test Steps | Expected |
|------|------------|----------|
| **Path Traversal** | filename="../../../passwd" | ชื่อไฟล์ถูก sanitize (uniqid) |
| **Double Extension** | file.php.jpg | ตรวจ MIME จริงด้วย finfo |

---

## Flow 9: Delete Book

### 1) Flow Name
**Delete Book** - ลบหนังสือ

### 2) Goal
Staff ลบหนังสือออกจากระบบ

### 3) Preconditions

| Condition | Required State |
|-----------|---------------|
| Login State | ✅ ต้อง login เป็น staff หรือ admin |
| Book | available = quantity (ไม่มีคนยืมอยู่) |

### 4) Trigger

| Property | Value |
|----------|-------|
| Endpoint | `/admin/books.php` |
| Method | `POST` |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `action` | string | ✅ | = 'delete' |
| `id` | int | ✅ | book_id |
| `csrf_token` | string | ✅ | ต้องตรงกับ session |

### 6) Steps

```
1. สร้างหนังสือใหม่ที่ไม่มีใครยืม
2. Login เป็น staff/admin
3. ไปที่ /admin/books.php
4. กดปุ่ม "ลบ" ที่หนังสือที่สร้าง
5. ยืนยันการลบ
6. ตรวจ flash message "ลบสำเร็จ"
7. ตรวจ books ไม่มี record นั้นแล้ว
```

### 7) Expected Results

| Aspect | Expected |
|--------|----------|
| books | DELETE row |
| File | cover image ถูกลบ |
| Flash Message | "ลบหนังสือสำเร็จ" |

### 8) Failure Paths

| Scenario | Input | Expected |
|----------|-------|----------|
| มีคนยืมอยู่ | available < quantity | Error: "ไม่สามารถลบได้" |
| Foreign key | มี borrow history | Error หรือ cascade |

### 9) Edge Cases

| Case | Test Steps | Expected |
|------|------------|----------|
| **No cover file** | หนังสือไม่มีรูปปก | ลบได้โดยไม่ error |

---

## Flow 10: Create Member

### 1) Flow Name
**Create Member** - เพิ่มสมาชิก (โดย Staff)

### 2) Goal
Staff เพิ่มสมาชิกใหม่โดยไม่ต้องรอให้สมัครเอง

### 3) Preconditions

| Condition | Required State |
|-----------|---------------|
| Login State | ✅ ต้อง login เป็น staff หรือ admin |
| Email | ต้องไม่ซ้ำ |

### 4) Trigger

| Property | Value |
|----------|-------|
| Endpoint | `/admin/member_form.php` หรือ `/api/add_member.php` |
| Method | `POST` |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `name` | string | ✅ | ไม่ว่าง, ≤100 |
| `email` | string | ✅ | email format, unique |
| `phone` | string | ❌ | 9-10 หลัก |
| `password` | string | ❌ | ≥6 (ถ้าไม่กรอกจะ generate) |
| `csrf_token` | string | ✅ | ต้องตรงกับ session |

### 6) Steps

```
1. Login เป็น staff/admin
2. ไปที่ /admin/member_form.php
3. กรอกข้อมูล:
   - ชื่อ: สมาชิกใหม่
   - อีเมล: newmember@test.com
   - เบอร์โทร: 0899999999
   - รหัสผ่าน: (เว้นว่างให้ generate)
4. กดบันทึก
5. ตรวจ redirect + flash แสดง password
6. ตรวจ users.role = 'member'
7. ตรวจ password เป็น hash
```

### 7) Expected Results

| Aspect | Expected |
|--------|----------|
| users | INSERT with role='member' |
| password | Hashed (bcrypt) |
| Response (API) | JSON with generated_password |

### 8) Failure Paths

| Scenario | Input | Expected |
|----------|-------|----------|
| Email ซ้ำ | email ที่มีอยู่ | Error: "อีเมลนี้มีในระบบแล้ว" |
| Email format ผิด | "invalid" | Error: "รูปแบบอีเมลไม่ถูกต้อง" |

### 9) Edge Cases

| Case | Test Steps | Expected |
|------|------------|----------|
| **Auto-generate** | ไม่กรอก password | สร้าง random และแสดงให้ staff |

---

## Flow 11: Pay Fine

### 1) Flow Name
**Pay Fine** - ชำระค่าปรับ

### 2) Goal
Staff รับชำระค่าปรับจากสมาชิกที่คืนหนังสือเกินกำหนด

### 3) Preconditions

| Condition | Required State |
|-----------|---------------|
| Login State | ✅ ต้อง login เป็น staff หรือ admin |
| Borrow | status='returned', fine_amount > 0 |
| Payment | ยังไม่มี payment record |

### 4) Trigger

| Property | Value |
|----------|-------|
| Endpoint | `/admin/payments.php` |
| Method | `POST` |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `action` | string | ✅ | = 'pay' |
| `borrow_id` | int | ✅ | borrow ที่มี fine > 0 |
| `csrf_token` | string | ✅ | ต้องตรงกับ session |

### 6) Steps

```
1. ต้องมี borrow ที่ status='returned' และ fine_amount > 0
2. ต้องยังไม่มี payment record
3. Login เป็น staff/admin
4. ไปที่ /admin/payments.php
5. กดปุ่ม "รับชำระ"
6. ตรวจ flash message "รับชำระค่าปรับเรียบร้อย"
7. ตรวจ payments มี record ใหม่
8. ตรวจ payments.amount = borrow.fine_amount
```

### 7) Expected Results

| Aspect | Expected |
|--------|----------|
| payments | INSERT new record |
| payments.amount | = borrows.fine_amount |
| Flash Message | "รับชำระค่าปรับเรียบร้อย" |

### 8) Failure Paths

| Scenario | Input | Expected |
|----------|-------|----------|
| ไม่มีค่าปรับ | fine_amount=0 | ไม่แสดงปุ่ม |
| จ่ายแล้ว | มี payment record | Error: "ชำระเงินแล้ว" |

### 9) Edge Cases

| Case | Test Steps | Expected |
|------|------------|----------|
| **Double Submit** | กด pay 2 ครั้ง | Unique constraint ป้องกัน |

---

## Flow 12: Search Books API

### 1) Flow Name
**Search Books API** - ค้นหาหนังสือ

### 2) Goal
ค้นหาหนังสือสำหรับ autocomplete หรือ AJAX search

### 3) Preconditions

| Condition | Required State |
|-----------|---------------|
| Login State | ❌ ไม่จำเป็น (public API) |

### 4) Trigger

| Property | Value |
|----------|-------|
| Endpoint | `/api/search_books.php` |
| Method | `GET` |
| Response | JSON |

### 5) Inputs

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `search` | string | ❌ | keyword |
| `category` | int | ❌ | category_id |
| `available` | bool | ❌ | filter available > 0 |

### 6) Steps

```
1. GET /api/search_books.php?search=php
2. ตรวจ Response: JSON array

3. GET /api/search_books.php?category=1&available=1
4. ตรวจ: หนังสือในหมวด 1 ที่ available > 0
```

### 7) Expected Results

| Aspect | Expected |
|--------|----------|
| HTTP Status | 200 |
| Content-Type | application/json |
| Response | Array of book objects |

### 8) Failure Paths

| Scenario | Input | Expected |
|----------|-------|----------|
| ไม่พบ | search="ไม่มี" | Empty array [] |
| Invalid category | category=99999 | Empty array [] |

### 9) Edge Cases

| Case | Test Steps | Expected |
|------|------------|----------|
| **SQL Injection** | search="'; DROP TABLE" | Safe (prepared statement) |
| **Unicode** | search="日本語" | Match ถ้ามี |

---

## Quick Test Checklist

### Authentication
- [ ] Login success (member/staff/admin)
- [ ] Login fail (wrong password)
- [ ] Login rate limit
- [ ] Register success
- [ ] Register email duplicate
- [ ] Logout

### Borrow
- [ ] Create borrow (single/multiple)
- [ ] Create borrow (quota exceeded)
- [ ] Return book (no fine)
- [ ] Return book (with fine)
- [ ] Pay fine

### Reservation
- [ ] Create reservation
- [ ] Create reservation (duplicate/no stock)
- [ ] Fulfill reservation
- [ ] Cancel reservation

### CRUD
- [ ] Create/Update/Delete book
- [ ] Create/Update/Delete member

### Security
- [ ] CSRF protection
- [ ] Rate limiting
- [ ] Session fixation prevention
- [ ] Authorization checks

---

*เอกสารนี้อ้างอิงจากโค้ดจริง ไม่มีการเดาหรือแต่งเพิ่ม*
