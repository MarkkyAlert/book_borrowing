# 🏛️ ARCHITECTURE — สถาปัตยกรรมระบบยืมคืนหนังสือ

> เอกสารนี้อธิบาย "ระบบออกแบบมายังไง" และ "ทำไมถึงออกแบบแบบนี้"
> ไม่ต้องอ่านโค้ด ไม่ต้องเข้าใจ PHP ก็อ่านเข้าใจได้
> เหมาะสำหรับ: เรียนรู้แนวคิดสถาปัตยกรรม, สอน, อธิบายให้ลูกค้าฟัง

---

## 🏗️ 1. ภาพรวมสถาปัตยกรรมระบบ

### ระบบนี้ใช้สถาปัตยกรรมแบบไหน?

ใช้ **Layered Architecture** (สถาปัตยกรรมแบบแบ่งชั้น)

```
┌─────────────────────────────────────────┐
│  👤 ผู้ใช้งาน (Browser)                  │
└────────────────┬────────────────────────┘
                 │
┌────────────────▼────────────────────────┐
│  📋 Controller Layer                     │
│  (ไฟล์ .php ที่ root / admin / api)      │
│  รับ request → ตรวจสิทธิ์ → ส่งต่อ        │
└────────────────┬────────────────────────┘
                 │
┌────────────────▼────────────────────────┐
│  🧠 Service Layer                        │
│  (app/Services/)                         │
│  กฎธุรกิจ → ตัดสินใจ → ควบคุม transaction │
└────────────────┬────────────────────────┘
                 │
┌────────────────▼────────────────────────┐
│  🗄️ Repository Layer                     │
│  (app/Repositories/)                     │
│  แปลงเป็น SQL → ดึง/บันทึกข้อมูล         │
└────────────────┬────────────────────────┘
                 │
┌────────────────▼────────────────────────┐
│  💾 Database Layer                       │
│  (MySQL)                                 │
│  เก็บข้อมูลทั้งหมด                       │
└─────────────────────────────────────────┘
```

นอกจากนี้ยังมี **Utility Layer** ที่ตัดขวาง (ใช้ได้ทุกชั้น):

```
┌─────────────────────────────────────────┐
│  🔧 Utility / Helper Layer               │
│  (includes/)                              │
│  config, db connection, functions         │
│  ← ทุกชั้นเรียกใช้ได้                     │
└─────────────────────────────────────────┘
```

### ทำไมถึงเลือกแนวนี้?

| เหตุผล | คำอธิบาย |
|--------|---------|
| **เข้าใจง่าย** | แต่ละชั้นมีหน้าที่ชัด ไม่ปนกัน มือใหม่อ่านตามได้ |
| **แก้ไขง่าย** | อยากแก้กฎธุรกิจ → แก้แค่ Service, อยากแก้ SQL → แก้แค่ Repository |
| **ไม่ต้องใช้ framework** | ใช้ PHP ล้วน เห็นทุกขั้นตอนชัดเจน ไม่มี "เวทมนตร์" ซ่อน |
| **เรียนรู้ได้จริง** | แนวคิดเดียวกับ Laravel, Spring Boot, Django แต่ง่ายกว่า |
| **Deploy ง่าย** | แค่ copy ไฟล์ไปวาง ไม่ต้อง `composer install` หรือ build |

### เหมาะกับระบบประเภทไหน?

- ✅ ระบบ CRUD ขนาดเล็ก-กลาง (สร้าง, อ่าน, แก้ไข, ลบข้อมูล)
- ✅ ระบบที่มีกฎธุรกิจชัดเจน (เช่น ยืมได้กี่เล่ม, ค่าปรับเท่าไหร่)
- ✅ ระบบที่ต้องการ concurrency control (เช่น stock หนังสือ)
- ✅ ระบบที่ทีมเล็ก (1-3 คน) ดูแล
- ❌ ไม่เหมาะกับระบบ microservices หรือ event-driven ขนาดใหญ่

---

## 🧱 2. แนวคิดหลักที่ใช้ในการออกแบบ

### 📐 Separation of Concerns — "แยกหน้าที่ให้ชัด"

**แนวคิด:** แต่ละส่วนของระบบควรรับผิดชอบเรื่องเดียว ไม่ปนกัน

เปรียบเทียบกับร้านอาหาร:
- พนักงานเสิร์ฟ → รับออเดอร์ ไม่ใช่ทำอาหาร
- พ่อครัว → ทำอาหาร ไม่ใช่เก็บเงิน
- แคชเชียร์ → เก็บเงิน ไม่ใช่ล้างจาน

ในระบบนี้:
- **Controller** → รับ request + ตรวจสิทธิ์ (ไม่มีกฎธุรกิจ ไม่มี SQL)
- **Service** → กฎธุรกิจ + ตัดสินใจ (ไม่รู้จัก HTTP, ไม่รู้จัก HTML)
- **Repository** → ดึง/บันทึกข้อมูล (ไม่มีกฎธุรกิจ ไม่ตัดสินใจ)

**ข้อดี:** ถ้าอยากเปลี่ยน "ยืมได้ 5 เล่ม แทน 3 เล่ม" → แก้แค่ config/Service ไม่กระทบ Controller หรือ Repository เลย

---

### 🎯 Single Source of Truth — "แหล่งเดียวของความจริง"

**แนวคิด:** กฎสำคัญควรอยู่ที่เดียว ไม่กระจาย ถ้าจะแก้ แก้จุดเดียวมีผลทั้งระบบ

ตัวอย่างในโค้ดนี้:

| กฎ/ค่า | อยู่ที่ไหน (แหล่งเดียว) |
|--------|----------------------|
| ค่าตั้งค่าระบบ (วันยืม, ค่าปรับ) | `includes/config.php` (อ่านจาก `.env`) |
| การ hash password | `hashPassword()` ใน `functions.php` |
| การ validate ข้อมูลสมาชิก | `validateMemberData()` ใน `functions.php` |
| การสร้าง user | `MemberService::createMember()` |
| กฎการยืม (โควต้า, stock) | `BorrowService::createBorrow()` |
| กฎการจอง (state transition) | `ReservationService` |
| การตั้งค่ารายงาน | `includes/report_helper.php` |

**ทำไมสำคัญ?**

สมมติระบบสร้าง user ได้ 4 ทาง (register, admin เพิ่ม, import CSV, AJAX เพิ่มเร็ว)
ถ้าทุกทางเขียน validate email ซ้ำกันเอง → ถ้าอยากเปลี่ยนกฎ ต้องแก้ 4 จุด อาจลืมจุดใดจุดหนึ่ง

ในระบบนี้ ทั้ง 4 ทาง **ไปจบที่ MemberService จุดเดียว** → แก้ที่เดียวมีผลทุกทาง

---

### 🧱 Boundary / Responsibility — "ขอบเขตความรับผิดชอบ"

**แนวคิด:** แต่ละชั้นมีกฎว่า "ทำอะไรได้" และ "ทำอะไรไม่ได้"

```
📋 Controller
├── ✅ ได้: รับ request, ตรวจสิทธิ์, ตรวจ CSRF, เรียก Service, แสดงผล
├── ❌ ไม่ได้: เขียน SQL, คำนวณค่าปรับ, ตัดสินใจว่ายืมได้ไหม

🧠 Service
├── ✅ ได้: ตรวจกฎธุรกิจ, คำนวณ, เปิด transaction, เรียก Repository
├── ❌ ไม่ได้: อ่าน $_POST, สร้าง HTML, redirect

🗄️ Repository
├── ✅ ได้: เขียน SQL, ดึงข้อมูล, บันทึกข้อมูล
├── ❌ ไม่ได้: ตัดสินใจว่ายืมได้ไหม, ตรวจโควต้า, คำนวณค่าปรับ
```

**เปรียบเทียบ:** เหมือนระบบราชการ — แต่ละแผนกมีหน้าที่ชัด ไม่ก้าวก่ายกัน ถ้าทุกคนทำทุกอย่าง จะวุ่นวายและเกิดข้อผิดพลาด

---

### 📋 Thin Controller / Fat Service — "Controller บาง Service หนา"

**แนวคิด:** Controller ควรทำน้อยที่สุด — แค่ "รับ" และ "ส่งต่อ" ส่วนงานจริงทั้งหมดอยู่ใน Service

ตัวอย่าง: "คืนหนังสือ" ใน `admin/borrows.php`

```
Controller ทำแค่:
├── ตรวจว่า login + เป็น staff
├── ตรวจ CSRF token
├── ตรวจ idempotency key (ป้องกันกดซ้ำ)
├── เรียก BorrowService::returnBook(borrowId, staffId, payNow)
├── แสดงผลสำเร็จ/ล้มเหลว
└── จบ

Service ทำงานจริง:
├── เปิด transaction
├── ล็อคข้อมูลรายการยืม
├── ตรวจว่าคืนไปแล้วหรือยัง
├── คำนวณค่าปรับ
├── อัปเดตสถานะ
├── คืน stock
├── บันทึก payment (ถ้าจ่ายทันที)
└── commit / rollback
```

**ทำไมต้องแบบนี้?**
- ถ้าวันหนึ่งอยากเพิ่ม "คืนผ่าน API" → สร้าง Controller ใหม่ เรียก Service เดิมได้เลย ไม่ต้องเขียนกฎธุรกิจซ้ำ
- ถ้ากฎธุรกิจอยู่ใน Controller → ต้อง copy-paste ไปทุก Controller = ผิดหลัก Single Source of Truth

---

### 🗃️ Repository Pattern — "แยกการคุยกับ database ออกมา"

**แนวคิด:** คำสั่ง SQL ทั้งหมดควรอยู่ใน Repository ไม่กระจายทั่วระบบ

```
❌ แบบที่ไม่ดี (SQL อยู่ใน Controller):
Controller → เขียน SQL → ได้ข้อมูล → ตรวจกฎ → เขียน SQL อีก → แสดงผล
(ทุกอย่างปนกัน แก้ยาก test ยาก)

✅ แบบที่ระบบนี้ใช้:
Controller → Service → Repository → SQL → Database
(แต่ละชั้นแยกชัด แก้ SQL ก็แก้แค่ Repository)
```

**ข้อดี:**
- ถ้าวันหนึ่งเปลี่ยนจาก MySQL เป็น PostgreSQL → แก้แค่ Repository ไม่ต้องแก้ Service หรือ Controller
- SQL อยู่รวมกันที่เดียว ง่ายต่อการ review และ optimize
- Service ไม่ต้องรู้เรื่อง SQL เลย แค่สั่ง "ขอข้อมูล" หรือ "บันทึกข้อมูล"

---

## 🗂️ 3. โครงสร้างโฟลเดอร์

### แต่ละโฟลเดอร์มีหน้าที่อะไร?

```
book_borrowing/
│
├── *.php (root)        📋 Controller Layer (Public)
│                       หน้าเว็บสำหรับผู้ใช้ทั่วไป + สมาชิก
│                       เช่น login, register, ดูหนังสือ, ดูประวัติยืม
│
├── admin/              📋 Controller Layer (Admin/Staff)
│                       หน้าเว็บสำหรับเจ้าหน้าที่ + ผู้ดูแล
│                       เช่น จัดการหนังสือ, ยืม-คืน, รายงาน
│
├── api/                📋 Controller Layer (API)
│                       Endpoint สำหรับ AJAX (JavaScript เรียกใช้)
│                       เช่น จองหนังสือ, ค้นหา, เพิ่มสมาชิกเร็ว
│
├── app/
│   ├── Services/       🧠 Service Layer
│   │                   กฎธุรกิจ, transaction, validation
│   │
│   └── Repositories/   🗄️ Repository Layer
│                       SQL queries, data access, row locking
│
├── includes/           🔧 Utility / Helper Layer
│                       config, db, functions, UI components
│
├── database/           💾 Database Schema
│                       โครงสร้างตาราง, ข้อมูลตัวอย่าง, migrations
│
├── uploads/            📁 ไฟล์ที่ user upload (รูปปกหนังสือ)
├── logs/               📁 ไฟล์ log ข้อผิดพลาด
├── css/                📁 Stylesheet เพิ่มเติม
├── cron/               📁 งาน schedule (ล้าง token, expire การจอง)
├── docs/               📁 เอกสารประกอบ
└── .env                ⚙️ ค่าตั้งค่าระบบ
```

### ใครควรเรียกใคร? (ทิศทางที่ถูกต้อง)

```
✅ ทิศทางที่ถูกต้อง (บนลงล่าง):

Controller  →  Service  →  Repository  →  Database
                 ↑
              Utility (ใช้ได้ทุกชั้น)
```

```
❌ ทิศทางที่ห้าม (ล่างขึ้นบน):

Repository  ✗→  Service     (Repository ไม่ควรเรียก Service)
Service     ✗→  Controller  (Service ไม่ควรเรียก Controller)
Repository  ✗→  $_POST      (Repository ไม่ควรอ่าน request โดยตรง)
Controller  ✗→  SQL         (Controller ไม่ควรเขียน SQL โดยตรง)
```

### ตัวอย่างที่เห็นในโค้ดจริง

```
admin/borrows.php (Controller)
  └── เรียก BorrowService::returnBook()

BorrowService (Service)
  ├── เรียก BorrowRepository::findByIdForUpdate()
  ├── เรียก BookRepository::incrementAvailable()
  └── เรียก PaymentRepository::create()

BorrowRepository (Repository)
  └── เขียน SQL: UPDATE borrows SET status = 'returned' ...
```

สังเกต: **Controller ไม่เคยเรียก Repository โดยตรงสำหรับ write operations**
(ยกเว้นบางกรณี read-only ที่ไม่มี business logic เช่น ดึงรายการแสดงผล)

---

## 🧠 4. หน้าที่ของแต่ละ Layer

### 📋 Controller Layer

**ตำแหน่ง:** ไฟล์ .php ที่ root, `admin/`, `api/`

**หน้าที่:**
- รับ HTTP request จากผู้ใช้ (GET/POST)
- ตรวจว่า login แล้วหรือยัง + มีสิทธิ์ไหม
- ตรวจ CSRF token
- ตรวจ idempotency key (ป้องกันกดซ้ำ)
- ดึงข้อมูลจาก `$_POST` / `$_GET` แล้วส่งต่อให้ Service
- รับผลจาก Service แล้วแสดงผล (HTML หรือ JSON)

**ควรมี logic ระดับไหน?**
น้อยที่สุดเท่าที่จะทำได้ แค่ "รับ" "ส่งต่อ" "แสดงผล"

| ✅ ควรอยู่ใน Controller | ❌ ไม่ควรอยู่ใน Controller |
|----------------------|------------------------|
| `requireStaff()` | คำนวณค่าปรับ |
| `validateCSRFToken()` | ตรวจว่ายืมเกินโควต้าไหม |
| `$_POST['book_id']` | เขียน SQL |
| `redirect()` / `echo json` | เปิด transaction |
| เรียก `$service->method()` | ล็อคข้อมูลด้วย FOR UPDATE |

---

### 🧠 Service Layer

**ตำแหน่ง:** `app/Services/`

**หน้าที่:**
- ตัดสินใจว่า "ทำได้" หรือ "ทำไม่ได้" ตามกฎธุรกิจ
- validate ข้อมูลเชิงธุรกิจ (เช่น stock พอไหม, ซ้ำไหม)
- เปิด/ปิด transaction (ทำครบหรือไม่ทำเลย)
- เรียก Repository หลายตัวเพื่อทำงานร่วมกัน
- คำนวณค่าต่างๆ (ค่าปรับ, วันกำหนดคืน)

**ควรมี logic ระดับไหน?**
มากที่สุด — นี่คือ "สมอง" ของระบบ

| ✅ ควรอยู่ใน Service | ❌ ไม่ควรอยู่ใน Service |
|--------------------|----------------------|
| ตรวจโควต้ายืม | อ่าน `$_POST` |
| คำนวณค่าปรับ | สร้าง HTML |
| เปิด transaction | redirect |
| เรียก Repository | เช็ค CSRF token |
| ล็อคข้อมูล (ผ่าน Repository) | แสดง flash message |

**Service ที่มีในระบบ:**

| Service | รับผิดชอบ |
|---------|----------|
| `AuthService` | login, register, password reset, profile |
| `BorrowService` | ยืม, คืน, จ่ายค่าปรับ |
| `ReservationService` | จอง, อนุมัติ, ยกเลิก, expire |
| `BookService` | เพิ่ม, แก้, ลบหนังสือ |
| `MemberService` | เพิ่ม, แก้, ลบสมาชิก |
| `DashboardService` | รวมสถิติสำหรับ dashboard |
| `ReportService` | รวมข้อมูลสำหรับรายงาน |
| `HomeService` | ข้อมูลหน้าแรก (หนังสือ, หมวดหมู่) |

---

### 🗄️ Repository Layer

**ตำแหน่ง:** `app/Repositories/`

**หน้าที่:**
- แปลงคำสั่งจาก Service ให้เป็น SQL
- ดึงข้อมูลจาก database
- บันทึก/อัปเดต/ลบข้อมูลใน database
- ล็อคข้อมูลด้วย `SELECT ... FOR UPDATE` (ตามคำสั่ง Service)

**ควรมี logic ระดับไหน?**
น้อยมาก — แค่ "ไปหยิบของ" หรือ "ไปเก็บของ" ตามที่ถูกสั่ง

| ✅ ควรอยู่ใน Repository | ❌ ไม่ควรอยู่ใน Repository |
|----------------------|------------------------|
| `SELECT * FROM books WHERE id = ?` | ตรวจว่ายืมเกินโควต้าไหม |
| `UPDATE books SET available = available - 1` | คำนวณค่าปรับ |
| `INSERT INTO borrows (...)` | เปิด/ปิด transaction |
| `SELECT ... FOR UPDATE` | ตัดสินใจว่าลบได้ไหม |
| `JOIN`, `WHERE`, `ORDER BY` | อ่าน `$_POST` |

**Repository ที่มีในระบบ:**

| Repository | จัดการตาราง |
|-----------|------------|
| `UserRepository` | `users` |
| `BookRepository` | `books` |
| `BorrowRepository` | `borrows` |
| `ReservationRepository` | `reservations` |
| `CategoryRepository` | `categories` |
| `PaymentRepository` | `payments` |
| `ReportRepository` | อ่านข้อมูลหลายตาราง (read-only) |
| `SettingsRepository` | `settings` |
| `PasswordResetRepository` | `password_resets` |

---

### 💾 Database Layer

**ตำแหน่ง:** MySQL + `database/schema.sql`

**หน้าที่:**
- เก็บข้อมูลทั้งหมดอย่างถาวร
- บังคับ constraint (เช่น `available >= 0`, `UNIQUE email`)
- จัดการ index เพื่อให้ค้นหาเร็ว
- รองรับ transaction + row locking

ระบบนี้มี 9 ตาราง: `users`, `books`, `categories`, `borrows`, `reservations`, `payments`, `password_resets`, `settings`, `rate_limits`

---

### 🔧 Utility / Helper Layer

**ตำแหน่ง:** `includes/`

**หน้าที่:**
ฟังก์ชันที่ **ทุกชั้นใช้ร่วมกัน** ไม่จัดอยู่ในชั้นใดชั้นหนึ่ง

| ไฟล์ | หน้าที่ |
|------|---------|
| `config.php` | โหลดค่าตั้งค่าจาก `.env` → define เป็น constants |
| `db.php` | สร้าง PDO connection (singleton) |
| `functions.php` | ฟังก์ชันช่วยเหลือทั้งหมด |
| `header.php` / `footer.php` | UI template สำหรับหน้า public |
| `book_grid.php` | UI component แสดงตารางหนังสือ (ใช้ซ้ำ) |
| `modal.js` | กล่อง popup ยืนยัน (ใช้แทน `confirm()`) |
| `report_helper.php` | ตั้งค่ารายงาน (map ชื่อ, หัวตาราง, filename) |

**ฟังก์ชันสำคัญใน `functions.php`:**

| หมวด | ฟังก์ชัน | หน้าที่ |
|------|---------|---------|
| 🛡️ Security | `e()` | escape HTML ป้องกัน XSS |
| 🛡️ Security | `generateCSRFToken()` / `validateCSRFToken()` | ป้องกัน CSRF |
| 🛡️ Security | `checkRateLimit()` / `incrementRateLimit()` | ป้องกัน brute force |
| 🛡️ Security | `hashPassword()` | hash password ด้วย bcrypt |
| 🔒 Access | `requireLogin()` / `requireStaff()` / `requireAdmin()` | ตรวจสิทธิ์ |
| 📦 Flash | `setFlash()` / `getFlash()` / `displayFlash()` | ข้อความแจ้งเตือนครั้งเดียว |
| ✅ Validate | `validateMemberData()` / `validatePassword()` | ตรวจข้อมูล |
| 🌐 UI | `formatDate()` / `formatFine()` / `daysDiff()` | จัดรูปแบบแสดงผล |

---

### 🔗 Bootstrap — จุดเชื่อมทุกชั้นเข้าด้วยกัน

**ตำแหน่ง:** `bootstrap.php`

ทุกหน้าของระบบเริ่มต้นด้วย `require_once bootstrap.php` ซึ่งทำหน้าที่:

```
bootstrap.php
├── 1. โหลด config.php    → ค่าตั้งค่าทั้งหมดพร้อมใช้
├── 2. โหลด db.php        → database connection พร้อมใช้
├── 3. โหลด functions.php → helper functions พร้อมใช้
├── 4. ล้าง idempotency keys ที่หมดอายุ
├── 5. ตั้ง autoloader     → โหลด class ใน app/ อัตโนมัติ
└── 6. ตั้ง error reporting → ตาม APP_DEBUG
```

> ⚠️ **ห้ามแก้ลำดับ require** ใน bootstrap.php — ต้อง config ก่อน db ก่อน functions เพราะแต่ละตัวพึ่งพาตัวก่อนหน้า

---

## 🔐 5. การออกแบบด้านความปลอดภัย (เชิงโครงสร้าง)

### ป้องกันภัยคุกคามตรงไหน?

```
📋 Controller Layer                 🛡️ ป้องกัน
├── CSRF Token                      → ถูกหลอกให้กด submit โดยไม่รู้ตัว
├── Rate Limiting                   → เดารหัสผ่านอัตโนมัติ (brute force)
├── Access Control (requireStaff)   → คนไม่มีสิทธิ์เข้าหน้า admin
└── Idempotency Key                 → กดปุ่มซ้ำ (double submit)

🧠 Service Layer                    🛡️ ป้องกัน
├── Business Rule Validation        → ข้อมูลผิดกฎ (ยืมเกินโควต้า)
├── Transaction                     → ข้อมูลค้างครึ่งเดียว
└── Row Locking (FOR UPDATE)        → 2 คนแก้ข้อมูลเดียวกันพร้อมกัน

🗄️ Repository Layer                 🛡️ ป้องกัน
├── Prepared Statements             → SQL Injection
└── Parameterized Queries           → SQL Injection

🔧 Utility Layer                    🛡️ ป้องกัน
├── e() - HTML escape               → XSS (แทรก script ในหน้าเว็บ)
├── hashPassword() - bcrypt          → อ่านรหัสผ่านจาก database
├── startSession() - secure config   → Session hijacking
└── session_regenerate_id()          → Session fixation
```

### SQL Injection ป้องกันตรงไหน?

**Repository Layer** — ทุก SQL query ใช้ Prepared Statements

```
❌ ไม่ทำแบบนี้:
"SELECT * FROM users WHERE email = '$email'"
→ ถ้า email = "'; DROP TABLE users; --" จะลบตารางทั้งหมด!

✅ ทำแบบนี้ (ในทุก Repository):
"SELECT * FROM users WHERE email = ?" + bind $email แยก
→ ตัวแปรถูกส่งแยกจากคำสั่ง SQL → แทรกคำสั่งอันตรายไม่ได้
```

### Password อยู่ชั้นไหน?

```
Controller (login.php) → รับ password จาก form
                          ↓
Service (AuthService)  → เรียก password_verify() เทียบกับ hash
                          ↓
Utility (functions.php) → hashPassword() ใช้ bcrypt เข้ารหัส
                          ↓
Repository             → เก็บ hash ลง database (ไม่เคยเก็บ password จริง)
```

- **hash** ทำที่ Utility Layer (เป็น Single Source of Truth)
- **verify** ทำที่ Service Layer (เป็นส่วนหนึ่งของกฎธุรกิจ login)
- **เก็บ hash** ทำที่ Repository Layer

### Authorization ควรเช็คที่ชั้นไหน?

**Controller Layer** — เป็นด่านแรกที่กรองก่อนทุกอย่าง

```
admin/borrows.php
├── require bootstrap.php;
├── requireStaff();          ← เช็คสิทธิ์ที่นี่ (ถ้าไม่ใช่ staff → redirect ออก)
├── ... (ไม่ต้องเช็คซ้ำใน Service)
```

ทุกหน้าใน `admin/` เรียก `requireStaff()` หรือ `requireAdmin()` ก่อนทำอะไรทั้งนั้น
API endpoints ใช้ `requireStaffApi()` / `requireLogin()` ซึ่งตอบ JSON แทน redirect

### Transaction / Lock ควรอยู่ตรงไหน?

**Service Layer** — เพราะ Service เป็นคนตัดสินใจว่า "ต้องทำอะไรบ้าง"

```
BorrowService::returnBook()
├── $this->pdo->beginTransaction()    ← เปิด transaction
├── เรียก Repository ดึง/ล็อคข้อมูล
├── ตรวจกฎธุรกิจ
├── เรียก Repository บันทึก
├── $this->pdo->commit()              ← ยืนยัน
└── catch → $this->pdo->rollBack()    ← ถ้าพัง ย้อนกลับ
```

**ทำไมไม่อยู่ใน Repository?**
เพราะ 1 transaction อาจต้องเรียก Repository หลายตัว (เช่น BorrowRepository + BookRepository + PaymentRepository) ถ้าแต่ละ Repository เปิด transaction เอง จะกลายเป็นหลาย transaction ไม่ได้ "ทำครบหรือไม่ทำเลย" เป็นก้อนเดียว

---

## ⚠️ 6. ขอบเขตของสถาปัตยกรรมนี้

### เหมาะกับงานแบบไหน?

| ประเภทงาน | เหมาะ |
|-----------|-------|
| ระบบ CRUD ทั่วไป (เช่น จัดการข้อมูล, สต็อก) | ✅ |
| ระบบที่มีกฎธุรกิจชัดเจน | ✅ |
| โปรเจคของคนเดียว / ทีมเล็ก | ✅ |
| ระบบที่ต้องเรียนรู้แนวคิด backend | ✅ |
| ระบบขนาดเล็ก (< 100 concurrent users) | ✅ |

### ไม่เหมาะกับงานแบบไหน?

| ประเภทงาน | เหตุผล |
|-----------|--------|
| ระบบ microservices | ออกแบบเป็น monolith (ทุกอย่างอยู่ใน project เดียว) |
| ระบบ real-time (เช่น chat, notification push) | ไม่มี WebSocket |
| ระบบที่ต้องรองรับ 500+ คนพร้อมกัน | PHP + session ไม่เหมาะกับ scale ระดับนั้น |
| ระบบที่ต้องมี mobile app | ไม่มี REST API เต็มรูปแบบ |
| ระบบที่ต้องรันหลาย server | Session เก็บเป็นไฟล์ ไม่ share ข้าม server |

### ถ้าเอาไปใช้ production ต้องคิดเพิ่มเรื่องอะไร?

| เรื่อง | สถานะปัจจุบัน | ถ้าจะใช้ production |
|--------|-------------|-------------------|
| **HTTPS** | ไม่มี | ต้องติดตั้ง SSL Certificate |
| **Database backup** | ไม่มี | ต้องตั้ง backup อัตโนมัติ |
| **Error logging** | แสดงบนหน้าจอ (debug mode) | ต้องเขียนลง log file + ปิด display |
| **Session storage** | เก็บเป็นไฟล์ | ถ้ามีหลาย server → ใช้ Redis หรือ DB |
| **Rate limit storage** | เก็บใน MySQL | ถ้า traffic สูง → ใช้ Redis |
| **File upload** | เก็บใน local folder | ถ้ามีหลาย server → ใช้ S3 หรือ shared storage |
| **Monitoring** | ไม่มี | ต้องเพิ่ม health check / uptime monitor |
| **CI/CD** | ไม่มี | ต้องเพิ่ม automated testing + deployment |

---

## 🧭 7. แนวทางการต่อยอด

### ถ้าจะเพิ่ม feature ควรเพิ่มที่ layer ไหน?

**ตัวอย่าง:** อยากเพิ่ม "ระบบแจ้งเตือนหนังสือเกินกำหนด"

```
ขั้นที่ 1 │ Repository Layer
          │ สร้าง method ใหม่ใน BorrowRepository
          │ เพื่อดึงรายการยืมที่เกินกำหนด
          │
ขั้นที่ 2 │ Service Layer
          │ สร้าง NotificationService (ไฟล์ใหม่)
          │ เรียก BorrowRepository → สร้างข้อความแจ้งเตือน
          │
ขั้นที่ 3 │ Controller Layer
          │ สร้างหน้า admin/notifications.php
          │ เรียก NotificationService → แสดงผล
          │
ขั้นที่ 4 │ (Optional) cron/
          │ สร้าง cron job สำหรับส่งแจ้งเตือนอัตโนมัติ
```

**หลักการ:** เพิ่มจากล่างขึ้นบน (Repository → Service → Controller)

### ถ้าจะเปลี่ยน Database ควรแก้ส่วนไหน?

**แก้แค่ Repository Layer**

เพราะ Service ไม่รู้จัก SQL — Service แค่สั่ง "ขอข้อมูลหนังสือ" ไม่สนว่า Repository จะใช้ MySQL, PostgreSQL หรืออ่านจากไฟล์ JSON

```
ก่อน: BookRepository → MySQL
หลัง: BookRepository → PostgreSQL

Service ไม่ต้องแก้เลย → เรียก $bookRepo->findById() เหมือนเดิม
```

อาจต้องแก้:
- `includes/db.php` — เปลี่ยน DSN connection string
- ไฟล์ใน `app/Repositories/` — แก้ SQL ที่ specific กับ MySQL (ถ้ามี)

### ถ้าจะทำ API / Frontend แยก ควรเริ่มตรงไหน?

**Option A: เพิ่ม API endpoint (ง่ายสุด)**

```
สร้างไฟล์ใหม่ใน api/
├── api/books.php      → GET = รายการ, POST = สร้าง
├── api/borrows.php    → GET = รายการ, POST = สร้าง
└── ...

แต่ละ endpoint:
├── require bootstrap.php
├── ตรวจสิทธิ์ (ใช้ session หรือ token)
├── เรียก Service เดิม ← ไม่ต้องเขียนกฎธุรกิจซ้ำ
└── ตอบ JSON
```

ข้อดี: ใช้ Service เดิมได้เลย ไม่ต้องเขียนกฎธุรกิจใหม่

**Option B: แยก Frontend ออกเป็น SPA (React / Vue)**

```
Frontend (React/Vue)  ←→  API (PHP)  ←→  Database

Frontend:
├── เรียก API ผ่าน fetch / axios
├── แสดงผลฝั่ง browser
└── ไม่ต้องรู้จัก PHP

API (PHP):
├── รับ request → เรียก Service → ตอบ JSON
├── เปลี่ยน authentication จาก session เป็น JWT
└── เพิ่ม CORS headers
```

ข้อเสีย: ต้อง rewrite Controller Layer ทั้งหมด + เพิ่ม JWT auth

---

## 📌 8. สรุปสำหรับผู้ซื้อ

### ระบบนี้สอนแนวคิดอะไร?

| แนวคิด | เรียนรู้จากส่วนไหน |
|--------|-------------------|
| **Layered Architecture** | โครงสร้าง Controller → Service → Repository |
| **Separation of Concerns** | แต่ละชั้นมีหน้าที่ชัด ไม่ปนกัน |
| **Single Source of Truth** | MemberService, config.php, functions.php |
| **Repository Pattern** | app/Repositories/ ทุกไฟล์ |
| **Transaction Management** | BorrowService, ReservationService |
| **Concurrency Control** | Row Locking ด้วย SELECT ... FOR UPDATE |
| **Authentication Design** | AuthService + session + rate limit |
| **Security in Depth** | CSRF, XSS, SQL Injection, bcrypt ป้องกันหลายชั้น |
| **State Machine** | ReservationService (pending → fulfilled/cancelled/expired) |

### ได้ฝึกคิดแบบสถาปนิกระบบยังไง?

1. **คิดเป็นชั้น** — ไม่ยัดทุกอย่างไว้ที่เดียว แบ่งหน้าที่ให้ชัด
2. **คิดเรื่องขอบเขต** — แต่ละชั้นทำอะไรได้/ไม่ได้
3. **คิดเรื่อง concurrency** — ถ้า 2 คนทำพร้อมกัน จะเกิดอะไร?
4. **คิดเรื่อง data integrity** — ถ้ากลางทางพัง ข้อมูลจะเสียหายไหม?
5. **คิดเรื่อง security** — ป้องกันภัยคุกคามที่ชั้นไหน?
6. **คิดเรื่อง maintenance** — ถ้าจะแก้ไขทีหลัง แก้ง่ายไหม?

แนวคิดเหล่านี้ **ใช้ได้กับทุกภาษา ทุก framework** ไม่ใช่แค่ PHP
ถ้าเข้าใจจาก template นี้ วันที่ไปเรียน Laravel, Spring Boot, Django หรือ NestJS จะเข้าใจได้เร็วมาก

### เหมาะกับใครมากที่สุด?

- 🎯 **คนที่อยากเข้าใจ "backend ทำงานยังไง"** — เห็นภาพจริง ไม่ใช่แค่ทฤษฎี
- 🎯 **คนที่อยากเรียนรู้ก่อนใช้ framework** — เข้าใจแนวคิดพื้นฐานก่อน แล้วค่อยใช้ framework จะง่ายขึ้นมาก
- 🎯 **คนที่ต้องส่ง project** — มีทั้งโค้ดที่ทำงานได้จริง + เอกสารอธิบายครบ
- 🎯 **คนที่อยากมี template ไปต่อยอด** — โครงสร้างดี แก้ไขง่าย เพิ่มฟีเจอร์ได้

---

*เอกสารนี้เป็นส่วนหนึ่งของชุดโค้ด "ระบบยืมคืนหนังสือ" — อธิบายจากโค้ดจริงทั้งหมด ไม่มีการเดา*
