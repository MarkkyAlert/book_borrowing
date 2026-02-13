# 🗺️ WHERE TO EDIT — แผนที่การแก้ไขระบบยืมคืนหนังสือ

> ไฟล์นี้บอกว่า **"ถ้าอยากแก้อะไร ต้องไปแก้ไฟล์ไหน"**
> ไม่ต้องไล่โค้ดเอง อ่านจากตรงนี้แล้วไปแก้ได้เลย

---

## 🔰 1. วิธีใช้ไฟล์นี้ (อ่านก่อน)

### ไฟล์นี้คือ "แผนที่"

เวลาอยากแก้อะไรในระบบ ไม่ต้องเปิดไล่ทุกไฟล์ ให้มาเปิดไฟล์นี้ก่อน แล้วค้นหาสิ่งที่อยากแก้ จะบอกว่าต้องไปแก้ไฟล์ไหน

### แนะนำให้แก้จากง่ายไปยาก

```
ระดับ 1 (ง่ายมาก):  แก้ค่าใน .env          → ไม่ต้องแตะโค้ดเลย
ระดับ 2 (ง่าย):      แก้ HTML / CSS          → เปลี่ยนหน้าตา
ระดับ 3 (ปานกลาง):  แก้ Controller / Form   → เปลี่ยน input / flow หน้าเว็บ
ระดับ 4 (ต้องเข้าใจ): แก้ Service            → เปลี่ยนกฎธุรกิจ
ระดับ 5 (ต้องระวัง):  แก้ Repository / DB    → เปลี่ยนโครงสร้างข้อมูล
```

### ⚠️ สำรองไฟล์ก่อนแก้ทุกครั้ง

- ใช้ Git: `git add .` → `git commit -m "ก่อนแก้..."` หรือ
- Copy โฟลเดอร์ทั้งหมดเก็บไว้ก่อนแก้
- ถ้าแก้แล้วพัง จะได้ย้อนกลับได้

---

## 🎨 2. แก้หน้าตา / UI

### 2.1 เปลี่ยนชื่อเว็บ / ค่าพื้นฐาน

| อยากแก้ | แก้ที่ไฟล์ | แก้ตรงไหน |
|--------|----------|---------|
| ชื่อเว็บ | `.env` | `APP_NAME="ชื่อใหม่"` |
| URL เว็บ | `.env` | `APP_URL="http://..."` |
| ค่าปรับต่อวัน | `.env` | `FINE_PER_DAY=10` |
| จำนวนวันยืม | `.env` | `DEFAULT_BORROW_DAYS=7` |
| จำนวนเล่มสูงสุดที่ยืมได้ | `.env` | `MAX_BORROW_BOOKS=3` |
| ความยาวรหัสผ่านขั้นต่ำ | `.env` | `MIN_PASSWORD_LENGTH=6` |

💡 แก้แล้ว save → refresh หน้าเว็บ เห็นผลทันที

### 2.2 เปลี่ยนสีเว็บ

| อยากแก้ | แก้ที่ไฟล์ | วิธี |
|--------|----------|-----|
| สีหลักหน้า public | `includes/header.php` | ค้นหา `tailwind.config` → เปลี่ยนค่าสีในกลุ่ม `primary` |
| สีหลักหน้า admin | `admin/header.php` | ค้นหา `tailwind.config` → เปลี่ยนค่าสีในกลุ่ม `primary` |
| สี CSS เพิ่มเติม | `css/style.css` | ค้นหา `:root { }` ด้านบน → เปลี่ยนค่าสี |

💡 ลองเปลี่ยนทีละค่า → save → refresh ดูผลทีละจุด

### 2.3 เปลี่ยนข้อความ / ปุ่ม / layout

| อยากแก้ | แก้ที่ไฟล์ |
|--------|----------|
| ข้อความหน้าแรก (public) | `index.php` |
| ข้อความหน้ารายละเอียดหนังสือ | `book.php` |
| ข้อความหน้า login | `login.php` |
| ข้อความหน้าสมัครสมาชิก | `register.php` |
| ข้อความหน้า profile | `profile.php` |
| ข้อความหน้ายืมของฉัน | `my_borrows.php` |
| ข้อความหน้าจองของฉัน | `my_reservations.php` |
| ข้อความหน้าลืมรหัสผ่าน | `forgot_password.php` |
| ข้อความหน้ารีเซ็ตรหัสผ่าน | `reset_password.php` |

| อยากแก้หน้า admin | แก้ที่ไฟล์ |
|---------|----------|
| Dashboard | `admin/index.php` |
| จัดการหนังสือ | `admin/books.php` |
| ฟอร์มเพิ่ม/แก้หนังสือ | `admin/book_form.php` |
| จัดการสมาชิก | `admin/members.php` |
| ฟอร์มเพิ่ม/แก้สมาชิก | `admin/member_form.php` |
| รายการยืม-คืน | `admin/borrows.php` |
| ฟอร์มยืมหนังสือ | `admin/borrow_form.php` |
| การจอง | `admin/reservations.php` |
| ชำระค่าปรับ | `admin/payments.php` |
| หมวดหมู่ | `admin/categories.php` |
| รายงาน | `admin/reports.php` |
| ตั้งค่าระบบ | `admin/settings.php` |
| นำเข้าหนังสือ | `admin/import_books.php` |
| นำเข้าสมาชิก | `admin/import_members.php` |
| พิมพ์ label หนังสือ | `admin/book_labels.php` |
| พิมพ์บัตรสมาชิก | `admin/member_card.php` |
| Export PDF | `admin/export_pdf.php` |

### 2.4 เปลี่ยน Header / Footer / เมนู

| ส่วน | แก้ที่ไฟล์ |
|-----|----------|
| Header หน้า public (เมนู, logo) | `includes/header.php` |
| Footer หน้า public | `includes/footer.php` |
| Header หน้า admin (เมนู sidebar) | `admin/header.php` |
| Footer หน้า admin | `admin/footer.php` |
| การ์ดแสดงหนังสือ (grid) | `includes/book_grid.php` |
| Modal (popup) | `includes/modal.js` |

### 2.5 เปลี่ยน CSS / Stylesheet

| ไฟล์ | หน้าที่ |
|------|--------|
| `css/style.css` | CSS หลักทั้งเว็บ (custom styles) |
| `includes/header.php` | Tailwind config (สีหลัก) + CDN link |
| `admin/header.php` | Tailwind config (สีหลัก) สำหรับ admin |

💡 ระบบใช้ **Tailwind CSS (CDN)** — สีส่วนใหญ่ตั้งใน JavaScript config ในไฟล์ header ไม่ใช่ CSS ปกติ

---

## 👤 3. แก้เรื่องผู้ใช้ (User / Login / Role)

### 3.1 เปลี่ยนเงื่อนไขการสมัครสมาชิก

| อยากแก้ | แก้ที่ไฟล์ | ระดับ |
|--------|----------|------|
| เงื่อนไขข้อมูลสมาชิก (ชื่อ, email, ...) | `includes/functions.php` → ฟังก์ชัน `validateMemberData()` | Validation |
| เงื่อนไขรหัสผ่าน | `includes/functions.php` → ฟังก์ชัน `validatePassword()` | Validation |
| ความยาวรหัสผ่านขั้นต่ำ | `.env` → `MIN_PASSWORD_LENGTH=6` | Config |
| ฟอร์มสมัคร (UI) | `register.php` | HTML |
| Logic การสมัคร | `app/Services/AuthService.php` → `register()` | Service |
| บันทึกลง DB | `app/Repositories/UserRepository.php` → `create()` | Repository |

### 3.2 เพิ่ม field ใหม่ให้สมาชิก (เช่น เบอร์โทร, LINE ID)

ต้องแก้ **5 จุด** เรียงตามลำดับ:

```
1️⃣ ฐานข้อมูล
   เปิด phpMyAdmin → ตาราง users → เพิ่ม column ใหม่
   เช่น: phone VARCHAR(20), line_id VARCHAR(100)

2️⃣ ฟอร์มสมัคร (public)
   ไฟล์: register.php
   เพิ่ม <input> สำหรับ field ใหม่

3️⃣ ฟอร์มแก้ไขสมาชิก (admin)
   ไฟล์: admin/member_form.php
   เพิ่ม <input> สำหรับ field ใหม่

4️⃣ ฟอร์มโปรไฟล์ (member)
   ไฟล์: profile.php
   เพิ่ม <input> สำหรับ field ใหม่

5️⃣ Validation + Service + Repository
   ไฟล์: includes/functions.php → validateMemberData()
   ไฟล์: app/Services/MemberService.php → createMember(), updateMember()
   ไฟล์: app/Services/AuthService.php → register()
   ไฟล์: app/Repositories/UserRepository.php → create(), update()
   เพิ่ม field ใหม่ในแต่ละฟังก์ชัน
```

### 3.3 เปลี่ยนระบบ Role / สิทธิ์

| อยากแก้ | แก้ที่ไฟล์ |
|--------|----------|
| ตรวจสิทธิ์เข้าหน้า (redirect) | `includes/functions.php` → `requireLogin()`, `requireStaff()`, `requireAdmin()` |
| ตรวจสิทธิ์ API (JSON 403) | `includes/functions.php` → `requireStaffApi()`, `requireAdminApi()` |
| เช็คว่าเป็น role อะไร | `includes/functions.php` → `isAdmin()`, `isStaff()`, `isMember()` |
| เมนูที่แสดง/ซ่อนตาม role | `includes/header.php` + `admin/header.php` |

⚠️ **ระบบมี 3 role ตายตัว:** admin, staff, member — ถ้าอยากเพิ่ม role ใหม่ ต้องแก้ทั้ง DB + functions + ทุกหน้าที่เช็คสิทธิ์

### 3.4 เปลี่ยน Login / Logout

| อยากแก้ | แก้ที่ไฟล์ |
|--------|----------|
| หน้า login (UI) | `login.php` |
| Logic การ login | `app/Services/AuthService.php` → `login()` |
| Logic การ logout | `logout.php` |
| ลืมรหัสผ่าน (UI) | `forgot_password.php` |
| Logic ลืมรหัสผ่าน | `app/Services/AuthService.php` → `requestPasswordReset()` |
| รีเซ็ตรหัสผ่าน (UI) | `reset_password.php` |
| Logic รีเซ็ตรหัสผ่าน | `app/Services/AuthService.php` → `resetPassword()` |
| เปลี่ยนรหัสผ่าน (ในโปรไฟล์) | `app/Services/AuthService.php` → `changePassword()` |

---

## 📚 4. แก้ Logic หลักของระบบ

### 4.1 ระบบยืมหนังสือ

| อยากแก้ | แก้ที่ไฟล์ |
|--------|----------|
| จำนวนเล่มสูงสุดที่ยืมได้ | `.env` → `MAX_BORROW_BOOKS=3` |
| จำนวนวันยืม default | `.env` → `DEFAULT_BORROW_DAYS=7` |
| ฟอร์มยืม (UI) | `admin/borrow_form.php` |
| Logic ยืม (กฎทั้งหมด) | `app/Services/BorrowService.php` → `createBorrow()` |
| เงื่อนไขโควต้า | `app/Services/BorrowService.php` → ส่วน quota check ใน `createBorrow()` |
| ตรวจยืมซ้ำ | `app/Repositories/BorrowRepository.php` → `isAlreadyBorrowing()` |
| SQL สร้างรายการยืม | `app/Repositories/BorrowRepository.php` → `create()` |
| SQL หัก stock | `app/Repositories/BookRepository.php` → `decrementAvailable()` |

### 4.2 ระบบคืนหนังสือ

| อยากแก้ | แก้ที่ไฟล์ |
|--------|----------|
| หน้ารายการยืม-คืน (UI) | `admin/borrows.php` |
| Logic คืน | `app/Services/BorrowService.php` → `returnBook()` |
| คำนวณค่าปรับ | `app/Services/BorrowService.php` → `calculateFine()` |
| ค่าปรับต่อวัน | `.env` → `FINE_PER_DAY=10` |
| SQL คืน stock | `app/Repositories/BookRepository.php` → `incrementAvailable()` |

### 4.3 ระบบค่าปรับ

| อยากแก้ | แก้ที่ไฟล์ |
|--------|----------|
| ค่าปรับต่อวัน | `.env` → `FINE_PER_DAY=10` |
| Logic คำนวณค่าปรับ | `app/Services/BorrowService.php` → `calculateFine()` |
| Logic ชำระค่าปรับ | `app/Services/BorrowService.php` → `payFine()` |
| หน้าชำระค่าปรับ (UI) | `admin/payments.php` |
| SQL บันทึกการชำระ | `app/Repositories/PaymentRepository.php` |

### 4.4 ระบบจองหนังสือ

| อยากแก้ | แก้ที่ไฟล์ |
|--------|----------|
| Logic สร้างการจอง | `app/Services/ReservationService.php` → `createReservation()` |
| Logic อนุมัติจอง | `app/Services/ReservationService.php` → `fulfillReservation()` |
| Logic ยกเลิกจอง | `app/Services/ReservationService.php` → `cancelReservation()` |
| Logic หมดอายุจอง | `app/Services/ReservationService.php` → `expireOverdueReservations()` |
| หน้าจองของสมาชิก (UI) | `my_reservations.php` |
| หน้าจัดการจอง admin (UI) | `admin/reservations.php` |
| API จอง (JSON) | `api/reserve_book.php` |
| API ยกเลิกจอง (JSON) | `api/cancel_reservation.php` |

### 4.5 ระบบจัดการหนังสือ

| อยากแก้ | แก้ที่ไฟล์ |
|--------|----------|
| ฟอร์มเพิ่ม/แก้หนังสือ (UI) | `admin/book_form.php` |
| Validation หนังสือ | `includes/functions.php` → `validateBookData()` |
| Logic CRUD หนังสือ | `app/Services/BookService.php` |
| SQL หนังสือ | `app/Repositories/BookRepository.php` |
| นำเข้าจาก CSV | `admin/import_books.php` |
| จัดการหมวดหมู่ | `admin/categories.php` + `app/Repositories/CategoryRepository.php` |

### 4.6 ระบบรายงาน / Dashboard

| อยากแก้ | แก้ที่ไฟล์ |
|--------|----------|
| Dashboard admin (UI) | `admin/index.php` |
| ข้อมูล Dashboard | `app/Services/DashboardService.php` |
| หน้ารายงาน (UI) | `admin/reports.php` |
| Logic รายงาน | `app/Services/ReportService.php` |
| SQL รายงาน (สถิติ, join) | `app/Repositories/ReportRepository.php` |
| Export PDF | `admin/export_pdf.php` + `includes/report_helper.php` |

### 4.7 หน้าแรก (Public)

| อยากแก้ | แก้ที่ไฟล์ |
|--------|----------|
| หน้าแรก (UI) | `index.php` |
| ข้อมูลหน้าแรก | `app/Services/HomeService.php` |
| หน้ารายละเอียดหนังสือ | `book.php` |
| API ค้นหาหนังสือ | `api/search_books.php` |

---

## 🗄️ 5. แก้ฐานข้อมูล (Database)

### 5.1 ตารางอยู่ไหน?

| ไฟล์ | หน้าที่ |
|------|--------|
| `database/schema.sql` | โครงสร้างตารางทั้งหมด (สร้างตอน install) |
| `database/sample_data.sql` | ข้อมูลตัวอย่าง |
| `database/migrations/` | ไฟล์อัปเดตโครงสร้าง (เวอร์ชันใหม่) |
| `install.php` | ตัวสร้างตาราง + ข้อมูลเริ่มต้นอัตโนมัติ |

### 5.2 ตารางทั้งหมดในระบบ

| ตาราง | เก็บอะไร | Repository ที่ใช้ |
|-------|---------|-----------------|
| `users` | ผู้ใช้ทุก role (admin, staff, member) | `UserRepository.php` |
| `books` | หนังสือ + quantity + available | `BookRepository.php` |
| `categories` | หมวดหมู่หนังสือ | `CategoryRepository.php` |
| `borrows` | รายการยืม-คืน + ค่าปรับ | `BorrowRepository.php` |
| `reservations` | การจองหนังสือ | `ReservationRepository.php` |
| `payments` | การชำระค่าปรับ | `PaymentRepository.php` |
| `password_resets` | token รีเซ็ตรหัสผ่าน | `PasswordResetRepository.php` |
| `rate_limits` | ป้องกัน brute force login | (ใช้ผ่าน functions.php) |
| `settings` | ค่าตั้งค่าระบบ (key-value) | `SettingsRepository.php` |

### 5.3 เพิ่ม column ใหม่ต้องทำอะไรบ้าง?

```
ตัวอย่าง: เพิ่ม phone ให้ตาราง users

1️⃣ เพิ่ม column ใน DB
   เปิด phpMyAdmin → ตาราง users → Structure → Add column
   หรือรัน: ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL;

2️⃣ แก้ Repository
   ไฟล์: app/Repositories/UserRepository.php
   เพิ่ม phone ใน INSERT/UPDATE SQL

3️⃣ แก้ Service
   ไฟล์: app/Services/MemberService.php (หรือ AuthService.php)
   รับค่า phone จาก parameter แล้วส่งให้ Repository

4️⃣ แก้ Controller / Form
   ไฟล์: register.php, profile.php, admin/member_form.php
   เพิ่ม <input name="phone"> ในฟอร์ม

5️⃣ แก้ Validation (ถ้าต้องการ)
   ไฟล์: includes/functions.php → validateMemberData()
   เพิ่มเงื่อนไขตรวจ phone
```

### 5.4 Constraint สำคัญที่ต้องระวัง

| ตาราง | Constraint | ห้ามทำอะไร |
|-------|-----------|----------|
| `users` | UNIQUE on `email` | ห้ามใส่ email ซ้ำ |
| `payments` | UNIQUE on `borrow_id` | ชำระค่าปรับได้แค่ครั้งเดียวต่อรายการยืม |
| `borrows` | FOREIGN KEY → `users`, `books` | ห้ามลบ user/book ที่มีรายการยืมอยู่ |
| `reservations` | FOREIGN KEY → `users`, `books` | ห้ามลบ user/book ที่มีการจองอยู่ |
| `books` | `available` ≥ 0 | stock ห้ามติดลบ (มี WHERE guard ใน SQL) |

⚠️ **ถ้าลบข้อมูลใน phpMyAdmin ตรงๆ** ต้องลบตาราง "ลูก" ก่อน "แม่":
- ลบ borrows/reservations ก่อน → แล้วค่อยลบ users/books
- ถ้าลบสลับลำดับจะเจอ Foreign Key error

---

## ⚠️ 6. จุดที่ "ไม่แนะนำให้แก้" (Danger Zone)

### จุดเหล่านี้แก้ผิดแล้วระบบจะพังหรือไม่ปลอดภัย

| จุดที่ห้ามแก้ | อยู่ที่ไหน | ถ้าแก้ผิดจะเกิดอะไร |
|------------|----------|-----------------|
| **`beginTransaction()` / `commit()` / `rollBack()`** | Service ทุกตัว | stock ผิดเมื่อเกิด error (หัก stock แล้ว rollback ไม่ได้) |
| **`SELECT ... FOR UPDATE`** | Repository ทุกตัว | race condition → stock ติดลบ / เกินจริง |
| **`WHERE available > 0`** ใน `decrementAvailable()` | `BookRepository.php` | stock ติดลบได้ |
| **`session_regenerate_id(true)`** | `login.php` | session fixation attack (คนร้ายขโมย session ได้) |
| **`e()` ครอบ output** | Controller ทุกตัว | XSS attack (คนร้ายฝัง script ในหน้าเว็บได้) |
| **`validateCSRFToken()`** | Controller ทุกตัว | CSRF attack (คนร้ายหลอกส่ง form ได้) |
| **`password_hash()` / `password_verify()`** | `functions.php` + `AuthService.php` | รหัสผ่านไม่ปลอดภัย |
| **ลำดับ lock (user ก่อน book)** | `BorrowService.php` | deadlock (ระบบค้าง 2 ฝ่ายรอกันไม่จบ) |
| **ลำดับ require ใน `bootstrap.php`** | `bootstrap.php` | ระบบพัง (ค่าคงที่ยังไม่ถูกสร้าง) |
| **`hashPassword()`** | `functions.php` | ทุกจุดที่สร้าง/เปลี่ยนรหัสผ่านจะไม่ปลอดภัย |

### ถ้าจะแก้ส่วนเหล่านี้:

1. ต้อง **เข้าใจว่าทำหน้าที่อะไร** ก่อน (อ่าน `FLOW.md` หัวข้อ 6)
2. ต้อง **backup ก่อนแก้**
3. ต้อง **ทดสอบให้ครบ** หลังแก้ (ลองยืม คืน จอง ค่าปรับ ทุก flow)
4. ถ้าไม่แน่ใจ → **อย่าแก้** ถาม support ก่อน

---

## ✅ 7. ตัวอย่างคำถามยอดฮิต + คำตอบ

### "อยากเปลี่ยนชื่อเว็บ"

→ แก้ไฟล์ `.env` → เปลี่ยน `APP_NAME="ชื่อใหม่"` → save → refresh

---

### "อยากเปลี่ยนค่าปรับต่อวัน"

→ แก้ไฟล์ `.env` → เปลี่ยน `FINE_PER_DAY=20` (ใส่ตัวเลขที่ต้องการ) → save → refresh

---

### "อยากเปลี่ยนจำนวนเล่มที่ยืมได้"

→ แก้ไฟล์ `.env` → เปลี่ยน `MAX_BORROW_BOOKS=5` → save → refresh

---

### "อยากปิดไม่ให้สมัครสมาชิก"

→ แก้ไฟล์ `includes/header.php` → ค้นหาปุ่ม/ลิงก์ "สมัครสมาชิก" → ครอบด้วย comment:
```html
<!-- ปิดปุ่มสมัคร
<a href="register.php">สมัครสมาชิก</a>
-->
```
→ ทำเหมือนกันใน `login.php` (ถ้ามีลิงก์ไปหน้าสมัคร)

---

### "อยากซ่อนปุ่มบางปุ่ม"

→ เปิดไฟล์หน้าที่มีปุ่มนั้น → ค้นหาข้อความบนปุ่ม (Ctrl+F) → ครอบด้วย comment:
```html
<!-- ซ่อนปุ่ม
<button>ข้อความปุ่ม</button>
-->
```
→ save → refresh → ปุ่มจะหายไป (เอา comment ออก = ปุ่มกลับมา)

---

### "อยากเปลี่ยนสีเว็บ"

→ เปิด `includes/header.php` → ค้นหา `tailwind.config` → เปลี่ยนค่าสีในกลุ่ม `primary`
→ ทำเหมือนกันใน `admin/header.php`
→ (เสริม) แก้ `css/style.css` ส่วน `:root { }` ด้วย

---

### "อยากเพิ่มช่อง LINE ID ให้สมาชิก"

→ ดูหัวข้อ 3.2 ข้างบน (ต้องแก้ 5 จุด: DB → ฟอร์ม → Validation → Service → Repository)

---

### "อยากลบข้อมูลตัวอย่างทั้งหมด"

→ วิธีง่าย:
1. เปิด phpMyAdmin → ลบ database `book_borrowing`
2. ลบไฟล์ `.installed` ในโฟลเดอร์โปรเจกต์
3. เปิด `install.php` ใหม่ → ไม่ต้องเลือก "ใส่ข้อมูลตัวอย่าง"

---

### "password ใน DB เป็นตัวยาวๆ แปลกๆ"

→ ถูกต้องแล้ว ไม่ใช่ bug! รหัสผ่านถูก "เข้ารหัส" (hash) เพื่อความปลอดภัย
→ ถ้าอยากเปลี่ยนรหัสผ่าน ให้เปลี่ยนผ่านหน้าเว็บ (โปรไฟล์) หรือให้ admin สร้างสมาชิกใหม่
→ ❌ **ห้ามแก้ค่าใน DB โดยตรง**

---

### "stock หนังสือไม่ตรง"

→ เช็คก่อน: ยืม/คืนผ่านหน้า admin หรือแก้ DB โดยตรง?
→ ถ้าแก้ DB ตรงๆ stock จะไม่สัมพันธ์กับรายการยืม (ระบบคำนวณอัตโนมัติ)
→ ถ้ายืม/คืนผ่านระบบแล้ว stock ยังไม่ตรง → แจ้ง support พร้อม screenshot

---

### "เปิดเว็บแล้วหน้าขาว"

→ เปิด `.env` → เปลี่ยน `APP_DEBUG=true` → refresh → จะเห็น error จริง
→ สาเหตุที่พบบ่อย:
  - ยังไม่ได้สร้างไฟล์ `.env` (ต้อง copy จาก `.env.example`)
  - ยังไม่ได้รัน `install.php`
  - Apache/MySQL ยังไม่ได้เปิด

---

## 🧭 8. สรุปสำหรับมือใหม่

### ✅ แก้ได้ปลอดภัย (เริ่มจากตรงนี้)

| ระดับ | แก้อะไร | ไฟล์ |
|------|--------|------|
| 🟢 **ง่ายมาก** | ชื่อเว็บ, ค่าปรับ, จำนวนวันยืม | `.env` |
| 🟢 **ง่าย** | ข้อความ, ปุ่ม, layout, สี | ไฟล์ .php ที่ root/ และ admin/, css/style.css |
| 🟡 **ปานกลาง** | เพิ่ม field, ฟอร์ม, input | register.php, profile.php, admin/member_form.php |

### ⚠️ ถ้าไม่มั่นใจ ควรหยุดตรงนี้

| ระดับ | แก้อะไร | ไฟล์ |
|------|--------|------|
| 🟠 **ต้องเข้าใจ** | กฎธุรกิจ (โควต้า, ค่าปรับ, stock) | app/Services/*.php |
| 🔴 **ต้องระวัง** | SQL, ฐานข้อมูล, โครงสร้างตาราง | app/Repositories/*.php, phpMyAdmin |
| ⛔ **อย่าแก้** | Transaction, Lock, Security, Password | ดูหัวข้อ 6 |

### 🆘 แนะนำให้ถาม Support เมื่อ:

- แก้แล้วระบบพัง กลับไม่ได้ (ไม่มี backup)
- เจอ error ที่อ่านไม่ออกหลังติดตั้งครั้งแรก
- โค้ดต้นฉบับ (ที่ยังไม่แก้) ทำงานไม่ถูกต้อง → นี่คือ bug แจ้งได้เลย
- อ่านเอกสารแล้วยังไม่เข้าใจ → ถามได้ไม่ต้องเกรงใจ

### 📖 เอกสารอื่นที่ช่วยได้

- `README.md` — ภาพรวมระบบ + วิธีติดตั้ง
- `FLOW.md` — ภาพรวมการทำงาน (flow ของแต่ละ feature)
- `ARCHITECTURE.md` — โครงสร้างระบบ + แนวคิดการออกแบบ
- `FAQ.md` — คำถามที่พบบ่อย
- `SUPPORT.md` — นโยบายการซัพพอร์ต
- `LIMITATIONS.md` — ขอบเขตการใช้งาน
- `STUDY_GUIDE.md` — คู่มือเรียนรู้ระบบเชิงลึก
