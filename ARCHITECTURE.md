# 🏗️ ARCHITECTURE.md — สถาปัตยกรรมระบบยืมคืนหนังสือ

> เอกสารนี้อธิบาย **"ระบบออกแบบยังไง"** และ **"ทำไมถึงออกแบบแบบนี้"**
> ไม่ต้องอ่านโค้ด ก็เข้าใจโครงสร้างได้
> เหมาะสำหรับ: สอนนักเรียน, อธิบายให้ลูกค้า, ลดคำถามเชิงโครงสร้าง

---

## 🏗️ 1. ภาพรวมสถาปัตยกรรมระบบ

### ระบบนี้ใช้สถาปัตยกรรมแบบไหน?

ระบบนี้ใช้ **Layered Architecture (สถาปัตยกรรมแบบแบ่งชั้น)** ที่ได้แรงบันดาลใจจาก MVC

แต่แทนที่จะใช้ MVC แบบ framework สำเร็จรูป (เช่น Laravel, CodeIgniter) ระบบนี้ **เขียนเอง (vanilla PHP)** เพื่อให้เห็นกลไกภายในชัดเจน ไม่มี "เวทมนตร์" ที่ซ่อนอยู่

```
สถาปัตยกรรม 4 ชั้น:

┌─────────────────────────────────────────────────────┐
│  📋 Controller Layer                                │
│  (ไฟล์ .php ที่ root/, admin/, api/)                │
│  รับ request → ตรวจสิทธิ์ → ส่งต่อ → แสดงผล         │
├─────────────────────────────────────────────────────┤
│  🧠 Service Layer                                   │
│  (app/Services/)                                    │
│  กฎธุรกิจ → ตัดสินใจ → จัดการ transaction            │
├─────────────────────────────────────────────────────┤
│  🗄️ Repository Layer                                │
│  (app/Repositories/)                                │
│  เขียน SQL → ดึง/บันทึกข้อมูล → ไม่มีกฎธุรกิจ       │
├─────────────────────────────────────────────────────┤
│  💾 Database Layer                                   │
│  (MySQL / InnoDB)                                   │
│  เก็บข้อมูลถาวร → บังคับ constraint                  │
└─────────────────────────────────────────────────────┘
```

นอกจาก 4 ชั้นหลัก ยังมี:

```
🔧 Utility / Helper Layer
├── includes/config.php      → ค่าคงที่ทั้งระบบ
├── includes/db.php          → สร้าง PDO connection
├── includes/functions.php   → ฟังก์ชันพื้นฐาน (auth, CSRF, validation, ...)
└── bootstrap.php            → โหลดทุกอย่างเข้าด้วยกัน
```

### ทำไมถึงเลือกแนวนี้?

| เหตุผล | คำอธิบาย |
|--------|---------|
| **เห็นกลไกชัด** | ไม่มี framework ซ่อน — เห็นทุกขั้นตอนตั้งแต่ request เข้ามาจนถึง database |
| **แยกหน้าที่ชัด** | แต่ละไฟล์มีหน้าที่เดียว ไม่ปนกัน → อ่านง่าย แก้ง่าย |
| **เรียนรู้ได้จริง** | เหมาะสำหรับเข้าใจว่า "framework ทำอะไรให้เราบ้าง" ก่อนไปใช้ framework จริง |
| **ไม่ต้องติดตั้ง framework** | รันบน XAMPP/WAMP ได้เลย ไม่ต้อง composer install |
| **ต่อยอดง่าย** | เพิ่ม Service/Repository ใหม่ได้ทันที ไม่ต้องเรียนรู้โครงสร้าง framework |

### เหมาะกับระบบประเภทไหน?

- ระบบ CRUD ขนาดเล็ก-กลาง (ไม่เกิน 10-20 ตาราง)
- ระบบที่ต้องการ transaction + concurrency control
- โปรเจคที่ต้อง **อธิบายให้คนอื่นเข้าใจ** (ส่งงาน, สอน, เดโม)
- Prototype/MVP ก่อนย้ายไป framework

---

## 🧱 2. แนวคิดหลักที่ใช้ในการออกแบบ

### 2.1 Separation of Concerns — "แยกหน้าที่ให้ชัด"

**แนวคิด:** แต่ละส่วนมีหน้าที่เดียว ไม่ทำเกินหน้าที่ตัวเอง

**เปรียบเทียบ:** ร้านอาหาร
- **พนักงานรับออเดอร์** (Controller) ไม่เข้าครัวทำอาหารเอง
- **พ่อครัว** (Service) ไม่ออกไปรับออเดอร์จากลูกค้า
- **พนักงานคลัง** (Repository) ไม่ตัดสินใจว่าจะปรุงอะไร แค่หยิบวัตถุดิบตามที่สั่ง

**ในระบบนี้:**

| ส่วน | ทำ | ไม่ทำ |
|-----|---|----|
| **Controller** | รับ request, ตรวจ CSRF, เรียก Service, แสดงผล | ❌ เขียน SQL, ❌ ตัดสินใจกฎธุรกิจ |
| **Service** | ตัดสินใจตามกฎ, ใช้ transaction, เรียก Repository | ❌ แสดงผล HTML, ❌ เขียน SQL ตรง |
| **Repository** | เขียน SQL, ดึง/บันทึกข้อมูล | ❌ ตัดสินใจกฎธุรกิจ, ❌ ใช้ $_POST/$_SESSION |

### 2.2 Single Source of Truth (SSoT) — "กฎอยู่ที่เดียว"

**แนวคิด:** ข้อมูลหรือกฎแต่ละอย่างควรถูก define ไว้ที่เดียว ถ้าต้องแก้ แก้จุดเดียวมีผลทั้งระบบ

**ตัวอย่างในระบบ:**

| กฎ/ค่า | อยู่ที่ไหน | ใช้ที่ไหนบ้าง |
|--------|----------|-------------|
| **MAX_BORROW_BOOKS = 3** | `config.php` (อ่านจาก `.env`) | BorrowService, ReservationService |
| **FINE_PER_DAY = 10** | `config.php` (อ่านจาก `.env`) | BorrowService::calculateFine() |
| **validateMemberData()** | `functions.php` | MemberService, AuthService, import |
| **validateBookData()** | `functions.php` | book_form, import_books |
| **hashPassword()** | `functions.php` | ทุกจุดที่สร้าง/เปลี่ยน password |
| **emailExists()** | `MemberService` → `UserRepository` | register, create member, update member |

**ทำไมสำคัญ?**
ถ้า "ยืมได้สูงสุดกี่เล่ม" กระจายอยู่ 5 ไฟล์ เวลาแก้จะต้องไล่แก้ทั้ง 5 → พลาดจุดเดียว = บัค
แต่ถ้าอยู่ใน `.env` จุดเดียว → แก้ทีเดียว ทุกจุดได้ค่าใหม่

### 2.3 Thin Controller / Fat Service — "Controller ผอม Service อ้วน"

**แนวคิด:** Controller ไม่ควรมี logic เยอะ ควร "ส่งต่อ" ให้ Service ทำงานหนัก

**ตัวอย่าง:**

```
❌ Controller อ้วน (ไม่ดี):
login.php:
├── query database หา user
├── เทียบ password เอง
├── ตรวจ rate limit เอง
├── สร้าง session เอง
└── HTML ทั้งหมด

✅ Controller ผอม (ระบบนี้):
login.php:
├── ตรวจ CSRF
├── ตรวจ rate limit
├── เรียก AuthService::login()  ← 1 บรรทัด
├── เก็บ session
└── redirect
```

**ข้อดี:**
- Controller อ่านง่าย (เห็นภาพรวมทันที)
- กฎธุรกิจอยู่ที่ Service → ทดสอบง่าย เปลี่ยนง่าย
- ถ้าต้องสร้าง API endpoint ใหม่ → เรียก Service เดิมได้เลย ไม่ต้อง copy logic

### 2.4 Repository Pattern — "แยก SQL ออกจากกฎธุรกิจ"

**แนวคิด:** SQL ทั้งหมดอยู่ใน Repository เท่านั้น Service ไม่รู้จัก SQL

**เปรียบเทียบ:**
- Service บอก Repository ว่า "ขอข้อมูลหนังสือ ID 5" — ไม่สนว่า Repository ไปหามาจาก MySQL, PostgreSQL หรือ file
- Repository จัดการ SQL เอง แล้วคืน array ให้ Service

**ข้อดีหลัก:**
- ถ้าวันหนึ่งเปลี่ยน database → แก้แค่ Repository (Service ไม่ต้องแก้)
- SQL อยู่รวมกัน → หา/แก้ง่าย ไม่กระจายทั่วโค้ด
- ป้องกัน SQL Injection ง่ายกว่า (ดูแลจุดเดียว)

### 2.5 Boundary / Responsibility — "ใครเรียกใคร"

```
ทิศทางการเรียก (→ หมายถึง "เรียกได้"):

Controller → Service → Repository → Database
     │                      ↑
     └──────────────────────┘
     (Controller ไม่ควรเรียก Repository ตรง)

Helper / Functions
     └── ใช้ได้ทุกชั้น (เป็น utility กลาง)
```

**กฎสำคัญ:**
- Controller **ไม่ควร**เรียก Repository ตรง → ต้องผ่าน Service
- Repository **ไม่ควร**เรียก Service → ทิศทางเดียว
- Service เรียก Repository ได้หลายตัว (เช่น BorrowService เรียก BookRepository + BorrowRepository + ReservationRepository)

---

## 🗂️ 3. โครงสร้างโฟลเดอร์

### ภาพรวม

```
book_borrowing/
│
├── 📋 Controller Layer (หน้าเว็บ + API)
│   ├── *.php (root)          → หน้า public (index, login, register, book, ...)
│   ├── admin/*.php           → หน้า admin/staff (books, borrows, members, ...)
│   └── api/*.php             → JSON API endpoints (reserve, cancel, search, ...)
│
├── 🧠 Service Layer (กฎธุรกิจ)
│   └── app/Services/         → 8 Service classes
│       ├── AuthService           → Login, Register, Profile, Password Reset
│       ├── BorrowService         → ยืม, คืน, ค่าปรับ
│       ├── ReservationService    → จอง, อนุมัติ, ยกเลิก, หมดอายุ
│       ├── BookService           → CRUD หนังสือ
│       ├── MemberService         → CRUD สมาชิก + import
│       ├── HomeService           → หน้าแรก (public)
│       ├── DashboardService      → สถิติ admin
│       └── ReportService         → รายงานเชิงลึก
│
├── 🗄️ Repository Layer (SQL)
│   └── app/Repositories/     → 9 Repository classes
│       ├── UserRepository        → ตาราง users
│       ├── BookRepository        → ตาราง books
│       ├── BorrowRepository      → ตาราง borrows
│       ├── ReservationRepository → ตาราง reservations
│       ├── CategoryRepository    → ตาราง categories
│       ├── PaymentRepository     → ตาราง payments
│       ├── PasswordResetRepository → ตาราง password_resets
│       ├── ReportRepository      → หลายตาราง (JOIN สำหรับรายงาน)
│       └── SettingsRepository    → ตาราง settings
│
├── 🔧 Utility / Config / Helpers
│   ├── bootstrap.php         → จุดเริ่มต้น (โหลด config + db + functions)
│   ├── includes/
│   │   ├── config.php        → ค่าคงที่ (อ่านจาก .env)
│   │   ├── db.php            → สร้าง PDO connection (singleton)
│   │   ├── functions.php     → helper functions (auth, CSRF, validation, ...)
│   │   ├── header.php        → HTML header (public)
│   │   ├── footer.php        → HTML footer (public)
│   │   ├── book_grid.php     → component แสดงหนังสือ
│   │   ├── modal.js          → JavaScript สำหรับ modal
│   │   └── report_helper.php → ช่วยสร้างรายงาน PDF
│   │
│   ├── admin/header.php      → HTML header (admin)
│   └── admin/footer.php      → HTML footer (admin)
│
├── 📁 อื่นๆ
│   ├── .env / .env.example   → ค่า config (database, borrow settings, ...)
│   ├── install.php           → ติดตั้งระบบ (สร้างตาราง + ข้อมูลเริ่มต้น)
│   ├── css/                  → Stylesheet
│   ├── uploads/covers/       → รูปปกหนังสือ
│   ├── cron/                 → script สำหรับ cron job (optional)
│   ├── database/             → SQL schema + migration
│   ├── tests/                → ไฟล์ทดสอบ
│   ├── logs/                 → log files
│   └── docs/                 → เอกสารเพิ่มเติม
```

### ใครเรียกใคร? (Dependency Map)

```
📋 login.php
   └── 🧠 AuthService
       └── 🗄️ UserRepository
           └── 💾 users table

📋 admin/borrow_form.php
   └── 🧠 BorrowService
       ├── 🗄️ BorrowRepository    → borrows table
       ├── 🗄️ BookRepository      → books table
       └── 🗄️ ReservationRepository → reservations table

📋 api/reserve_book.php
   └── 🧠 ReservationService
       ├── 🗄️ ReservationRepository → reservations table
       ├── 🗄️ BookRepository        → books table
       └── 🗄️ BorrowRepository      → borrows table
```

### ใครไม่ควรเรียกใคร?

| กฎ | เหตุผล |
|-----|--------|
| ❌ Controller ไม่ควรเขียน SQL ตรง | SQL ต้องอยู่ใน Repository เท่านั้น |
| ❌ Controller ไม่ควรเรียก Repository ตรง | ต้องผ่าน Service (ยกเว้น read-only ง่ายๆ) |
| ❌ Repository ไม่ควรใช้ `$_POST` / `$_SESSION` | Repository ไม่ควรรู้เรื่อง HTTP |
| ❌ Service ไม่ควร echo HTML | Service ไม่ควรรู้เรื่อง UI |
| ❌ Repository ไม่ควรเรียก Service | ทิศทางเดียว: Service → Repository |

---

## 🧠 4. หน้าที่ของแต่ละ Layer

### 4.1 📋 Controller Layer

**ไฟล์:** `*.php` (root), `admin/*.php`, `api/*.php`

**เปรียบเทียบ:** พนักงานต้อนรับ — รับลูกค้า ตรวจบัตร ส่งต่อให้ผู้จัดการ

**หน้าที่:**
- รับ HTTP request (GET/POST)
- ตรวจสิทธิ์ (`requireLogin()`, `requireStaff()`, `requireAdmin()`)
- ตรวจ CSRF token (`validateCSRFToken()`)
- ดึงข้อมูลจาก `$_POST` / `$_GET`
- เรียก Service ให้ทำงาน
- จัดการผลลัพธ์ (redirect, แสดง HTML, คืน JSON)
- แสดง flash message

**ควรมี logic ระดับไหน?**
น้อยที่สุด — แค่ "รับ → ส่งต่อ → แสดงผล"

**ตัวอย่างสิ่งที่ควรอยู่:**
- `if ($_SERVER['REQUEST_METHOD'] === 'POST')` — ตรวจว่าเป็น POST ไหม
- `validateCSRFToken()` — ตรวจ CSRF
- `$authService->login($email, $password)` — เรียก Service
- `redirect(APP_URL . '/index.php')` — redirect

**ตัวอย่างสิ่งที่ไม่ควรอยู่:**
- ❌ SQL query ตรงๆ (`$pdo->query("SELECT * FROM users")`)
- ❌ การคำนวณค่าปรับ
- ❌ การตรวจโควต้ายืม
- ❌ การ hash password

### 4.2 🧠 Service Layer

**ไฟล์:** `app/Services/*.php` (8 Service classes)

**เปรียบเทียบ:** ผู้จัดการ / สมอง — คิด ตัดสินใจ บังคับกฎ

**หน้าที่:**
- ใช้กฎธุรกิจทั้งหมด (business logic)
- จัดการ transaction (BEGIN → COMMIT/ROLLBACK)
- ประสานงานระหว่าง Repository หลายตัว
- validate ข้อมูลเชิงธุรกิจ (โควต้า, stock, ยืมซ้ำ)
- throw Exception เมื่อกฎไม่ผ่าน

**ควรมี logic ระดับไหน?**
เยอะที่สุด — กฎทุกอย่างอยู่ที่นี่

**ตัวอย่างสิ่งที่ควรอยู่:**
- ตรวจโควต้า: `if ($activeBorrows + $pendingReservations >= MAX_BORROW_BOOKS)`
- คำนวณค่าปรับ: `$daysOverdue × FINE_PER_DAY`
- เปิด/ปิด transaction: `$pdo->beginTransaction()` → `$pdo->commit()`
- เรียก Repository: `$this->bookRepo->decrementAvailable($bookId)`

**ตัวอย่างสิ่งที่ไม่ควรอยู่:**
- ❌ `$_POST['email']` — ไม่ควรรู้เรื่อง HTTP
- ❌ `echo "<h1>..."` — ไม่ควรแสดงผล HTML
- ❌ SQL query ตรงๆ — ต้องผ่าน Repository
- ❌ `header("Location: ...")` — ไม่ควร redirect

### 4.3 🗄️ Repository Layer

**ไฟล์:** `app/Repositories/*.php` (9 Repository classes)

**เปรียบเทียบ:** พนักงานคลัง — ไม่ตัดสินใจ แค่หยิบของตามที่สั่ง

**หน้าที่:**
- เขียน SQL (SELECT, INSERT, UPDATE, DELETE)
- ใช้ prepared statements ป้องกัน SQL injection
- คืนข้อมูลเป็น array ให้ Service
- จัดการ pagination, sorting, filtering ใน SQL
- ใช้ `FOR UPDATE` เมื่อ Service ต้องการ lock

**ควรมี logic ระดับไหน?**
แค่ logic ของ SQL — ไม่มีกฎธุรกิจ

**ตัวอย่างสิ่งที่ควรอยู่:**
- `SELECT * FROM books WHERE id = ? FOR UPDATE`
- `UPDATE books SET available = available - 1 WHERE id = ? AND available > 0`
- `INSERT INTO borrows (user_id, book_id, ...) VALUES (?, ?, ...)`

**ตัวอย่างสิ่งที่ไม่ควรอยู่:**
- ❌ ตรวจโควต้า (`if count >= MAX_BORROW_BOOKS`)
- ❌ คำนวณค่าปรับ
- ❌ ใช้ `$_SESSION` หรือ `$_POST`
- ❌ เรียก Service อื่น

**จุดเด่นของ Repository ในระบบนี้:**

| เทคนิค | คำอธิบาย |
|--------|---------|
| **Prepared Statements** | ทุก query ใช้ `?` placeholder — ป้องกัน SQL injection |
| **FOR UPDATE** | ล็อคแถวระหว่าง transaction — ป้องกัน race condition |
| **WHERE guard** | `WHERE available > 0` ใน decrement — ป้องกัน stock ติดลบ |
| **EMULATE_PREPARES = false** | ใช้ native prepared statements จริงๆ — ปลอดภัยกว่า |

### 4.4 💾 Database Layer

**เทคโนโลยี:** MySQL + InnoDB engine

**หน้าที่:**
- เก็บข้อมูลถาวร
- บังคับ constraint (UNIQUE, FOREIGN KEY, NOT NULL)
- รองรับ transaction (InnoDB)
- รองรับ row-level locking (FOR UPDATE)

**ระบบนี้มี 9 ตาราง:**

| ตาราง | หน้าที่ | ความสัมพันธ์ |
|-------|--------|------------|
| **users** | ผู้ใช้ทุก role | → borrows, reservations, payments |
| **books** | หนังสือ | → borrows, reservations, categories |
| **categories** | หมวดหมู่ | ← books |
| **borrows** | การยืม-คืน | → users, books, payments |
| **reservations** | การจอง | → users, books, borrows |
| **payments** | การชำระค่าปรับ | → borrows, users (staff) |
| **rate_limits** | ป้องกัน brute force | standalone |
| **password_resets** | token รีเซ็ตรหัสผ่าน | → users |
| **settings** | ค่าตั้งค่าระบบ | standalone (key-value) |

### 4.5 🔧 Utility / Helper Layer

**ไฟล์:** `includes/functions.php`, `includes/config.php`, `includes/db.php`, `bootstrap.php`

**เปรียบเทียบ:** กล่องเครื่องมือ — ใช้ได้ทุกชั้น

**แบ่งตามหน้าที่:**

| ไฟล์ | หน้าที่ | ตัวอย่างฟังก์ชัน |
|------|--------|---------------|
| **config.php** | อ่าน `.env` → define constants | `MAX_BORROW_BOOKS`, `FINE_PER_DAY` |
| **db.php** | สร้าง PDO connection (singleton) | `getDB()` |
| **functions.php** | helper ทุกประเภท | `e()`, `requireStaff()`, `validateCSRFToken()`, `validateMemberData()` |
| **bootstrap.php** | โหลดทุกอย่าง + autoloader | ทุกหน้าเรียก `require bootstrap.php` |

**functions.php แบ่งเป็นหมวด:**

| หมวด | ฟังก์ชัน | หน้าที่ |
|------|---------|--------|
| 🛡️ **Security** | `e()`, `generateCSRFToken()`, `validateCSRFToken()` | ป้องกัน XSS + CSRF |
| 🔒 **Access Control** | `requireLogin()`, `requireStaff()`, `requireAdmin()` | ตรวจสิทธิ์ (redirect) |
| 🔒 **API Access** | `requireStaffApi()`, `requireAdminApi()` | ตรวจสิทธิ์ (JSON 403) |
| 🚦 **Rate Limit** | `checkRateLimit()`, `incrementRateLimit()`, `resetRateLimit()` | ป้องกัน brute force |
| ✅ **Validation** | `validateMemberData()`, `validateBookData()`, `validatePassword()` | ตรวจข้อมูล (SSoT) |
| 🔐 **Password** | `hashPassword()` | hash password (SSoT) |
| 📦 **Flash** | `setFlash()`, `getFlash()`, `displayFlash()` | ข้อความแสดงครั้งเดียว |
| 🌐 **UI** | `formatDate()`, `formatFine()`, `getBookStatusLabel()` | จัดรูปแบบแสดงผล |
| 🔑 **Idempotency** | `acquireIdempotencyKey()`, `releaseIdempotencyKey()` | ป้องกันกดซ้ำ |

---

## 🔐 5. การออกแบบด้านความปลอดภัย (เชิงโครงสร้าง)

ระบบนี้ไม่ได้แค่ "มีฟีเจอร์ security" แต่ออกแบบให้ **security อยู่ถูกชั้น** ตามหลัก Layered Architecture

### 5.1 SQL Injection — ป้องกันที่ชั้น Repository

```
ป้องกันตรงไหน: Repository Layer เท่านั้น
วิธี: Prepared Statements (native, ไม่ใช่ emulated)
```

| การตั้งค่า | คำอธิบาย |
|-----------|---------|
| **`EMULATE_PREPARES = false`** | ใช้ native prepared statements ของ MySQL จริงๆ |
| **`?` placeholder ทุก query** | ไม่มี string concatenation ใน SQL |
| **อยู่ชั้น Repository** | Service/Controller ไม่เขียน SQL → ไม่มีจุดให้ SQL injection เล็ดลอด |

**เปรียบเทียบ:**
- ❌ `"SELECT * FROM users WHERE email = '$email'"` → อันตราย
- ✅ `"SELECT * FROM users WHERE email = ?"` + `$stmt->execute([$email])` → ปลอดภัย

**ทำไมต้องอยู่ชั้น Repository?**
ถ้า SQL กระจายอยู่ทุกชั้น → ต้องตรวจทุกจุด → พลาดง่าย
แต่ถ้า SQL อยู่แค่ Repository → ตรวจที่เดียว → มั่นใจได้

### 5.2 XSS (Cross-Site Scripting) — ป้องกันที่ชั้น Controller (output)

```
ป้องกันตรงไหน: Controller Layer (ตอนแสดงผล HTML)
วิธี: ฟังก์ชัน e() ครอบทุก output
```

| ฟังก์ชัน | ทำอะไร |
|---------|--------|
| **`e($value)`** | แปลง `<`, `>`, `"`, `'`, `&` เป็น HTML entities |

**ทุกจุดที่แสดงข้อมูลจาก database บน HTML ต้องใช้ `e()`:**
- `<?= e($user['name']) ?>`
- `<?= e($book['title']) ?>`

**ทำไมอยู่ชั้น Controller?**
เพราะ Controller คือชั้นที่สร้าง HTML — จุดที่ข้อมูลจะถูกแสดงผลจริง

### 5.3 CSRF (Cross-Site Request Forgery) — ป้องกันที่ชั้น Controller (input)

```
ป้องกันตรงไหน: Controller Layer (ตอนรับ POST)
วิธี: CSRF token ในทุก form
```

| ขั้นตอน | ฟังก์ชัน | อยู่ตรงไหน |
|---------|---------|-----------|
| สร้าง token | `generateCSRFToken()` | ใส่ใน form (hidden input) |
| ตรวจ token | `validateCSRFToken()` | Controller ตรวจก่อน process POST |

**ทำไมอยู่ชั้น Controller?**
เพราะ CSRF เป็นเรื่องของ HTTP request — Service ไม่ควรรู้เรื่อง HTTP

### 5.4 Password — hash ที่ชั้น Service/Helper

```
ป้องกันตรงไหน: functions.php (helper) + Service Layer
วิธี: bcrypt ผ่าน password_hash() / password_verify()
```

| การจัดการ | อยู่ตรงไหน |
|----------|-----------|
| **hash password** | `hashPassword()` ใน functions.php (SSoT) |
| **เรียก hash** | Service เรียก `hashPassword()` ก่อนส่งให้ Repository |
| **เทียบ password** | `AuthService::login()` ใช้ `password_verify()` |
| **Repository เห็น** | hash เท่านั้น — ไม่เคยเห็น plaintext |

**ทำไม hash อยู่ที่ Helper/Service?**
- Helper `hashPassword()` เป็น SSoT → ทุกจุดใช้ algorithm เดียวกัน
- Service เรียก hash ก่อนส่ง Repository → Repository ไม่ต้องรู้เรื่อง password
- ถ้าวันหนึ่งเปลี่ยน algorithm → แก้แค่ `hashPassword()` จุดเดียว

### 5.5 Authorization — ตรวจสิทธิ์ที่ชั้น Controller

```
ป้องกันตรงไหน: Controller Layer (บรรทัดแรกของทุกหน้า)
วิธี: helper functions (requireLogin, requireStaff, requireAdmin)
```

| ฟังก์ชัน | ใช้ที่ไหน | ผลถ้าไม่ผ่าน |
|---------|----------|------------|
| `requireLogin()` | หน้าที่ต้อง login | redirect → login.php |
| `requireStaff()` | หน้า admin ทั่วไป | redirect → index.php |
| `requireAdmin()` | หน้ารายงาน, ตั้งค่า | redirect → admin/ |
| `requireStaffApi()` | API endpoint | JSON 403 |

**ทำไมอยู่ชั้น Controller?**
- Controller เป็นจุดแรกที่ request เข้ามา → ตรวจสิทธิ์ก่อนทำอะไรทั้งนั้น
- ถ้าไม่ผ่าน → redirect/403 ทันที ไม่ถึง Service

**เสริม:** บาง Service มีการตรวจเพิ่มเช่น "สมาชิกยกเลิกจองได้เฉพาะของตัวเอง" (ป้องกัน IDOR) — นี่เป็น business rule จึงอยู่ที่ Service

### 5.6 Transaction & Locking — จัดการที่ชั้น Service

```
อยู่ตรงไหน: Service Layer
วิธี: PDO beginTransaction/commit/rollBack + SELECT ... FOR UPDATE
```

| เทคนิค | อยู่ที่ | ทำอะไร |
|--------|-------|--------|
| **Transaction** | Service | ครอบหลาย Repository calls เป็นก้อนเดียว |
| **Row Locking** | Repository (SQL) แต่สั่งโดย Service | ล็อคแถวก่อนแก้ ป้องกัน race condition |

**ทำไม Transaction อยู่ที่ Service?**
- Service เป็นคนตัดสินใจว่า "operation นี้ต้อง atomic"
- Service เรียก Repository หลายตัว → ต้องครอบ transaction ข้าม Repository
- Repository ไม่รู้ว่าตัวเองกำลังอยู่ใน transaction ไหม (ไม่จำเป็นต้องรู้)

### สรุปภาพรวม Security ตาม Layer

```
┌───────────────────────────────────────────────────────┐
│ 📋 Controller Layer                                   │
│  ├── CSRF token check      (ป้องกัน CSRF)             │
│  ├── Authorization check    (ตรวจสิทธิ์)              │
│  ├── Rate limit check       (ป้องกัน brute force)     │
│  ├── e() output escaping    (ป้องกัน XSS)             │
│  └── Idempotency key        (ป้องกันกดซ้ำ)            │
├───────────────────────────────────────────────────────┤
│ 🧠 Service Layer                                      │
│  ├── Transaction management  (atomicity)              │
│  ├── Password hashing        (ผ่าน helper SSoT)       │
│  ├── IDOR prevention         (ตรวจ ownership)         │
│  └── Business rule validation (โควต้า, stock, ...)    │
├───────────────────────────────────────────────────────┤
│ 🗄️ Repository Layer                                   │
│  ├── Prepared statements     (ป้องกัน SQL injection)  │
│  ├── FOR UPDATE locking      (ป้องกัน race condition) │
│  └── WHERE guard clauses     (ป้องกัน stock ติดลบ)    │
├───────────────────────────────────────────────────────┤
│ 💾 Database Layer                                      │
│  ├── UNIQUE constraints      (ป้องกันข้อมูลซ้ำ)       │
│  ├── FOREIGN KEY             (บังคับความสัมพันธ์)      │
│  └── InnoDB engine           (รองรับ transaction+lock)│
└───────────────────────────────────────────────────────┘
```

---

## ⚠️ 6. ขอบเขตของสถาปัตยกรรมนี้

### เหมาะกับงานแบบไหน?

| งาน | ทำไมเหมาะ |
|-----|----------|
| **โปรเจคมหาวิทยาลัย** | โครงสร้างชัด มี comment ละเอียด อธิบายได้ |
| **เรียนรู้ backend architecture** | เห็นทุกชั้นชัดเจน ไม่มี framework ซ่อน |
| **เรียนรู้ security** | มีตัวอย่างจริงครบ CSRF, XSS, Rate Limit, Locking |
| **Prototype / MVP** | ใช้ได้เลย ไม่ต้องตั้ง infrastructure ซับซ้อน |
| **Template สำหรับต่อยอด** | เพิ่ม Service/Repository ใหม่ได้ง่าย |
| **ห้องสมุดขนาดเล็ก** | ครบ feature ยืม-คืน-จอง-ค่าปรับ-รายงาน |

### ไม่เหมาะกับงานแบบไหน?

| งาน | ทำไมไม่เหมาะ | ต้องเพิ่มอะไร |
|-----|-------------|-------------|
| **ระบบ enterprise** | ไม่มี caching, queue, horizontal scaling | Redis, RabbitMQ, load balancer |
| **High traffic (>1000 req/s)** | Session file-based, rate limit ใน DB | Redis session, Redis rate limit |
| **Multi-tenant** | ออกแบบ single library | tenant isolation, database per tenant |
| **Microservices** | monolith structure | แยก Service เป็น independent service |
| **SPA + API only** | Controller สร้าง HTML inline | แยก frontend, API-only backend |

### ถ้าเอาไปใช้ production ต้องคิดเพิ่มเรื่องอะไร?

| ด้าน | ปัจจุบัน | ควรเปลี่ยนเป็น |
|------|--------|-------------|
| **Session** | file-based (default PHP) | Redis / Database session |
| **Rate Limit** | เก็บใน MySQL | Redis (เร็วกว่ามาก) |
| **Email** | แสดง link บนหน้าจอ (dev) | SMTP / SendGrid / Mailgun |
| **File Storage** | local disk (uploads/) | S3 / Cloud Storage + CDN |
| **HTTPS** | config พร้อม, ยังไม่บังคับ | SSL certificate + force HTTPS |
| **Backup** | ไม่มี | mysqldump cron / managed DB |
| **Logging** | error_log → file | centralized logging (ELK, Sentry) |
| **Monitoring** | ไม่มี | uptime monitoring, error alerting |
| **Reservation Expiry** | Lazy Expire (ตรวจตอนเปิดหน้า) | cron job ทุก 5-15 นาที |

---

## 🧭 7. แนวทางการต่อยอด

### 7.1 ถ้าจะเพิ่ม feature ใหม่ ควรเพิ่มที่ layer ไหน?

**ตัวอย่าง:** เพิ่มระบบ "แจ้งเตือนหนังสือใกล้ครบกำหนดคืน"

```
ขั้นตอนการเพิ่ม feature:

1️⃣ Repository — สร้างฟังก์ชันดึงข้อมูล
   BorrowRepository::findDueSoon($days)
   → SELECT borrows ที่ due_date ภายใน $days วัน

2️⃣ Service — สร้าง logic แจ้งเตือน
   NotificationService::sendDueReminders()
   → เรียก BorrowRepository → วนส่ง notification

3️⃣ Controller — สร้างจุดเรียก
   cron/send_reminders.php หรือ admin/notifications.php
   → เรียก NotificationService

4️⃣ Config — เพิ่มค่าตั้งค่า (ถ้าจำเป็น)
   .env: REMINDER_DAYS_BEFORE=2
   config.php: define('REMINDER_DAYS_BEFORE', ...)
```

**หลักการ:**
- **ข้อมูลใหม่** → เพิ่ม Repository (SQL)
- **กฎใหม่** → เพิ่ม Service (business logic)
- **หน้าใหม่** → เพิ่ม Controller (.php ที่ root/admin/api)
- **ค่าตั้งค่าใหม่** → เพิ่มใน `.env` + `config.php`
- **ฟังก์ชันใช้ซ้ำ** → เพิ่มใน `functions.php`

### 7.2 ถ้าจะเปลี่ยน Database ควรแก้ส่วนไหน?

**ตัวอย่าง:** เปลี่ยนจาก MySQL เป็น PostgreSQL

```
แก้ที่ไหน:

✅ แก้: includes/db.php
   → เปลี่ยน DSN จาก mysql: เป็น pgsql:

✅ แก้: app/Repositories/*.php
   → แก้ SQL syntax ที่ต่างกัน (เช่น LIMIT, auto_increment)

❌ ไม่ต้องแก้: app/Services/*.php
   → Service ไม่รู้จัก SQL → ไม่ได้รับผลกระทบ

❌ ไม่ต้องแก้: Controller (*.php)
   → Controller ไม่รู้จัก database → ไม่ได้รับผลกระทบ
```

**นี่คือข้อดีของ Repository Pattern:**
เปลี่ยน database → แก้แค่ Repository + connection — ส่วนที่เหลือไม่ต้องแตะ

### 7.3 ถ้าจะทำ API / Frontend แยก ควรเริ่มตรงไหน?

**ตัวอย่าง:** ทำ React/Vue frontend แยก + PHP API backend

```
ขั้นตอน:

1️⃣ สร้าง API Controller ใหม่ (api/*.php)
   ├── ใช้ Service เดิมได้เลย! (ไม่ต้องเขียนใหม่)
   ├── เปลี่ยน output จาก HTML → JSON
   └── เปลี่ยน auth จาก session → token (JWT)

2️⃣ ตัวอย่าง:
   api/books.php (ใหม่):
   ├── ตรวจ JWT token
   ├── เรียก BookService::getBooks()  ← Service เดิม!
   └── echo json_encode($books)

3️⃣ Frontend (React/Vue):
   ├── fetch('/api/books.php')
   └── แสดงผลเอง
```

**จุดสำคัญ:**
- **Service Layer ใช้ซ้ำได้เลย** — นี่คือข้อดีของ Thin Controller
- แก้แค่ Controller (เปลี่ยนจาก HTML → JSON)
- เพิ่ม JWT authentication แทน session

### 7.4 ถ้าจะเพิ่ม Service ใหม่?

```
ขั้นตอน:

1️⃣ สร้างไฟล์ app/Services/XxxService.php
   namespace App\Services;

2️⃣ สร้าง Repository (ถ้าต้องการตารางใหม่)
   app/Repositories/XxxRepository.php

3️⃣ autoloader ใน bootstrap.php จะโหลดให้อัตโนมัติ
   (ไม่ต้องแก้ bootstrap.php ถ้า namespace ตรง)

4️⃣ เรียกใช้จาก Controller:
   $xxxService = new \App\Services\XxxService(getDB());
```

---

## 📌 8. สรุปสำหรับผู้ซื้อ

### ระบบนี้สอนแนวคิดอะไร?

| แนวคิด | เรียนรู้จากส่วนไหน |
|--------|-----------------|
| **Layered Architecture** | โครงสร้าง Controller → Service → Repository → Database |
| **Separation of Concerns** | แต่ละไฟล์มีหน้าที่เดียว ไม่ปนกัน |
| **Single Source of Truth** | ค่าคงที่ใน config, validation ใน helper, hash ใน helper |
| **Repository Pattern** | SQL ทั้งหมดอยู่ใน Repository เท่านั้น |
| **Thin Controller / Fat Service** | Controller ส่งต่อ Service ทำงานหนัก |
| **Transaction & Atomicity** | หลาย operation เป็นก้อนเดียว (all or nothing) |
| **Row Locking** | ป้องกัน race condition ด้วย SELECT ... FOR UPDATE |
| **Security in Depth** | CSRF, XSS, SQL Injection, Rate Limit, Session — แต่ละอย่างอยู่ถูกชั้น |
| **Idempotency** | ป้องกันกดซ้ำด้วย key-based dedup |
| **Stock Management** | จัดการ available ด้วย WHERE guard ป้องกันติดลบ |

### ได้ฝึกคิดแบบสถาปนิกระบบยังไง?

1. **คิดเป็นชั้น** — ไม่โยนทุกอย่างรวมกัน แต่แยกหน้าที่ชัดเจน
2. **คิดเรื่อง concurrency** — ถ้า 2 คนกดพร้อมกัน จะเกิดอะไร? ล็อคยังไง?
3. **คิดเรื่อง atomicity** — ถ้าทำครึ่งทางแล้วพัง ข้อมูลจะถูกไหม?
4. **คิดเรื่อง security** — ป้องกันอะไรบ้าง? ป้องกันตรงไหน? ทำไมต้องชั้นนั้น?
5. **คิดเรื่อง SSoT** — กฎนี้ถูก define กี่ที่? ถ้าแก้จุดเดียว จะกระทบอะไรบ้าง?
6. **คิดเรื่อง extensibility** — ถ้าจะเพิ่ม feature ต้องแก้กี่ไฟล์? กี่ชั้น?

### เหมาะกับใครมากที่สุด?

- **นักศึกษา** ที่ต้องส่งโปรเจค — ได้ระบบสมบูรณ์ + เข้าใจโครงสร้างเพื่ออธิบายได้
- **ผู้สอน** ที่ต้องการตัวอย่าง backend ที่เขียนดี มีโครงสร้างชัด อธิบายง่าย
- **ผู้เริ่มต้น backend** ที่อยากเห็นว่า "ระบบจริงออกแบบยังไง" ก่อนไปใช้ framework
- **Freelancer** ที่อยากมี template ไว้ต่อยอด — เพิ่ม feature ได้ง่ายเพราะแยกชั้นชัด
- **คนที่เตรียมไปสัมภาษณ์งาน** — มีตัวอย่าง architecture จริงที่อธิบายได้

### สิ่งที่ได้จากระบบนี้ ไม่ใช่แค่ "โค้ดที่ทำงานได้"

แต่คือ **"วิธีคิดในการออกแบบระบบ"** ที่ใช้ได้กับทุกภาษา ทุก framework:
- วิธีแยกชั้น
- วิธีจัดการ transaction
- วิธีป้องกัน race condition
- วิธีวาง security
- วิธีจัดโครงสร้างให้ต่อยอดง่าย

เมื่อเข้าใจแนวคิดเหล่านี้แล้ว ไม่ว่าจะย้ายไป Laravel, Node.js, Python หรือภาษาอื่น หลักการเดียวกันใช้ได้ทั้งหมด

---

> 📖 **เอกสารอื่นที่เกี่ยวข้อง:**
> - `README.md` — ภาพรวมระบบ + วิธีติดตั้ง + คำอธิบายสำหรับมือใหม่
> - `FLOW.md` — ภาพรวมการทำงาน (flow ของแต่ละ feature)
> - `FAQ.md` — คำถามที่พบบ่อย
> - `STUDY_GUIDE.md` — คู่มือเรียนรู้ระบบเชิงลึก
