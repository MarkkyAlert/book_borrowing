# Study Guide - คู่มือศึกษาระบบยืมคืนหนังสือ

เอกสารนี้สำหรับ **เจ้าของโปรเจกต์** ที่ให้ AI เขียนโค้ดส่วนใหญ่ เพื่อให้สามารถ:
- อ่านโค้ดได้เอง
- เข้าใจ flow การทำงาน
- แก้ไขระบบได้โดยไม่พัง

---

## 1. แผนที่โปรเจกต์ (Project Map)

### 1.1 โฟลเดอร์สำคัญและหน้าที่

| โฟลเดอร์ | หน้าที่ | ตัวอย่างไฟล์ |
|----------|--------|-------------|
| `/` (root) | **Public Entry Points** - หน้าเว็บที่ user เข้าถึงได้โดยตรง | `index.php`, `login.php`, `register.php` |
| `admin/` | **Admin Panel** - หน้าจัดการสำหรับ staff/admin | `index.php` (dashboard), `books.php`, `borrows.php` |
| `api/` | **API Endpoints** - รับ AJAX requests จาก frontend | `search_books.php`, `reserve_book.php` |
| `app/Services/` | **Business Logic** - กฎเกณฑ์ทางธุรกิจ, transactions | `BorrowService.php`, `AuthService.php` |
| `app/Repositories/` | **Data Access** - SQL queries ทั้งหมด | `BookRepository.php`, `UserRepository.php` |
| `includes/` | **Shared Components** - config, helpers, UI components | `config.php`, `functions.php`, `db.php` |
| `database/` | **Database Files** - schema และ migrations | `schema.sql`, `migrations/` |
| `uploads/covers/` | **User Uploads** - รูปปกหนังสือ | `cover_*.png` |
| `cron/` | **Scheduled Tasks** - งานที่รันตามเวลา | `expire_reservations.php` |

### 1.2 ไฟล์ Entry Point สำคัญ (อ่านก่อน 10 ไฟล์)

| ลำดับ | ไฟล์ | เหตุผลที่ต้องอ่านก่อน |
|-------|------|---------------------|
| 1 | `bootstrap.php` | **จุดเริ่มต้นของทุกหน้า** - โหลด config, DB, helpers, autoloader |
| 2 | `includes/config.php` | ค่าคงที่ทั้งระบบ (DB, business rules) ที่อ่านจาก `.env` |
| 3 | `includes/functions.php` | **Helper functions ทั้งหมด** - `e()`, `redirect()`, `requireLogin()`, CSRF |
| 4 | `includes/db.php` | PDO connection (Singleton pattern) |
| 5 | `login.php` | ตัวอย่าง **authentication flow** + rate limiting |
| 6 | `app/Services/BorrowService.php` | **Business logic หลัก** - ยืม/คืน/ค่าปรับ |
| 7 | `app/Repositories/BookRepository.php` | ตัวอย่าง **Repository pattern** + row locking |
| 8 | `admin/borrow_form.php` | ตัวอย่าง **admin page** + CSRF + idempotency |
| 9 | `api/reserve_book.php` | ตัวอย่าง **API endpoint** - auth/CSRF/validation |
| 10 | `admin/index.php` | **Dashboard** - เห็นภาพรวมว่าระบบ query อะไรบ้าง |

---

## 2. Request → Response Flow

### 2.1 Flow ภาพรวม

```
┌─────────────────────────────────────────────────────────────────────────┐
│ Browser (User)                                                          │
└─────────────────────────────────────────────────────────────────────────┘
                │
                │ HTTP Request (GET/POST)
                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ Entry Point (*.php, admin/*.php, api/*.php)                             │
│ ─────────────────────────────────────────                               │
│ • require bootstrap.php                                                 │
│ • Auth Check: requireLogin(), requireStaff(), requireAdmin()            │
│ • CSRF Check: validateCSRFToken($_POST['csrf_token'])                   │
│ • Input Validation (basic sanitization)                                 │
│ • Rate Limiting: checkRateLimit()                                       │
└─────────────────────────────────────────────────────────────────────────┘
                │
                │ Validated Data
                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ Service Layer (app/Services/)                                           │
│ ─────────────────────────────────────────                               │
│ • Business Logic & Validation Rules                                     │
│ • Transaction Management (begin/commit/rollback)                        │
│ • Calls Repository methods                                              │
│ • Throws Exception on failure                                           │
└─────────────────────────────────────────────────────────────────────────┘
                │
                │ Repository Calls
                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ Repository Layer (app/Repositories/)                                    │
│ ─────────────────────────────────────────                               │
│ • Pure SQL Queries (SELECT, INSERT, UPDATE, DELETE)                     │
│ • Row Locking (FOR UPDATE) when needed                                  │
│ • Returns arrays                                                        │
└─────────────────────────────────────────────────────────────────────────┘
                │
                │ PDO Query
                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ Database (MySQL)                                                        │
└─────────────────────────────────────────────────────────────────────────┘
                │
                │ Result
                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ Response                                                                │
│ • Web Page: setFlash() + redirect() หรือ render HTML                    │
│ • API: echo json_encode(['success' => ..., 'message' => ...])          │
└─────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Boundary (ขอบเขตความรับผิดชอบ)

| Layer | ตำแหน่ง | ควรทำ | ห้ามทำ |
|-------|---------|-------|--------|
| **Entry Point** | `*.php`, `admin/*.php`, `api/*.php` | รับ input, ตรวจ auth/CSRF, เรียก Service, render response | เขียน SQL, Business logic ซับซ้อน |
| **Service** | `app/Services/*.php` | Business logic, transactions, เรียก Repository | เขียน SQL โดยตรง, output HTML |
| **Repository** | `app/Repositories/*.php` | SQL queries, return arrays | Business logic, session access |
| **Helpers** | `includes/functions.php` | Utility functions, formatting | Database queries |

---

## 3. Core Flows (8 Flows หลัก)

### 3.1 Flow: User Login

| หัวข้อ | รายละเอียด |
|--------|-------------|
| **Goal** | ตรวจสอบ credentials และสร้าง session |
| **Entry Point** | `login.php` |
| **Inputs** | `email`, `password` (POST) |
| **Validation** | ไม่ว่าง, email format (`isValidEmail()`) |
| **Authorization** | ไม่ต้อง (หน้า public) |
| **DB Changes** | ไม่มี (read-only) |
| **Output** | redirect ไป `index.php` หรือ `admin/` ตาม role |
| **Failure Cases** | Email/password ผิด, Rate limit exceeded (5 attempts/15 min) |
| **จุดระวัง** | - ห้ามบอกว่า email หรือ password ผิด (user enumeration)<br>- Rate limit ใช้ key `login_` + md5(email) |

**Code Path:**
```
login.php → checkRateLimit() → AuthService::login() → UserRepository::findByEmail()
         → password_verify() → session_regenerate_id() → redirect
```

---

### 3.2 Flow: User Registration

| หัวข้อ | รายละเอียด |
|--------|-------------|
| **Goal** | สร้าง user ใหม่ (role = member) |
| **Entry Point** | `register.php` |
| **Inputs** | `name`, `email`, `password`, `confirm_password`, `phone` (optional) |
| **Validation** | - `validateMaxLength($name, 100)`<br>- `isValidEmail($email)`<br>- `validatePassword($password)` (min 6 chars)<br>- `isValidPhone($phone)` (9-10 digits) |
| **Authorization** | ไม่ต้อง (หน้า public) |
| **DB Changes** | INSERT `users` (role = 'member' hardcoded) |
| **Output** | redirect ไป `login.php` พร้อม flash success |
| **Failure Cases** | Email ซ้ำ, Rate limit (global key `register`) |
| **จุดระวัง** | - Rate limit ใช้ global key (ไม่ใช่ per-email) เพราะ attacker ใช้ email ใหม่ได้<br>- Password ถูก hash ด้วย `password_hash()` |

**Code Path:**
```
register.php → incrementRateLimit() → validate → AuthService::register()
            → UserRepository::emailExists() → UserRepository::create()
```

---

### 3.3 Flow: Create Borrow (ยืมหนังสือ)

| หัวข้อ | รายละเอียด |
|--------|-------------|
| **Goal** | บันทึกการยืมหนังสือ 1-3 เล่ม |
| **Entry Point** | `admin/borrow_form.php` |
| **Inputs** | `user_id`, `book_ids[]`, `borrow_days`, `csrf_token` |
| **Validation** | - `user_id > 0`<br>- `book_ids` ไม่ว่าง<br>- `borrow_days` 1-30<br>- user ต้องเป็น member |
| **Authorization** | `requireStaff()` |
| **DB Changes** | - INSERT `borrows` (1 row per book)<br>- UPDATE `books.available` ลดลง |
| **Locking** | `FOR UPDATE` บน users และ books (ป้องกันยืมทะลุโควต้า) |
| **Output** | redirect ไป `borrows.php` พร้อม flash |
| **Failure Cases** | - User ถึง quota (MAX_BORROW_BOOKS = 3)<br>- Book หมด stock<br>- Double submit |
| **จุดระวัง** | - **Idempotency Key**: `borrow_{userId}_{md5(bookIds)}` ป้องกัน double submit<br>- `decrementAvailable()` มี WHERE `available > 0` ป้องกันติดลบ |

**Code Path:**
```
borrow_form.php → validateCSRFToken() → BorrowService::createBorrow()
               → userRepo->lockById() → borrowRepo->countActiveBorrowsForUpdate()
               → bookRepo->findByIdForUpdate() → bookRepo->decrementAvailable()
               → borrowRepo->create() → commit
```

---

### 3.4 Flow: Return Book (คืนหนังสือ)

| หัวข้อ | รายละเอียด |
|--------|-------------|
| **Goal** | บันทึกการคืนหนังสือ + คำนวณค่าปรับ |
| **Entry Point** | `admin/borrows.php` (POST action=return) |
| **Inputs** | `borrow_id`, `pay_now` (checkbox), `csrf_token` |
| **Validation** | `borrow_id` ต้องมีอยู่และ status = 'borrowing' |
| **Authorization** | `requireStaff()` |
| **DB Changes** | - UPDATE `borrows` (status='returned', return_date, fine_amount)<br>- UPDATE `books.available` เพิ่มขึ้น<br>- INSERT `payments` (ถ้า pay_now && มีค่าปรับ) |
| **Locking** | `FOR UPDATE` บน borrows (ป้องกันคืนซ้ำ) |
| **Output** | redirect กลับ `borrows.php` |
| **Failure Cases** | - Borrow ไม่พบหรือคืนไปแล้ว<br>- Double submit |
| **จุดระวัง** | - ค่าปรับคำนวณจาก `due_date` vs วันนี้<br>- สูตรค่าปรับอยู่ที่ `BorrowService::calculateFine()` |

**สูตรค่าปรับ:**
```php
$daysOverdue × FINE_PER_DAY (default: 10 บาท/วัน)
```

---

### 3.5 Flow: Create Reservation (จองหนังสือ)

| หัวข้อ | รายละเอียด |
|--------|-------------|
| **Goal** | จองหนังสือเพื่อมารับทีหลัง |
| **Entry Point** | `api/reserve_book.php` |
| **Inputs** | `book_id`, `csrf_token` (user_id จาก session) |
| **Validation** | - ต้อง login<br>- `book_id > 0`<br>- ยังไม่มี pending reservation เล่มเดียวกัน |
| **Authorization** | `isLoggedIn()` |
| **DB Changes** | - INSERT `reservations` (status='pending', expires_at)<br>- UPDATE `books.available` ลดลง **ทันที** |
| **Locking** | `FOR UPDATE` บน books |
| **Output** | JSON `{success: true, message: "..."}` |
| **Failure Cases** | - Book หมด<br>- จองเล่มเดิมซ้ำ |
| **จุดระวัง** | - Stock ถูกหักทันทีตอนจอง (กัน stock ไว้)<br>- ถ้าหมดอายุ/ยกเลิก ต้องคืน stock กลับ |

**State Transitions:**
```
pending → fulfilled (admin อนุมัติ → สร้าง borrow)
pending → cancelled (ยกเลิก → คืน stock)
pending → expired   (cron job → คืน stock)
```

---

### 3.6 Flow: Fulfill Reservation (อนุมัติการจอง)

| หัวข้อ | รายละเอียด |
|--------|-------------|
| **Goal** | แปลง reservation เป็น borrow record |
| **Entry Point** | `admin/reservations.php` (POST action=fulfill) |
| **Inputs** | `reservation_id`, `csrf_token` |
| **Validation** | reservation ต้อง status = 'pending' |
| **Authorization** | `requireStaff()` |
| **DB Changes** | - INSERT `borrows`<br>- UPDATE `reservations` (status='fulfilled', borrow_id) |
| **Output** | redirect กลับพร้อม flash |
| **Failure Cases** | - Reservation ไม่ใช่ pending<br>- User ถึง quota |
| **จุดระวัง** | - ไม่ต้องลด stock เพราะหักไปแล้วตอนจอง<br>- ต้องตรวจ quota ของ user ก่อนอนุมัติ |

---

### 3.7 Flow: Delete Book (ลบหนังสือ)

| หัวข้อ | รายละเอียด |
|--------|-------------|
| **Goal** | ลบหนังสือออกจากระบบ |
| **Entry Point** | `admin/books.php` (POST action=delete) |
| **Inputs** | `id`, `csrf_token` |
| **Validation** | - Book ต้องมีอยู่<br>- available = quantity (ไม่มีคนยืมอยู่)<br>- ไม่มี borrow history |
| **Authorization** | `requireStaff()` |
| **DB Changes** | DELETE `books` + ลบไฟล์ cover_image |
| **Locking** | `FOR UPDATE` บน books |
| **Output** | redirect กลับพร้อม flash |
| **Failure Cases** | - มีคนยืมอยู่<br>- มี borrow history |
| **จุดระวัง** | - UI ซ่อนปุ่มลบถ้า `available != quantity`<br>- ลบ cover image file ด้วย |

---

### 3.8 Flow: Update Settings (ตั้งค่าระบบ)

| หัวข้อ | รายละเอียด |
|--------|-------------|
| **Goal** | บันทึกค่าตั้งค่าระบบ (เช่น ชื่อหน่วยงาน, สีบัตร) |
| **Entry Point** | `admin/settings.php` |
| **Inputs** | `org_name`, `card_color_primary`, `card_color_secondary`, `csrf_token` |
| **Validation** | - `org_name` ไม่ว่าง, ไม่เกิน 100 ตัวอักษร<br>- colors ต้อง format `#XXXXXX` |
| **Authorization** | `requireAdmin()` (Admin only!) |
| **DB Changes** | UPDATE/INSERT `settings` (key-value pairs) |
| **Output** | redirect กลับพร้อม flash |
| **Failure Cases** | - Validation error |
| **จุดระวัง** | - เป็นหน้า Admin-only<br>- ใช้ helper `updateSetting($key, $value)` |

---

## 4. Single Source of Truth Map

### 4.1 ตำแหน่งที่ถูกต้องของแต่ละ concern

| Concern | ตำแหน่งที่ถูกต้อง | ไฟล์ |
|---------|-----------------|------|
| **DB Connection** | `getDB()` | `includes/db.php` |
| **Config Values** | `env()` + Constants | `includes/config.php` + `.env` |
| **Auth Check** | `isLoggedIn()`, `isStaff()`, `isAdmin()` | `includes/functions.php` |
| **Access Control** | `requireLogin()`, `requireStaff()`, `requireAdmin()` | `includes/functions.php` |
| **CSRF Token** | `generateCSRFToken()`, `validateCSRFToken()` | `includes/functions.php` |
| **Rate Limiting** | `checkRateLimit()`, `incrementRateLimit()` | `includes/functions.php` |
| **XSS Protection** | `e()` (htmlspecialchars wrapper) | `includes/functions.php` |
| **Password Rules** | `validatePassword()` | `includes/functions.php` |
| **Email Validation** | `isValidEmail()` | `includes/functions.php` |
| **Phone Validation** | `isValidPhone()` | `includes/functions.php` |
| **Name Validation** | `validateName()`, `validateMaxLength()` | `includes/functions.php` |
| **Borrow Rules** | `MAX_BORROW_BOOKS`, `DEFAULT_BORROW_DAYS` | `includes/config.php` |
| **Fine Calculation** | `BorrowService::calculateFine()` | `app/Services/BorrowService.php` |
| **SQL Queries** | Repository methods | `app/Repositories/*.php` |

### 4.2 จุดที่พบ validation ซ้ำ/ใกล้ซ้ำ

| จุดที่พบ | ตำแหน่ง | หมายเหตุ |
|---------|---------|---------|
| Password length check | `validatePassword()` ใน `functions.php` + `AuthService::register()` | ควรเรียก `validatePassword()` ที่เดียว - ปัจจุบัน `register.php` เรียก helper ก่อน |
| Email format check | `isValidEmail()` ใน `functions.php` + `AuthService::register()` | `register.php` ตรวจก่อนส่งให้ Service - OK |
| User exists check | ทั้ง Entry Point และ Service | Entry Point ตรวจคร่าวๆ, Service ตรวจอีกครั้งภายใน transaction - เป็น pattern ที่ถูกต้อง |

---

## 5. Debug Playbook

### 5.1 วิธีเปิด Debug Mode

1. **สร้างไฟล์ `.env`** จาก `.env.example`:
   ```bash
   cp .env.example .env
   ```

2. **แก้ไข `.env`**:
   ```
   APP_DEBUG=true
   ```

3. **ผลลัพธ์**: Error details จะแสดงบนหน้าเว็บแทนที่จะแสดงแค่ "ระบบขัดข้อง"

### 5.2 Log อยู่ที่ไหน

| ประเภท | ตำแหน่ง |
|--------|---------|
| PHP Errors | `logs/` folder หรือ Apache error log |
| DB Connection Error | แสดงบนหน้าเว็บ (ถ้า APP_DEBUG=true) |
| Custom Logs | ใช้ `error_log()` - ไปที่ Apache error log |

### 5.3 เวลาเจอ Error แต่ละประเภท

#### HTTP 400 Bad Request
1. ตรวจ **input validation** ใน Entry Point
2. ดู `$errors` array ที่สร้างจาก validation
3. ตรวจว่า required fields ส่งมาครบไหม

#### HTTP 401 Unauthorized
1. ตรวจว่า user **login อยู่หรือไม่** (`$_SESSION['user_id']`)
2. ดูว่า session หมดอายุหรือไม่ (SESSION_LIFETIME = 3600 วินาที)
3. ตรวจ `isLoggedIn()` ถูกเรียกที่ไหน

#### HTTP 403 Forbidden
1. ตรวจ **CSRF token** - token หมดอายุหรือไม่ตรง
2. ตรวจ **role** - user มี role ที่ต้องการหรือไม่
3. ดู `requireStaff()` หรือ `requireAdmin()` ที่หน้านั้น

#### HTTP 500 Internal Server Error
1. เปิด **APP_DEBUG=true** ดู error message
2. ตรวจ **PDO Exception** - DB connection, SQL syntax
3. ดู **PHP error log** ของ Apache
4. ตรวจ **file permissions** ของ uploads/, logs/

### 5.4 ตัวอย่าง Debug ด้วย curl

#### 1. ทดสอบ Login
```bash
curl -X POST http://localhost/book_borrowing/login.php \
  -d "email=admin@library.com&password=123456" \
  -c cookies.txt -b cookies.txt -L -v
```

#### 2. ทดสอบ Search Books API
```bash
curl "http://localhost/book_borrowing/api/search_books.php?search=php&category=1"
```

#### 3. ทดสอบ Reserve Book (ต้อง login ก่อน)
```bash
# Step 1: Login และเก็บ cookie
curl -X POST http://localhost/book_borrowing/login.php \
  -d "email=member@test.com&password=123456" \
  -c cookies.txt -L

# Step 2: Reserve (ต้องมี csrf_token จาก session)
curl -X POST http://localhost/book_borrowing/api/reserve_book.php \
  -d "book_id=1&csrf_token=YOUR_TOKEN_HERE" \
  -b cookies.txt
```

### 5.5 Debug Checklist

```
□ APP_DEBUG=true ใน .env หรือยัง?
□ ดู Apache error log แล้วหรือยัง?
□ Session เริ่มต้นถูกต้องไหม? (startSession() ถูกเรียกผ่าน bootstrap.php)
□ CSRF token ตรงกับ session ไหม?
□ User มี role ที่ต้องการไหม?
□ DB connection ได้ไหม? (ทดสอบด้วย getDB())
□ ตรวจ input validation ผ่านหมดไหม?
□ Transaction commit หรือ rollback?
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
| อายุการจอง | `app/Services/ReservationService.php` → `createReservation()` param | default 2 days |

### 6.2 ถ้าจะแก้ Validation

| ต้องการแก้ | แก้ที่ไฟล์ |
|-----------|-----------|
| Password length | `includes/config.php` → `MIN_PASSWORD_LENGTH` |
| Email format | `includes/functions.php` → `isValidEmail()` (ใช้ FILTER_VALIDATE_EMAIL) |
| Phone format | `includes/functions.php` → `isValidPhone()` (regex) |
| Name max length | `includes/functions.php` → `validateMaxLength()` |
| Custom field validation | สร้าง function ใหม่ใน `functions.php` |

### 6.3 ถ้าจะแก้ SQL

| ต้องการแก้ | แก้ที่ไฟล์ |
|-----------|-----------|
| Query หนังสือ | `app/Repositories/BookRepository.php` |
| Query การยืม | `app/Repositories/BorrowRepository.php` |
| Query user | `app/Repositories/UserRepository.php` |
| Query การจอง | `app/Repositories/ReservationRepository.php` |
| Query รายงาน | `app/Repositories/ReportRepository.php` |

**กฎสำคัญ:** 
- ห้ามเขียน SQL ใน Entry Point หรือ Service
- ทุก SQL ต้องอยู่ใน Repository เท่านั้น
- ใช้ Prepared Statements (`?` placeholder) เสมอ

### 6.4 ถ้าจะแก้ Permission

| ต้องการแก้ | แก้ที่ไฟล์ |
|-----------|-----------|
| เพิ่ม role ใหม่ | 1. `database/schema.sql` - แก้ ENUM<br>2. `includes/functions.php` - เพิ่ม `isNewRole()`<br>3. สร้าง `requireNewRole()` |
| เปลี่ยน access level | Entry Point - เปลี่ยน `requireStaff()` เป็น `requireAdmin()` หรือกลับกัน |

### 6.5 Checklist: เพิ่ม Field ใหม่ในตาราง

ตัวอย่าง: เพิ่ม field `publisher` ในตาราง `books`

```
□ 1. Database
   └── สร้าง migration file: database/migrations/XXX_add_publisher_to_books.sql
       ALTER TABLE books ADD COLUMN publisher VARCHAR(100) DEFAULT NULL;

□ 2. Repository
   └── app/Repositories/BookRepository.php
       - แก้ findById() ให้ SELECT publisher ด้วย
       - แก้ create() ให้ INSERT publisher
       - แก้ update() ให้ UPDATE publisher

□ 3. Service (ถ้ามี validation)
   └── app/Services/BookService.php
       - รับ $data['publisher'] ใน createBook(), updateBook()

□ 4. Entry Point (Form)
   └── admin/book_form.php
       - เพิ่ม input field สำหรับ publisher
       - รับค่าจาก $_POST['publisher']
       - ส่งไปให้ Service

□ 5. Entry Point (Display)
   └── admin/books.php, book.php
       - แสดง <?= e($book['publisher']) ?>

□ 6. ทดสอบ
   └── ทดสอบ CRUD ครบทุก operation
```

### 6.6 Checklist: เพิ่ม API Endpoint ใหม่

```
□ 1. สร้างไฟล์ใน api/
   └── api/new_endpoint.php

□ 2. Template พื้นฐาน:
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
   // ...
   
   // 5. Call Service
   try {
       $service = new SomeService(getDB());
       $result = $service->doSomething($input);
       echo json_encode(['success' => true, 'data' => $result]);
   } catch (Exception $e) {
       http_response_code(400);
       echo json_encode(['success' => false, 'message' => $e->getMessage()]);
   }

□ 3. ทดสอบด้วย curl
```

### 6.7 สรุป: แก้ 1 จุดให้ครบ

| Layer | คำถาม |
|-------|-------|
| **Database** | ต้องแก้ schema ไหม? สร้าง migration? |
| **Repository** | ต้องเพิ่ม/แก้ SQL query ไหม? |
| **Service** | ต้องเพิ่ม/แก้ business logic ไหม? |
| **Entry Point** | ต้องแก้ form หรือ display ไหม? |
| **Validation** | ต้องเพิ่ม validation rule ไหม? |
| **Test** | ทดสอบ flow ครบถ้วนหรือยัง? |

---

## 7. Quick Reference Card

### 7.1 Helper Functions ที่ใช้บ่อย

```php
// Security
e($string)                    // Escape HTML (ป้องกัน XSS)
generateCSRFToken()           // สร้าง CSRF token
validateCSRFToken($token)     // ตรวจ CSRF token

// Auth
isLoggedIn()                  // ตรวจว่า login อยู่ไหม
isAdmin()                     // ตรวจว่าเป็น admin ไหม
isStaff()                     // ตรวจว่าเป็น staff หรือ admin ไหม
requireLogin()                // บังคับ login (redirect ถ้าไม่)
requireStaff()                // บังคับเป็น staff+
requireAdmin()                // บังคับเป็น admin

// Redirect & Flash
redirect($url)                // redirect + exit
setFlash($type, $message)     // ตั้ง flash message
getFlash()                    // ดึง flash message
displayFlash()                // แสดง flash message (HTML)

// Validation
isValidEmail($email)          // ตรวจ email format
isValidPhone($phone)          // ตรวจ phone format (9-10 digits)
validatePassword($password)   // ตรวจ password (return error หรือ null)
validateMaxLength($val, $max) // ตรวจความยาว

// Rate Limiting
checkRateLimit($key)          // ตรวจว่าเกิน limit ไหม
incrementRateLimit($key)      // เพิ่ม counter
resetRateLimit($key)          // reset counter

// Formatting
formatDate($date, $format)    // จัดรูปแบบวันที่
formatFine($amount)           // จัดรูปแบบค่าปรับ
daysDiff($date1, $date2)      // คำนวณจำนวนวัน
```

### 7.2 Config Constants

```php
// Database
DB_HOST, DB_NAME, DB_USER, DB_PASS

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
