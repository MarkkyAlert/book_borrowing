# Function Reference - ระบบยืมคืนหนังสือ

เอกสารอ้างอิงฟังก์ชันทั้งหมดในระบบ สำหรับผู้ดูแลและนักพัฒนาที่ต้องการแก้ไขต่อ

---

## สารบัญ (TOC)

1. [AuthService](#1-authservice)
2. [BorrowService](#2-borrowservice)
3. [ReservationService](#3-reservationservice)
4. [BookService](#4-bookservice)
5. [MemberService](#5-memberservice)
6. [Helper Functions](#6-helper-functions)

---

## 1. AuthService

**ไฟล์:** `app/Services/AuthService.php`

### 1.1 login()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | ตรวจสอบ email และ password เพื่อ authenticate user |
| **Where Used** | `login.php` |
| **Inputs** | `$email: string`, `$password: string` (plaintext) |
| **Outputs** | `?array` - user data หรือ null ถ้าล้มเหลว |
| **Side Effects** | ไม่มี |
| **Business Rules** | ไม่บอกว่า email/password ผิด (ป้องกัน enumeration) |
| **Error Handling** | Return null ถ้า email ไม่พบ หรือ password ไม่ตรง |
| **DB Touchpoints** | `users` (SELECT) |
| **Idempotency** | ✅ Safe - เรียกซ้ำได้ |
| **Concurrency** | ✅ Safe - ไม่มี race condition |

**Example:**
```php
$user = $authService->login('admin@library.com', '123456');
if ($user) {
    $_SESSION['user_id'] = $user['id'];
}
```

**Tests:** valid credentials → user array, wrong password → null, email not found → null

---

### 1.2 register()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | สร้าง user ใหม่ (role = member) |
| **Where Used** | `register.php` |
| **Inputs** | `$data: ['name', 'email', 'password', 'phone?']` |
| **Outputs** | `['success' => bool, 'user_id?' => int, 'error?' => string]` |
| **Side Effects** | INSERT `users` (password hashed with bcrypt) |
| **Business Rules** | Role = 'member' เสมอ, Email ต้อง unique |
| **Error Handling** | ข้อมูลไม่ครบ, Email ซ้ำ, DB error |
| **DB Touchpoints** | `users` (SELECT, INSERT) |
| **Idempotency** | ❌ เรียกซ้ำ = email ซ้ำ error |
| **Concurrency** | ⚠️ Unique constraint ป้องกัน |

---

### 1.3 changePassword()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | เปลี่ยนรหัสผ่าน (ต้องยืนยันรหัสเดิม) |
| **Where Used** | `profile.php` |
| **Inputs** | `$userId: int`, `$currentPassword: string`, `$newPassword: string` |
| **Outputs** | `['success' => bool, 'error?' => string]` |
| **Side Effects** | UPDATE `users.password` |
| **Business Rules** | ต้องยืนยันรหัสผ่านเดิมก่อน |
| **Error Handling** | User ไม่พบ, รหัสผ่านเดิมผิด |

---

### 1.4 requestPasswordReset()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | สร้าง token สำหรับ reset password |
| **Where Used** | `forgot_password.php` |
| **Inputs** | `$email: string` |
| **Outputs** | `['success' => bool, 'token?' => string]` |
| **Side Effects** | INSERT `password_resets` |
| **Business Rules** | ถ้า email ไม่พบ ยังคง return success (ป้องกัน enumeration), Rate limit: 3/hour |
| **Error Handling** | Rate limit exceeded |

---

### 1.5 resetPassword()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | รีเซ็ตรหัสผ่านด้วย token |
| **Where Used** | `reset_password.php` |
| **Inputs** | `$token: string`, `$newPassword: string` |
| **Outputs** | `['success' => bool, 'error?' => string]` |
| **Side Effects** | UPDATE `users.password`, mark token used |
| **Business Rules** | Token ต้อง valid และยังไม่หมดอายุ, ใช้ได้ครั้งเดียว |
| **Error Handling** | Token ไม่ถูกต้อง/หมดอายุ |

---

## 2. BorrowService

**ไฟล์:** `app/Services/BorrowService.php`

### 2.1 createBorrow()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | บันทึกการยืมหนังสือ (รองรับหลายเล่ม) |
| **Where Used** | `admin/borrow_form.php` |
| **Inputs** | `$userId: int`, `$bookIds: array`, `$borrowDays?: int` (1-30, default: 7) |
| **Outputs** | `['success', 'borrowed', 'skipped', 'due_date', 'message']` |
| **Side Effects** | INSERT `borrows`, UPDATE `books.available` -= 1 |
| **Business Rules** | Quota: MAX_BORROW_BOOKS (3), ห้ามยืมซ้ำเล่มเดิม, Member only |
| **Error Handling** | ไม่เลือก user/book, เกินโควต้า, ไม่พบ member |
| **DB Touchpoints** | `users`, `borrows`, `books` |
| **Idempotency** | ❌ เรียกซ้ำ = ยืมซ้ำ (ถ้าคืนแล้ว) |
| **Concurrency** | ✅ FOR UPDATE lock ป้องกัน race condition |

**Example:**
```php
$result = $borrowService->createBorrow($userId, [1, 2, 3], 7);
// ['success' => true, 'borrowed' => ['Book A', 'Book B'], 'skipped' => ['Book C (หมด)']]
```

**Tests:**
- ✅ Single/multiple books with quota OK
- ❌ Over quota → Exception
- ⚠️ Mixed (some unavailable) → partial success
- 🔒 Concurrent borrow last copy → 1 success, 1 skip

---

### 2.2 returnBook()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | บันทึกการคืนหนังสือ + คำนวณค่าปรับ |
| **Where Used** | `admin/borrows.php` (POST action=return) |
| **Inputs** | `$borrowId: int`, `$payNow?: bool`, `$recordedBy?: int` |
| **Outputs** | `['success', 'fine' => ['days', 'amount'], 'paid', 'message']` |
| **Side Effects** | UPDATE `borrows` (status='returned'), UPDATE `books.available` += 1, INSERT `payments` (if payNow) |
| **Business Rules** | State: borrowing → returned, Fine = days × FINE_PER_DAY |
| **Error Handling** | ไม่พบรายการยืม, คืนไปแล้ว |
| **DB Touchpoints** | `borrows`, `books`, `payments` |
| **Idempotency** | ❌ คืนซ้ำ = error |
| **Concurrency** | ✅ FOR UPDATE lock ป้องกัน double-return |

**Tests:**
- ✅ On time → fine=0
- ✅ Late 3 days → fine=30
- 🔒 Double-click return → 1 success, 1 error

---

### 2.3 calculateFine()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | คำนวณค่าปรับจากวันเกินกำหนด |
| **Where Used** | `returnBook()`, preview |
| **Inputs** | `$dueDate: string`, `$returnDate?: string` (default: today) |
| **Outputs** | `['days' => int, 'amount' => float]` |
| **Side Effects** | ไม่มี (pure function) |
| **Business Rules** | สูตร: วันเกิน × FINE_PER_DAY (10 บาท) |
| **Idempotency** | ✅ Pure function |

---

### 2.4 payFine()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | รับชำระค่าปรับทีหลัง |
| **Where Used** | `admin/payments.php`, `admin/borrows.php` |
| **Inputs** | `$borrowId: int`, `$recordedBy?: int` |
| **Outputs** | `['success', 'amount', 'message']` |
| **Side Effects** | INSERT `payments` |
| **Business Rules** | ต้องมี fine_amount > 0, ห้ามจ่ายซ้ำ |
| **Error Handling** | ไม่มีค่าปรับ, ชำระแล้ว |
| **Concurrency** | ✅ FOR UPDATE lock ป้องกัน double-pay |

---

## 3. ReservationService

**ไฟล์:** `app/Services/ReservationService.php`

### 3.1 createReservation()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | จองหนังสือ (หัก stock ทันที) |
| **Where Used** | `api/reserve_book.php` |
| **Inputs** | `$userId: int`, `$bookId: int`, `$expireDays?: int` (default: 2) |
| **Outputs** | `['success', 'message', 'expires_at']` |
| **Side Effects** | INSERT `reservations` (status='pending'), UPDATE `books.available` -= 1 |
| **Business Rules** | Stock หักทันที, ห้ามจองซ้ำ, ต้องมี available > 0 |
| **Error Handling** | จองซ้ำ, หนังสือหมด |
| **Concurrency** | ✅ FOR UPDATE lock ป้องกัน race condition |

**Tests:**
- ✅ Book available → success, stock -= 1
- ❌ Out of stock → error
- 🔒 Race: 2 users reserve last copy → 1 success, 1 error

---

### 3.2 fulfillReservation()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | อนุมัติการจอง → สร้าง borrow |
| **Where Used** | `admin/reservations.php` (POST action=approve) |
| **Inputs** | `$reservationId: int`, `$borrowDays?: int` |
| **Outputs** | `['success', 'borrow_id', 'due_date', 'message']` |
| **Side Effects** | INSERT `borrows`, UPDATE `reservations` (status='fulfilled') |
| **Business Rules** | State: pending → fulfilled, ตรวจ quota ก่อนอนุมัติ, ไม่ต้อง update stock |
| **Error Handling** | ไม่ใช่ pending, User เกิน quota |
| **Concurrency** | ✅ FOR UPDATE lock |

---

### 3.3 cancelReservation()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | ยกเลิกการจอง + คืน stock |
| **Where Used** | `admin/reservations.php` (POST action=cancel) |
| **Inputs** | `$reservationId: int`, `$userId?: int` (ถ้าส่ง = ต้องเป็นเจ้าของ) |
| **Outputs** | `['success', 'message']` |
| **Side Effects** | UPDATE `reservations` (status='cancelled'), UPDATE `books.available` += 1 |
| **Business Rules** | State: pending → cancelled |
| **Error Handling** | ไม่พบ/ยกเลิกแล้ว |

---

### 3.4 expireOverdueReservations()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | Batch job: หมดอายุการจองที่เกินกำหนด |
| **Where Used** | `cron/expire_reservations.php` |
| **Inputs** | ไม่มี |
| **Outputs** | `int` - จำนวนที่ expire |
| **Side Effects** | UPDATE `reservations` (status='expired'), UPDATE `books.available` += 1 |
| **Business Rules** | State: pending (expired) → expired |
| **Idempotency** | ✅ เรียกซ้ำไม่มีผล |

---

## 4. BookService

**ไฟล์:** `app/Services/BookService.php`

### 4.1 getBooks()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | ดึงรายการหนังสือ + filter + sort |
| **Where Used** | `index.php`, `admin/books.php` |
| **Inputs** | `$filters: ['search?', 'category_id?', 'status?', 'sort?']` |
| **Outputs** | `array` - รายการหนังสือ |
| **Side Effects** | ไม่มี |

### 4.2 createBook()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | สร้างหนังสือใหม่ |
| **Where Used** | `admin/book_form.php` |
| **Inputs** | `$data: ['title', 'author', 'isbn?', 'category_id?', 'quantity?']` |
| **Outputs** | `int` - book ID |
| **Side Effects** | INSERT `books` (available = quantity) |
| **Business Rules** | ISBN ต้อง unique |

### 4.3 updateBook()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | อัปเดตหนังสือ |
| **Where Used** | `admin/book_form.php` |
| **Inputs** | `$id: int`, `$data: [...]` |
| **Side Effects** | UPDATE `books` (available คำนวณใหม่ตาม quantity diff) |

### 4.4 deleteBook()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | ลบหนังสือ |
| **Where Used** | `admin/books.php` |
| **Inputs** | `$id: int` |
| **Side Effects** | DELETE `books`, ลบ cover image file |
| **Business Rules** | ห้ามลบถ้ามีคนยืมอยู่ หรือมีประวัติการยืม |
| **Error Handling** | กำลังถูกยืม, มีประวัติ |

---

## 5. MemberService

**ไฟล์:** `app/Services/MemberService.php`

### 5.1 createMember()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | สร้างสมาชิกใหม่ (โดย staff) |
| **Where Used** | `admin/member_form.php`, `api/add_member.php` |
| **Inputs** | `$data: ['name', 'email', 'phone?', 'password?']` |
| **Outputs** | `['id', 'name', 'email', 'password']` - password คือ plaintext |
| **Side Effects** | INSERT `users` (role='member') |
| **Business Rules** | Password auto-generate ถ้าไม่ส่ง |

### 5.2 updateMember()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | อัปเดตผู้ใช้ (+ เปลี่ยน role ถ้า admin ส่งมา) |
| **Where Used** | `admin/member_form.php` |
| **Inputs** | `$id: int`, `$data: ['name', 'email', 'phone?', 'role?']` |
| **Side Effects** | UPDATE `users` (role whitelist: member/staff) |

### 5.3 deleteMember()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | ลบผู้ใช้ (member/staff, ไม่รวม admin) |
| **Where Used** | `admin/members.php` |
| **Business Rules** | ห้ามลบถ้ามีประวัติการยืม |

### 5.4 importMember()

| หัวข้อ | รายละเอียด |
|--------|------------|
| **Purpose** | Import สมาชิกจาก CSV (upsert) |
| **Where Used** | `admin/import_members.php` |
| **Outputs** | `['action' => 'created'|'updated', 'id']` |
| **Business Rules** | Email มี → update, Email ไม่มี → insert with default password |

---

## 6. Helper Functions

**ไฟล์:** `includes/functions.php`

### 6.1 Security Functions

| Function | Purpose | Example |
|----------|---------|---------|
| `e($string)` | Escape HTML (ป้องกัน XSS) | `<?= e($user['name']) ?>` |
| `generateCSRFToken()` | สร้าง CSRF token | `<input name="csrf_token" value="<?= generateCSRFToken() ?>">` |
| `validateCSRFToken($token)` | ตรวจสอบ CSRF token | `if (!validateCSRFToken($_POST['csrf_token'])) die();` |
| `checkRateLimit($key)` | ตรวจ brute force limit | `if (!checkRateLimit('login')) { /* blocked */ }` |
| `incrementRateLimit($key)` | เพิ่ม attempt counter | หลังจาก login fail |
| `resetRateLimit($key)` | Reset counter | หลังจาก login success |

### 6.2 Auth Functions

| Function | Purpose | Return |
|----------|---------|--------|
| `isLoggedIn()` | ตรวจว่า login อยู่ไหม | `bool` |
| `isAdmin()` | ตรวจว่าเป็น admin | `bool` |
| `isStaff()` | ตรวจว่าเป็น staff หรือ admin | `bool` |
| `requireLogin()` | บังคับ login (redirect ถ้าไม่) | `void` หรือ redirect |
| `requireAdmin()` | บังคับ admin | `void` หรือ redirect |
| `requireStaff()` | บังคับ staff+ | `void` หรือ redirect |
| `getCurrentUser()` | ดึง user data จาก DB | `?array` |

### 6.3 Flash Message Functions

| Function | Purpose |
|----------|---------|
| `setFlash($type, $message)` | ตั้งข้อความชั่วคราว |
| `getFlash()` | ดึงแล้วลบ flash |
| `displayFlash()` | แสดง flash พร้อม styling |

**Types:** 'success', 'error', 'warning', 'info'

### 6.4 Validation Functions

| Function | Purpose | Return |
|----------|---------|--------|
| `isValidEmail($email)` | ตรวจ format email | `bool` |
| `isValidPhone($phone)` | ตรวจเบอร์ไทย (9-10 หลัก) | `bool` |
| `validateName($name, $max)` | ตรวจชื่อ | `?string` (error หรือ null) |
| `validateMaxLength($val, $max, $field)` | ตรวจความยาว | `?string` |
| `validatePassword($pwd, $allowEmpty)` | ตรวจรหัสผ่าน | `?string` |

### 6.5 Utility Functions

| Function | Purpose | Example |
|----------|---------|---------|
| `redirect($url)` | Redirect + exit | `redirect(APP_URL . '/login.php')` |
| `formatDate($date, $format)` | จัดรูปแบบวันที่ | `formatDate('2024-01-15')` → "15/01/2024" |
| `formatFine($amount)` | จัดรูปแบบค่าปรับ | `formatFine(30)` → "30 บาท" |
| `daysDiff($date1, $date2)` | คำนวณวันต่าง | `daysDiff('2024-01-01', '2024-01-15')` → 14 |
| `getSetting($key, $default)` | ดึง setting จาก DB | `getSetting('org_name', 'ห้องสมุด')` |
| `updateSetting($key, $value)` | บันทึก setting | `updateSetting('org_name', 'ห้องสมุด ABC')` |

---

## Quick Reference: State Transitions

### Borrow States
```
borrowing → returned (returnBook)
```

### Reservation States
```
pending → fulfilled (fulfillReservation) → creates borrow
pending → cancelled (cancelReservation) → returns stock
pending → expired   (expireOverdueReservations) → returns stock
```

---

## Quick Reference: Race Condition Protection

| Operation | Protection | Method |
|-----------|------------|--------|
| ยืมหนังสือ | Lock user + book rows | `FOR UPDATE` |
| คืนหนังสือ | Lock borrow row | `FOR UPDATE` where status='borrowing' |
| จองหนังสือ | Lock book row | `FOR UPDATE` |
| อนุมัติจอง | Lock reservation row | `FOR UPDATE` where status='pending' |
| ชำระค่าปรับ | Lock borrow row | `FOR UPDATE` + check payment exists |

---

*เอกสารนี้สร้างจากโค้ดจริง ไม่มีการเดาหรือแต่งเพิ่ม*
