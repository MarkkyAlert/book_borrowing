# 📚 FLOW.md — ภาพรวมการทำงานของระบบยืมคืนหนังสือ

> เอกสารนี้อธิบาย **"ระบบทำงานยังไง"** แบบเห็นภาพ
> ไม่ต้องอ่านโค้ด ก็เข้าใจได้ว่าแต่ละส่วนเชื่อมกันอย่างไร
> เหมาะสำหรับ: อธิบายให้ลูกค้า, สอนนักเรียน, ตอบคำถามซัพพอร์ต

---

## 🧭 1. ภาพรวมระบบ (System Overview)

### ระบบนี้ทำอะไร?

ระบบนี้เป็น **"ระบบจัดการห้องสมุดบนเว็บ"** ที่ครอบคลุมงานหลัก 6 อย่าง:

| งาน | คำอธิบาย |
|-----|---------|
| **ยืมหนังสือ** | Staff บันทึกการยืม หัก stock อัตโนมัติ |
| **คืนหนังสือ** | Staff บันทึกการคืน คืน stock + คำนวณค่าปรับอัตโนมัติ |
| **จองหนังสือ** | สมาชิกจองเองผ่านเว็บ กัน stock ไว้ให้ |
| **จัดการหนังสือ** | เพิ่ม แก้ไข ลบ import CSV |
| **จัดการผู้ใช้** | เพิ่ม แก้ไข ลบ เปลี่ยน role import CSV พิมพ์บัตร |
| **รายงาน + สถิติ** | สรุปภาพรวม + Export CSV |

### ใครใช้ระบบนี้บ้าง? (Roles)

ระบบแบ่งผู้ใช้เป็น 3 บทบาท:

| บทบาท | คือใคร | สิทธิ์หลัก |
|--------|--------|-----------|
| **Admin** | ผู้ดูแลระบบ | ทำได้ทุกอย่าง + ตั้งค่าระบบ + ดูรายงาน + แต่งตั้งสิทธิ์ (member ↔ staff) |
| **Staff** | เจ้าหน้าที่ | จัดการหนังสือ สมาชิก ยืม-คืน จอง ค่าปรับ |
| **Member** | สมาชิกทั่วไป | ดูหนังสือ จองหนังสือ ดูประวัติตัวเอง |

> 💡 Admin กับ Staff เข้าหน้าจัดการเหมือนกัน แต่บางเมนู (เช่น รายงาน, ตั้งค่า) เฉพาะ Admin เท่านั้น

### ข้อมูลหลักที่ระบบจัดการ

| ตาราง | เก็บอะไร | ตัวอย่าง |
|-------|---------|---------|
| **users** | ข้อมูลผู้ใช้ทุก role | ชื่อ, email, password (เข้ารหัส), role |
| **books** | ข้อมูลหนังสือ | ชื่อ, ผู้แต่ง, ISBN, จำนวนทั้งหมด, จำนวนว่าง |
| **categories** | หมวดหมู่หนังสือ | นิยาย, วิชาการ, การ์ตูน |
| **borrows** | รายการยืม-คืน | ใครยืม, เล่มไหน, วันยืม, วันกำหนดคืน, ค่าปรับ |
| **reservations** | รายการจอง | ใครจอง, เล่มไหน, สถานะ (รอ/อนุมัติ/ยกเลิก/หมดอายุ) |
| **payments** | การชำระค่าปรับ | จ่ายกี่บาท, ใครบันทึก |
| **rate_limits** | ป้องกัน brute force | นับจำนวน login ผิดต่อ email |
| **password_resets** | token รีเซ็ตรหัสผ่าน | token, วันหมดอายุ, ใช้แล้วหรือยัง |
| **settings** | ค่าตั้งค่าระบบ | สีธีม, ชื่อห้องสมุด |

---

## 🗂️ 2. โครงสร้างการไหลของระบบ (High-level Flow)

### ทุกคำสั่งผ่าน 4 ชั้น

เวลาผู้ใช้ทำอะไรก็ตามบนเว็บ ข้อมูลจะไหลผ่าน 4 ชั้นเสมอ:

```
👤 ผู้ใช้กดปุ่มบนเว็บ (browser)
      │
      ▼
📋 Controller (ไฟล์ .php ที่ root + admin/)
      │  "พนักงานต้อนรับ" — รับคำขอ ตรวจสิทธิ์ ส่งต่อ
      ▼
🧠 Service (app/Services/)
      │  "ผู้จัดการ / สมอง" — คิด ตัดสินใจ บังคับกฎ
      ▼
🗄️ Repository (app/Repositories/)
      │  "พนักงานคลัง" — อ่าน/เขียนข้อมูลจาก database
      ▼
💾 Database (MySQL)
      เก็บข้อมูลทั้งหมดอย่างถาวร
```

### หน้าที่ของแต่ละชั้น

| ชั้น | เปรียบเทียบ | หน้าที่ | ตัวอย่างไฟล์ |
|-----|-----------|--------|-------------|
| **Controller** | พนักงานรับออเดอร์ | รับ request, ตรวจสิทธิ์, ตรวจ CSRF, ส่งต่อให้ Service | `login.php`, `admin/borrows.php` |
| **Service** | พ่อครัว | ตัดสินใจตามกฎ, ใช้ transaction, จัดการ lock | `BorrowService.php`, `AuthService.php` |
| **Repository** | พนักงานคลัง | เขียน SQL, ดึง/บันทึกข้อมูล, ไม่มีกฎธุรกิจ | `BookRepository.php`, `UserRepository.php` |
| **Database** | ตู้เก็บเอกสาร | เก็บข้อมูลถาวร, บังคับ constraint | MySQL (InnoDB) |

### ทำไมต้องแยกเป็นชั้นๆ? (Separation of Concerns)

แนวคิดหลักคือ: **แยกหน้าที่ให้ชัด แก้ง่าย พังยาก**

- **Controller ไม่คิด ไม่ตัดสินใจ** — แค่รับคำขอแล้วส่งต่อ
- **Service คิดและตัดสินใจทุกอย่าง** — กฎทั้งหมดอยู่ที่นี่
- **Repository แค่ดึง/บันทึกข้อมูล** — ไม่มีกฎธุรกิจ

ถ้าอยากเปลี่ยนกฎ "ยืมได้กี่เล่ม" → แก้ที่ **Admin → ตั้งค่าระบบ** (ไม่ต้องแตะไฟล์)
ถ้าอยากเปลี่ยนหน้าตาเว็บ → แก้แค่ Controller (ไฟล์ .php ที่ root)
ถ้าอยากเปลี่ยนวิธีเก็บข้อมูล → แก้แค่ Repository
ไม่ต้องไปยุ่งกับส่วนอื่น

### จุดเริ่มต้นของทุกหน้า: bootstrap.php

**ทุกหน้าเว็บ** เริ่มต้นด้วยการเรียก `bootstrap.php` ซึ่งทำ 6 อย่าง:

```
bootstrap.php ทำอะไรบ้าง?

1️⃣ โหลด config.php     → อ่าน .env แล้วสร้างค่าคงที่ (DB_HOST, APP_URL, ...)
   จากนั้น rules.php อ่านกฎการยืมจากตาราง settings ทับอีกชั้น
2️⃣ โหลด db.php         → สร้างการเชื่อมต่อ database (PDO)
3️⃣ โหลด functions.php  → ฟังก์ชันพื้นฐาน (login check, CSRF, escape HTML, ...)
4️⃣ ล้าง idempotency    → ลบ key กันกดซ้ำที่หมดอายุ
5️⃣ ตั้ง autoloader      → โหลด class ใน app/ อัตโนมัติตาม namespace
6️⃣ ตั้ง error reporting → APP_DEBUG=true แสดง error / false ซ่อน
```

> ⚠️ **ห้ามแก้ลำดับ require ใน bootstrap.php** — config ต้องโหลดก่อน db ต้องก่อน functions

---

## 🔐 3. Flow: Authentication / Login

### 3.1 Login (เข้าสู่ระบบ)

**ไฟล์ที่เกี่ยวข้อง:** `login.php` → `AuthService` → `UserRepository`

```
ผู้ใช้เปิดหน้า login → กรอก email + password → กดเข้าสู่ระบบ

Step 1 → ตรวจ CSRF token
         ป้องกันเว็บอื่นหลอกส่ง form login แทนผู้ใช้

Step 2 → ตรวจ input
         email ว่างไหม? password ว่างไหม?
         ถ้าว่าง → หยุดทันที แสดง error

Step 3 → ตรวจ rate limit (จำกัดจำนวนครั้ง)
         login ผิดมากี่ครั้งแล้ว? (นับแยกตาม email)
         ถ้าเกิน 5 ครั้งใน 15 นาที → ล็อค "กรุณารอ 15 นาที"
         ทำไม: ป้องกันคนร้ายเดารหัสผ่านด้วยโปรแกรมอัตโนมัติ

Step 4 → AuthService::login()
         หา user จาก email → เทียบ password ด้วย password_verify()
         ถ้าผิด → คืน null (ไม่บอกว่า email ผิดหรือ password ผิด)
         ทำไม: ป้องกันคนร้ายมาเช็คว่า email นี้มีในระบบไหม

Step 5 → สำเร็จ!
         ├── ล้างจำนวนครั้งที่ผิด
         ├── session_regenerate_id(true) ← สร้างบัตรผ่านใหม่
         │   ทำไม: ป้องกัน session fixation attack
         ├── เก็บ user_id, name, email, role ใน session
         └── redirect ตาม role:
             admin/staff → /admin/
             member      → /index.php
```

**จุดสำคัญด้านความปลอดภัย:**

| มาตรการ | ทำอะไร | อยู่ตรงไหน |
|---------|--------|-----------|
| **CSRF token** | ป้องกันเว็บอื่นหลอกส่ง form | Controller (login.php) |
| **Rate limiting** | ล็อคหลัง login ผิดเกิน 5 ครั้ง | Controller + functions.php |
| **Generic error** | ไม่แยก "email ผิด" / "password ผิด" | AuthService |
| **password_verify()** | เทียบ hash อย่างปลอดภัย | AuthService |
| **session_regenerate_id()** | สร้าง session ID ใหม่หลัง login | Controller (login.php) |
| **Secure cookie** | HttpOnly + SameSite=Lax + Secure(HTTPS) | functions.php → startSession() |

### 3.2 Register (สมัครสมาชิก)

**ไฟล์ที่เกี่ยวข้อง:** `register.php` → `AuthService` → `MemberService` → `UserRepository`

```
Step 1 → ตรวจ CSRF token

Step 2 → AuthService::register()
         ├── delegate ไป MemberService::createMember()
         │   (ใช้ logic เดียวกันกับ admin สร้างสมาชิก = Single Source of Truth)
         ├── validate: ชื่อ, email (ไม่ซ้ำ), เบอร์โทร, password (≥ 6 ตัว)
         ├── hash password ด้วย bcrypt ก่อนเก็บ
         └── INSERT เป็น role = member

Step 3 → redirect ไป login.php (ไม่ auto-login)
         แสดง "สมัครสำเร็จ กรุณาเข้าสู่ระบบ"
```

**จุดสำคัญ:** `register.php` และ `admin/member_form.php` ใช้ `MemberService::createMember()` เดียวกัน — แก้กฎตรวจสอบที่เดียว มีผลทั้ง 2 จุด

### 3.3 ลืมรหัสผ่าน + รีเซ็ต

**ไฟล์ที่เกี่ยวข้อง:** `forgot_password.php` → `AuthService` → `PasswordResetRepository`
**ไฟล์ที่เกี่ยวข้อง:** `reset_password.php` → `AuthService` → `UserRepository` + `PasswordResetRepository`

```
ขอ reset:
Step 1 → กรอก email → AuthService::requestPasswordReset()
Step 2 → หา user จาก email
         ถ้าไม่พบ → ยังคืน "สำเร็จ" (ไม่บอกว่า email ไม่มี — ป้องกัน enumeration)
Step 3 → ตรวจ rate limit (max 3 ครั้ง/ชั่วโมง/email)
Step 4 → สร้าง token (64 ตัวอักษร, random, หมดอายุ 1 ชม.)
Step 5 → บันทึก token ลง database
Step 6 → แสดง link reset บนหน้าจอ (ถ้า APP_DEBUG=true)

รีเซ็ต:
Step 1 → เปิด link → AuthService::validateResetToken()
         ตรวจ: token ตรงไหม + ยังไม่หมดอายุ + ยังไม่ใช้
Step 2 → กรอก password ใหม่ → AuthService::resetPassword()
Step 3 → เปิด transaction:
         ├── เปลี่ยน password (hash ใหม่)
         └── mark token ว่าใช้แล้ว (ใช้ซ้ำไม่ได้)
         ทั้ง 2 อย่างต้องสำเร็จพร้อมกัน ถ้าอันใดอันหนึ่งพัง → rollback ทั้งหมด
```

---

## 👤 4. Flow: การจัดการผู้ใช้ (User Management)

### 4.1 สร้างสมาชิก (Staff/Admin สร้างให้)

**ไฟล์ที่เกี่ยวข้อง:** `admin/member_form.php` → `MemberService` → `UserRepository`

```
Step 1 → Staff กรอกข้อมูล: ชื่อ, email, เบอร์, password (เว้นว่างได้)

Step 2 → MemberService::createMember()
         ├── validate ผ่าน validateMemberData() (shared helper)
         ├── ตรวจ email ซ้ำ
         ├── ถ้าไม่กรอก password → สร้าง random 8 ตัว
         ├── hash password ด้วย bcrypt
         └── INSERT เป็น role = member

Step 3 → แสดง password ให้ admin เห็นครั้งเดียว
         (หลังจากนี้ไม่มีทางดึง password จริงกลับมาได้)
```

### 4.2 แก้ไขผู้ใช้ + เปลี่ยน role

**ไฟล์ที่เกี่ยวข้อง:** `admin/member_form.php` → `MemberService` → `UserRepository`

```
Step 1 → Staff แก้ไข: ชื่อ, email, เบอร์ (password แยกต่างหาก)
         Admin เพิ่มเติม: เปลี่ยน role (member ↔ staff) ผ่าน dropdown

Step 2 → MemberService::updateMember()
         ├── ตรวจว่าผู้ใช้มีอยู่ (member/staff)
         ├── ถ้าเปลี่ยน email → ตรวจซ้ำ (ยกเว้นตัวเอง)
         ├── role whitelist: member/staff เท่านั้น (ป้องกัน escalation เป็น admin)
         └── UPDATE ข้อมูล (+ role ถ้า admin ส่งมา)
```

**จุดสำคัญ:** หลังเปลี่ยน role ผู้ใช้ต้อง re-login ถึงจะเห็นสิทธิ์ใหม่ (เพราะ session เก็บ role ตอน login)

### 4.3 ลบผู้ใช้

**ไฟล์ที่เกี่ยวข้อง:** `admin/members.php` → `MemberService` → `UserRepository`

```
Step 1 → MemberService::deleteMember()
         ├── เปิด transaction
         ├── Guard #1: มีประวัติการยืมไหม? → ถ้ามี ห้ามลบ (สถิติจะหาย)
         ├── Guard #2: มี pending reservation ไหม? → ถ้ามี ห้ามลบ (stock จะไม่ถูกคืน)
         └── ผ่านทั้ง 2 guard → DELETE (เฉพาะ member/staff, ไม่รวม admin)
```

### 4.4 การแยก Role และตรวจสิทธิ์

ระบบตรวจสิทธิ์ผ่าน helper functions ใน `functions.php`:

| ฟังก์ชัน | ใช้ตรงไหน | ถ้าไม่ผ่าน |
|---------|----------|-----------|
| `requireLogin()` | ทุกหน้าที่ต้อง login | redirect ไป login.php |
| `requireStaff()` | หน้า admin ทั่วไป | redirect ไป index.php |
| `requireAdmin()` | หน้ารายงาน, ตั้งค่า | redirect ไป admin/ |
| `requireStaffApi()` | API ที่ต้องเป็น staff+ | JSON 403 Forbidden |
| `requireAdminApi()` | API ที่ต้องเป็น admin | JSON 403 Forbidden |

**จุดสำคัญ:** ทุกหน้า admin เรียก `requireStaff()` หรือ `requireAdmin()` ที่บรรทัดแรกเสมอ — ถ้าลบบรรทัดนี้ ใครก็เข้าหน้า admin ได้

### 4.5 Profile + เปลี่ยนรหัสผ่าน

**ไฟล์ที่เกี่ยวข้อง:** `profile.php` → `AuthService` → `UserRepository`

```
แก้ profile:
├── AuthService::updateProfile()
├── แก้ได้: ชื่อ, เบอร์โทร
└── แก้ไม่ได้: email (ป้องกัน account takeover)
    ระบบใช้ email จาก DB เสมอ ไม่ใช่จาก form

เปลี่ยน password:
├── AuthService::changePassword()
├── ต้องยืนยันรหัสเดิมก่อน (ป้องกัน session hijack)
├── ห้ามใช้รหัสเดิมซ้ำ
└── hash ใหม่แล้ว update
```

---

## 🔄 5. Flow หลักของระบบ (Core Business Flows)

### 5.1 ยืมหนังสือ (Create Borrow)

**ไฟล์ที่เกี่ยวข้อง:** `admin/borrow_form.php` → `BorrowService` → `BorrowRepository` + `BookRepository` + `ReservationRepository`

```
Staff เปิดหน้ายืม → เลือกผู้ยืม + หนังสือ (หลายเล่มได้) → กดบันทึก

Step 1 → ตรวจ input
         ├── เลือกผู้ยืมแล้วหรือยัง?
         ├── เลือกหนังสืออย่างน้อย 1 เล่มหรือยัง?
         └── จำนวนวันยืม 1-30

Step 2 → ตรวจว่าผู้ยืมเป็น member/staff (ไม่ใช่ admin)
         (dropdown แสดงทั้ง member + staff)

Step 3 → เปิด transaction (เริ่มทำงานแบบ "ทั้งหมดหรือไม่เลย")

Step 4 → ล็อคแถว user (FOR UPDATE)
         ป้องกัน: admin 2 คนกดยืมให้ member เดียวกันพร้อมกัน

Step 5 → ตรวจโควต้า
         ├── นับจำนวนที่ยืมอยู่ (active borrows)
         ├── นับจำนวนที่จองรอรับ (pending reservations)
         ├── ยืม + จอง รวมกันต้องไม่เกิน MAX_BORROW_BOOKS (ค่าเริ่มต้น: 3)
         └── ถ้าเกิน → หยุด แสดง error

Step 6 → วนทีละเล่ม:
         ├── ล็อคแถวหนังสือ (FOR UPDATE)
         ├── ตรวจ stock (available > 0?)
         ├── ตรวจยืมซ้ำ (ยืมเล่มนี้อยู่แล้วไหม?)
         ├── หัก stock (available -1) ด้วย WHERE available > 0
         └── สร้างรายการยืม (borrow record)

Step 7 → COMMIT transaction
         ├── สำเร็จ → stock ถูกหัก + borrow ถูกสร้าง
         └── ล้มเหลว → rollback ทุกอย่าง (เหมือนไม่เกิดขึ้น)
```

**ทำไมต้องนับ pending reservations ด้วย?**
ถ้านับแค่ที่ยืม สมาชิกอาจยืม 3 เล่ม + จองอีก 3 เล่ม = ถือครอง 6 เล่ม ซึ่งเกินกฎ

### 5.2 คืนหนังสือ (Return Book)

**ไฟล์ที่เกี่ยวข้อง:** `admin/borrows.php` → `BorrowService` → `BorrowRepository` + `BookRepository` + `PaymentRepository`

```
Staff เปิดรายการยืม → หารายการ → กดคืน

Step 1 → เปิด transaction

Step 2 → ล็อค borrow (FOR UPDATE + status='borrowing')
         ถ้าสถานะเป็น 'returned' แล้ว → คืน null → หยุด
         ป้องกัน: 2 คนกดคืนพร้อมกัน

Step 3 → คำนวณค่าปรับ
         ├── วันเกิน = วันคืนจริง − วันกำหนดคืน
         ├── ค่าปรับ = วันเกิน × FINE_PER_DAY (ค่าเริ่มต้น: 10 บาท/วัน)
         └── คืนตรงเวลา → ค่าปรับ = 0

Step 4 → 3 การเขียนใน 1 transaction:
         ├── เปลี่ยนสถานะ borrowing → returned + บันทึกค่าปรับ
         ├── คืน stock (available +1)
         └── ถ้า Staff เลือก "จ่ายทันที" + มีค่าปรับ → สร้าง payment record

Step 5 → COMMIT
```

### 5.3 รับชำระค่าปรับทีหลัง (Pay Fine)

**ไฟล์ที่เกี่ยวข้อง:** `admin/payments.php` → `BorrowService` → `BorrowRepository` + `PaymentRepository`

```
Step 1 → เปิด transaction

Step 2 → ล็อค borrow (FOR UPDATE)
         ป้องกัน: 2 คนกดชำระพร้อมกัน

Step 3 → ตรวจ: มีค่าปรับจริงไหม? ชำระไปแล้วหรือยัง?

Step 4 → สร้าง payment record
         (UNIQUE constraint บน borrow_id = จ่ายได้ครั้งเดียวต่อรายการยืม)

Step 5 → COMMIT
```

### 5.4 จองหนังสือ (Create Reservation)

**ไฟล์ที่เกี่ยวข้อง:** `api/reserve_book.php` → `ReservationService` → `ReservationRepository` + `BookRepository` + `BorrowRepository`

```
สมาชิกเปิดหน้าหนังสือ → กดปุ่ม "จอง"

Step 0 → Lazy expire: คืน stock จาก reservation ที่หมดอายุก่อน

Step 1 → เปิด transaction

Step 2 → ล็อคหนังสือ (FOR UPDATE)
         ป้องกัน: 2 คนจองเล่มสุดท้ายพร้อมกัน

Step 3 → ตรวจ 5 เงื่อนไข:
         ├── stock ว่างไหม? (available > 0)
         ├── จองเล่มนี้ซ้ำไหม?
         ├── ยืมเล่มนี้อยู่แล้วไหม?
         ├── ล็อค user row (ป้องกัน concurrent borrow+reserve)
         └── ยืม+จอง เกิน MAX_BORROW_BOOKS ไหม?

Step 4 → สร้างรายการจอง (หมดอายุใน 2 วัน)

Step 5 → หัก stock ทันที (available -1)
         ⚠️ stock ถูกหักตอนจอง ไม่ใช่ตอนอนุมัติ!

Step 6 → COMMIT
```

**หลังจากจองแล้ว มี 3 ทางเป็นไปได้:**

```
              ┌─────────────────────────────────────────┐
              │         reservation (pending)            │
              └──────┬──────────┬──────────┬─────────────┘
                     │          │          │
          Staff อนุมัติ    ยกเลิก     หมดอายุ (2 วัน)
                     │          │          │
                     ▼          ▼          ▼
              ┌──────────┐ ┌────────┐ ┌─────────┐
              │fulfilled │ │cancelled│ │ expired │
              │สร้าง borrow│ │คืน stock│ │คืน stock│
              │ไม่หัก stock│ │        │ │(อัตโนมัติ)│
              └──────────┘ └────────┘ └─────────┘
```

### 5.5 อนุมัติการจอง (Fulfill Reservation)

**ไฟล์ที่เกี่ยวข้อง:** `admin/reservations.php` → `ReservationService` → `ReservationRepository` + `BorrowRepository`

```
Step 1 → เปิด transaction

Step 2 → ล็อค reservation (FOR UPDATE + status='pending')
         ป้องกัน: 2 admin กดอนุมัติพร้อมกัน

Step 3 → ตรวจ: ยืมเล่มนี้ซ้ำไหม?

Step 4 → ตรวจโควต้า
         ├── นับ active borrows
         ├── นับ pending reservations อื่น (ลบ 1 = ตัวที่กำลัง fulfill)
         └── ถ้าเกิน → หยุด

Step 5 → สร้าง borrow record (ไม่หัก stock เพิ่ม — หักไปแล้วตอนจอง)

Step 6 → เปลี่ยนสถานะ pending → fulfilled + เชื่อม borrow_id

Step 7 → COMMIT
```

### 5.6 ยกเลิกการจอง (Cancel Reservation)

**ไฟล์ที่เกี่ยวข้อง:** `admin/reservations.php` หรือ `my_reservations.php` → `ReservationService`

```
Step 1 → เปิด transaction

Step 2 → ล็อค reservation (FOR UPDATE + status='pending')
         ถ้าเรียกจาก member → เพิ่มเงื่อนไข user_id ตรงไหม (ป้องกัน IDOR)
         ถ้าเรียกจาก admin → ยกเลิกได้ทุกคน

Step 3 → เปลี่ยนสถานะ pending → cancelled

Step 4 → คืน stock (available +1)

Step 5 → COMMIT
```

### 5.7 จัดการหนังสือ (Book Management)

**ไฟล์ที่เกี่ยวข้อง:** `admin/book_form.php`, `admin/books.php` → `BookService` → `BookRepository`

**สร้างหนังสือ:**
```
กรอกข้อมูล → BookService::createBook() → INSERT (available = quantity)
```

**แก้ไขหนังสือ:**
```
Step 1 → เปิด transaction + ล็อคหนังสือ (FOR UPDATE)
Step 2 → คำนวณ available ใหม่:
         available_ใหม่ = available_เดิม + (quantity_ใหม่ − quantity_เดิม)
Step 3 → ตรวจ: ลด quantity ต่ำกว่าจำนวนที่ออกอยู่ (ยืม+จอง) ไม่ได้
Step 4 → UPDATE + COMMIT
```

**ลบหนังสือ:**
```
Step 1 → เปิด transaction + ล็อคหนังสือ
Step 2 → ตรวจ 3 guard:
         ├── มีคนยืมอยู่ไหม? → ห้ามลบ
         ├── มีประวัติการยืมไหม? → ห้ามลบ (สถิติจะหาย)
         └── มี pending reservation ไหม? → ห้ามลบ (stock จะไม่ถูกคืน)
Step 3 → DELETE + COMMIT
Step 4 → ลบรูปปก (หลัง COMMIT — ป้องกันลบรูปแล้ว DB rollback)
```

### 5.8 Lazy Expire: การคืน stock อัตโนมัติจากจองหมดอายุ

ระบบไม่ได้ตั้ง cron job ไว้คืน stock — แต่ใช้หลัก **"ตรวจตอนเปิดหน้า"**

```
ทุกครั้งที่เปิดหน้าที่แสดงรายชื่อหนังสือ:
├── HomeService::getBooks()
├── BookService::getBooks()
├── BookService::getBookById()
└── BookService::getAvailableBooks()

   ก่อนดึงข้อมูล → เรียก markExpiredReservations()
   ├── หา reservation ที่ pending + เลยกำหนด 2 วัน
   ├── เปลี่ยนสถานะ → expired
   └── คืน stock (available +1) ทีละรายการ
```

> 💡 วิธีนี้เรียกว่า **Lazy Expire** — ไม่ต้องตั้ง cron แต่ stock จะถูกคืนก่อนแสดงผลเสมอ

---

## ⚠️ 6. จุดสำคัญที่ต้องเข้าใจเป็นพิเศษ

### 6.1 Transaction — "ทั้งหมดหรือไม่เลย"

Transaction คือกลไกที่ทำให้หลายขั้นตอนเป็น **"ก้อนเดียว"** — ถ้าขั้นตอนใดพัง ทุกอย่างจะถูกยกเลิกกลับ

**ตัวอย่าง:** ยืมหนังสือ 3 เล่ม
```
BEGIN TRANSACTION
├── หัก stock เล่ม A ✅
├── หัก stock เล่ม B ✅
├── หัก stock เล่ม C ❌ (available = 0!)
└── ROLLBACK → stock เล่ม A, B ถูกคืนกลับ → เหมือนไม่เกิดขึ้น
```

**จุดที่ใช้ transaction:**

| Flow | ทำไมต้องใช้ |
|------|-----------|
| **ยืมหนังสือ** | หัก stock หลายเล่ม + สร้าง borrow → ถ้าเล่มใดพัง ต้อง rollback ทั้งหมด |
| **คืนหนังสือ** | คืน stock + เปลี่ยนสถานะ + สร้าง payment → ต้องสำเร็จพร้อมกัน |
| **จองหนังสือ** | หัก stock + สร้าง reservation → ถ้าอันใดพัง stock ต้องถูก |
| **อนุมัติจอง** | สร้าง borrow + เปลี่ยนสถานะ reservation → ต้องสำเร็จพร้อมกัน |
| **ยกเลิกจอง** | คืน stock + เปลี่ยนสถานะ → ต้องสำเร็จพร้อมกัน |
| **ลบหนังสือ** | ตรวจ guard + DELETE → ป้องกัน race condition |
| **ลบสมาชิก** | ตรวจ guard + DELETE → ป้องกัน race condition |
| **รีเซ็ต password** | เปลี่ยน password + mark token ว่าใช้แล้ว → ต้องสำเร็จพร้อมกัน |

> ⚠️ **ห้ามลบ `beginTransaction()` / `commit()` / `rollBack()` ออก!** ถ้าลบ → stock จะผิดเมื่อเกิดข้อผิดพลาด

### 6.2 Row Locking — "ล็อคแถวก่อนแก้"

Row locking (`SELECT ... FOR UPDATE`) คือการ **"จองสิทธิ์แก้ข้อมูล"** — คนอื่นต้องรอจนกว่า transaction จะจบ

**เปรียบเทียบ:** เหมือนเข้าห้องน้ำแล้วล็อคประตู คนอื่นต้องรอจนเราออกมา

**จุดที่ใช้ row locking:**

| จุดที่ lock | ล็อคอะไร | ป้องกันอะไร |
|-----------|---------|-----------|
| **ยืม → ล็อค user** | แถว user ของผู้ยืม | 2 admin กดยืมให้คนเดียวกันพร้อมกัน |
| **ยืม → ล็อค book** | แถว book ทีละเล่ม | 2 คนยืมเล่มสุดท้ายพร้อมกัน |
| **คืน → ล็อค borrow** | แถว borrow | 2 คนกดคืนพร้อมกัน (กันคืนซ้ำ) |
| **จอง → ล็อค book** | แถว book | 2 คนจองเล่มสุดท้ายพร้อมกัน |
| **จอง → ล็อค user** | แถว user ของผู้จอง | จอง + ยืมพร้อมกัน เกินโควต้า |
| **อนุมัติจอง → ล็อค reservation** | แถว reservation | 2 admin กดอนุมัติพร้อมกัน |
| **ยกเลิกจอง → ล็อค reservation** | แถว reservation | กดยกเลิก 2 ครั้งพร้อมกัน |
| **ชำระค่าปรับ → ล็อค borrow** | แถว borrow | 2 คนกดชำระพร้อมกัน |
| **แก้หนังสือ → ล็อค book** | แถว book | admin แก้ quantity ระหว่างมีคนยืม |
| **ลบหนังสือ → ล็อค book** | แถว book | 2 admin กดลบพร้อมกัน |

> ⚠️ **ลำดับ lock สำคัญ!** ระบบ lock user ก่อน book เสมอ — ถ้าสลับลำดับจะเกิด **deadlock** (2 ฝ่ายรอกันไม่จบ)

### 6.3 Idempotency Key — "กันกดซ้ำ"

เวลาผู้ใช้กดปุ่ม "ยืม" แล้วอินเทอร์เน็ตช้า อาจกดซ้ำ 2-3 ครั้ง

```
กดยืม (ครั้งที่ 1) → สร้าง idempotency key → ยืมสำเร็จ → ลบ key
กดยืม (ครั้งที่ 2) → เจอ key เดิม → "กำลังดำเนินการ กรุณาอย่ากดซ้ำ"
```

> 💡 Key หมดอายุใน 60 วินาที (ล้างอัตโนมัติทุก request ผ่าน `bootstrap.php`)

### 6.4 Stock Management — "ตัวเลข available ต้องถูกเสมอ"

`available` = จำนวนเล่มที่ว่างอยู่จริงๆ (ไม่รวมที่ถูกยืมหรือจอง)

```
available ถูกหักเมื่อ:
├── ยืมหนังสือ (available -1 ต่อเล่ม)
└── จองหนังสือ (available -1 ทันทีที่จอง!)

available ถูกคืนเมื่อ:
├── คืนหนังสือ (available +1)
├── ยกเลิกการจอง (available +1)
└── จองหมดอายุ (available +1 — lazy expire)

available ไม่เปลี่ยนเมื่อ:
└── อนุมัติจอง → สร้าง borrow (stock หักไปแล้วตอนจอง)
```

**จุดสำคัญ:** SQL ที่หัก stock ใช้ `WHERE available > 0` — ถ้า available = 0 จะหักไม่ได้ (ป้องกันติดลบ)

### 6.5 Quota Check — "ยืม + จอง ไม่เกิน 3"

```
โควต้า = active borrows + pending reservations

ตรวจตอน:
├── ยืมหนังสือ → ยืม+จอง ≥ MAX? → ห้ามยืม
├── จองหนังสือ → ยืม+จอง ≥ MAX? → ห้ามจอง
└── อนุมัติจอง → ยืม + (จองอื่น−1) ≥ MAX? → ห้ามอนุมัติ
    (ลบ 1 = ตัวที่กำลัง fulfill เพราะมันจะเปลี่ยนจาก reservation เป็น borrow)
```

### 6.6 จุดที่ห้ามแก้ถ้าไม่เข้าใจ

| จุด | เหตุผล |
|-----|--------|
| **`beginTransaction()` / `commit()` / `rollBack()`** | ลบ → stock ผิดเมื่อเกิด error |
| **`SELECT ... FOR UPDATE`** | ลบ → race condition → stock ติดลบ/เกิน |
| **`WHERE available > 0` ใน decrementAvailable()** | ลบ → stock ติดลบได้ |
| **`WHERE available < quantity` ใน incrementAvailable()** | ลบ → stock เกิน quantity ได้ |
| **`session_regenerate_id(true)`** | ลบ → session fixation attack |
| **`e()` ครอบทุก output** | ลบ → XSS attack |
| **`validateCSRFToken()`** | ลบ → CSRF attack |
| **ลำดับ lock (user ก่อน book)** | สลับ → deadlock |
| **password_hash() / password_verify()** | เปลี่ยน → password ไม่ปลอดภัย |
| **ลำดับ require ใน bootstrap.php** | สลับ → ระบบพัง (constant ยังไม่ถูก define) |

---

## 🛡️ 7. ขอบเขตการใช้งานที่ควรรู้

### ออกแบบมาเพื่ออะไร?

| เหมาะกับ | คำอธิบาย |
|---------|---------|
| **เรียนรู้ Backend** | โครงสร้างชัดเจน แยกชั้น มี comment ละเอียด |
| **เรียนรู้ Security** | มีตัวอย่าง CSRF, XSS, Rate Limit, Session, Locking จริงๆ |
| **ส่งงานมหาวิทยาลัย** | ครบ CRUD + ระบบยืมคืน + รายงาน |
| **ใช้เป็น Template** | แก้ชื่อ ปรับ config แล้วใช้ได้เลย |
| **สอนนักเรียน** | มีเอกสารอธิบายครบ อ่านแล้วเข้าใจ |

### ไม่เหมาะกับอะไร?

| ไม่เหมาะ | เหตุผล |
|---------|--------|
| **ห้องสมุดจริงขนาดใหญ่** | ยังไม่มี caching, queue, horizontal scaling |
| **ระบบที่ต้อง uptime 99.99%** | เป็น single server, ไม่มี failover |
| **ระบบที่ต้อง multi-tenant** | ออกแบบ single library เท่านั้น |
| **E-commerce / ระบบจ่ายเงินจริง** | ไม่มี payment gateway |

### ถ้าจะเอาไปต่อยอด production ต้องระวังเรื่องไหน?

| เรื่อง | ทำไม | ทำอะไร |
|--------|------|--------|
| **Session Storage** | default ใช้ file → ไม่รองรับ multi-server | เปลี่ยนเป็น Redis/Database |
| **Rate Limit** | เก็บใน DB → ช้าถ้า traffic สูง | ย้ายไป Redis |
| **File Upload** | เก็บใน local disk → ไม่รองรับ CDN | ย้ายไป S3/Cloud Storage |
| **Email** | ยังไม่ส่ง email จริง (แสดง link บนหน้าจอเท่านั้น) | ต่อ SMTP / SendGrid |
| **HTTPS** | config พร้อมแต่ต้องเปิดเอง | ตั้ง SSL certificate |
| **Backup** | ไม่มี auto backup | ตั้ง mysqldump cron |
| **Logging** | ใช้ error_log → เขียนลง file | ต่อ centralized logging |
| **Reservation Expiry** | ใช้ Lazy Expire → ขึ้นกับว่ามีคนเปิดหน้า | เพิ่ม cron job |
| **Password Reset** | แสดง link บนหน้าจอ (dev mode) | ต่อ email service |

---

## 🧠 8. วิธีอ่านโค้ดจาก FLOW นี้

### เริ่มจากไหน?

ถ้าอยากเข้าใจระบบนี้ แนะนำอ่านตามลำดับนี้:

```
ลำดับแนะนำสำหรับมือใหม่:

1️⃣ bootstrap.php
   → เข้าใจว่า "ทุกหน้าเริ่มต้นยังไง"
   → config, db, functions โหลดตรงไหน

2️⃣ includes/config.php
   → ดูค่าคงที่ทั้งหมด (MAX_BORROW_BOOKS, FINE_PER_DAY, ...)
   → เข้าใจว่า .env ทำงานยังไง

3️⃣ includes/functions.php
   → ดูฟังก์ชันพื้นฐาน (login check, CSRF, escape, rate limit)
   → เข้าใจ "เครื่องมือ" ที่ระบบใช้ทั่วไป

4️⃣ login.php
   → ดูตัวอย่าง Controller ที่ง่ายที่สุด
   → เห็นภาพ: รับ POST → ตรวจ CSRF → เรียก Service → redirect

5️⃣ app/Services/AuthService.php
   → ดูตัวอย่าง Service แรก
   → เห็น: login(), register(), password hashing

6️⃣ app/Services/BorrowService.php
   → ดู flow หลักของระบบ: ยืม, คืน, ค่าปรับ
   → เห็น: transaction, lock, quota check

7️⃣ app/Services/ReservationService.php
   → ดู flow จอง: สร้าง, อนุมัติ, ยกเลิก, หมดอายุ
   → เห็น: stock management, lazy expire

8️⃣ app/Services/BookService.php
   → ดู CRUD + guard ก่อนลบ
   → เห็น: available calculation, file deletion after commit

9️⃣ app/Repositories/ (เลือกอ่าน)
    → ดูว่า SQL เขียนยังไง
    → เห็น: prepared statements, FOR UPDATE, WHERE guard
```

### มือใหม่ควรโฟกัสส่วนไหนก่อน?

| ระดับ | โฟกัส | ไฟล์ |
|-------|-------|------|
| **เริ่มต้น** | เข้าใจ flow: request → controller → service → repo → DB | `login.php` → `AuthService` |
| **กลาง** | เข้าใจ transaction + lock + stock | `BorrowService` → `ReservationService` |
| **ขั้นสูง** | เข้าใจ security: CSRF, XSS, rate limit, session | `functions.php` + controller ทุกตัว |

### แผนที่ Service ↔ หน้าที่

| Service | หน้าที่หลัก | ความซับซ้อน |
|---------|-----------|------------|
| **AuthService** | Login, Register, Profile, Password Reset | ⭐⭐ |
| **BorrowService** | ยืม, คืน, ค่าปรับ | ⭐⭐⭐ (มี transaction + lock) |
| **ReservationService** | จอง, อนุมัติ, ยกเลิก, หมดอายุ | ⭐⭐⭐ (มี transaction + lock + lazy expire) |
| **BookService** | CRUD หนังสือ + guard ก่อนลบ | ⭐⭐ |
| **MemberService** | CRUD สมาชิก + import CSV | ⭐⭐ |
| **HomeService** | หน้าแรก (public) | ⭐ (read-only + lazy expire) |
| **DashboardService** | สถิติ admin dashboard | ⭐ (read-only aggregator) |
| **ReportService** | รายงานเชิงลึก | ⭐ (read-only aggregator) |

### แผนที่ Repository ↔ ตาราง

| Repository | ตาราง | จุดเด่น |
|-----------|-------|--------|
| **UserRepository** | users | findByEmail, emailExists, findMembers |
| **BookRepository** | books | decrementAvailable (WHERE guard), findByIdForUpdate |
| **BorrowRepository** | borrows | countActiveBorrowsForUpdate (lock), isAlreadyBorrowing |
| **ReservationRepository** | reservations | countPendingByUser, markExpiredReservations |
| **CategoryRepository** | categories | CRUD หมวดหมู่ |
| **PaymentRepository** | payments | UNIQUE constraint บน borrow_id |
| **PasswordResetRepository** | password_resets | token management |
| **ReportRepository** | หลายตาราง (JOIN) | สถิติและรายงาน |
| **SettingsRepository** | settings | key-value store |

---

## 📌 9. สรุปสั้นสำหรับผู้ซื้อ

### ระบบนี้เหมาะกับใคร?

- **นักศึกษา** ที่ต้องส่งโปรเจคจบหรือรายงาน — ได้ระบบสมบูรณ์พร้อมใช้
- **ผู้สอน** ที่ต้องการตัวอย่าง PHP ที่เขียนดี มีโครงสร้างชัดเจน
- **ผู้เริ่มต้น** ที่อยากเรียนรู้ backend: layered architecture, security, transaction
- **Freelancer** ที่อยากมี template ไว้ต่อยอดงานให้ลูกค้า
- **ห้องสมุดขนาดเล็ก** ที่ต้องการระบบยืมคืนบนเว็บ

### ใช้เรียนรู้อะไรได้บ้าง?

| หัวข้อ | ตัวอย่างในระบบ |
|--------|---------------|
| **Layered Architecture** | Controller → Service → Repository → Database |
| **Transaction & Locking** | ยืม, คืน, จอง ทุก flow ที่แก้ข้อมูลหลายตาราง |
| **Security** | CSRF, XSS prevention, Rate Limiting, Session Management, Password Hashing |
| **CRUD + Validation** | หนังสือ, สมาชิก, หมวดหมู่ — มี shared validation helper |
| **Stock Management** | available tracking, guard ป้องกันติดลบ/เกิน |
| **Race Condition Prevention** | row locking, idempotency key, deadlock prevention |
| **Quota System** | นับ borrows + reservations รวมกัน |
| **Lazy Processing** | จองหมดอายุ → คืน stock ตอนเปิดหน้า |
| **Single Source of Truth** | ค่าคงที่ใน config, validation ใน helper, fine calculation ใน Service |
| **Role-based Access Control** | admin / staff / member แยกสิทธิ์ชัดเจน |

### ควรรู้อะไรก่อนนำไปใช้หรือขายต่อ?

1. **PHP + MySQL พื้นฐาน** — ต้องรู้ว่า `require`, `$_SESSION`, `$_POST` คืออะไร
2. **XAMPP / WAMP** — ต้องติดตั้ง local server ได้
3. **อ่าน .env.example** — ค่าที่ต้องปรับก่อนใช้งาน (database, URL)
4. **รัน install.php ครั้งเดียว** — สร้างตาราง + ข้อมูลเริ่มต้น
5. **อ่าน README.md ก่อน** — ขั้นตอนติดตั้ง + คำอธิบายระบบ
6. **อ่าน FLOW.md (ไฟล์นี้)** — ภาพรวมการทำงาน
7. **ระวังส่วนที่ห้ามแก้** — ดูหัวข้อ 6.6 ก่อนแก้โค้ด

---

> 📖 **เอกสารอื่นที่เกี่ยวข้อง:**
> - `README.md` — ภาพรวมระบบ + วิธีติดตั้ง + คำอธิบายสำหรับมือใหม่
> - `FAQ.md` — คำถามที่พบบ่อย
> - `STUDY_GUIDE.md` — คู่มือเรียนรู้ระบบเชิงลึก
