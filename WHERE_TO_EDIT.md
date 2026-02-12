# 🗺️ WHERE TO EDIT — แก้ตรงไหน ได้ผลอะไร

> **ไฟล์นี้คือ "แผนที่" ของระบบ**
> ช่วยให้คุณรู้ว่า "ถ้าอยากแก้สิ่งนี้ → ต้องไปแก้ไฟล์ไหน"
> โดยไม่ต้องไล่อ่านโค้ดทั้งหมดเอง

---

## 🔰 1. วิธีใช้ไฟล์นี้ (อ่านก่อน!)

### 📌 ไฟล์นี้คืออะไร?
- เป็น **แผนที่** บอกว่าระบบแต่ละส่วนอยู่ในไฟล์ไหน
- เหมาะสำหรับมือใหม่ที่ซื้อ template ไปแล้วอยากปรับแต่ง
- **ไม่ได้สอนเขียนโค้ด** แต่บอกว่า "แก้ตรงไหน = ได้ผลอะไร"

### 📋 ขั้นตอนก่อนเริ่มแก้
1. ⭐ **Backup ก่อนทุกครั้ง** — ก๊อปปี้โฟลเดอร์ทั้งหมดเก็บไว้ก่อนแก้
2. 📖 อ่านหัวข้อที่ตรงกับสิ่งที่อยากแก้
3. 🔍 ไปเปิดไฟล์ที่ระบุ แล้วค้นหาจุดที่ต้องแก้
4. ✅ ทดสอบทุกครั้งหลังแก้ — เปิดหน้าเว็บดูว่ายังทำงานปกติ
5. ❌ ถ้าพัง → เอา backup กลับมา แล้วลองใหม่ทีละจุด

### 🎯 แนะนำลำดับการอ่าน
- **แก้หน้าตา / ข้อความ** → อ่านหัวข้อ 2 ก่อน (ง่ายสุด ปลอดภัยสุด)
- **แก้เงื่อนไข / กฎระบบ** → อ่านหัวข้อ 3-4
- **แก้ฐานข้อมูล** → อ่านหัวข้อ 5 (ต้องระวัง)
- **ไม่แน่ใจ** → อ่านหัวข้อ 6 ก่อนแก้อะไร

---

## 🎨 2. แก้หน้าตา / UI

### 🏷️ เปลี่ยนชื่อเว็บ / ชื่อระบบ

| อยากแก้อะไร | แก้ตรงไหน | วิธีแก้ |
|-------------|-----------|---------|
| ชื่อเว็บที่แสดงบน title bar | `.env` บรรทัด `APP_NAME=` | เปลี่ยนข้อความในเครื่องหมาย `"..."` |
| ชื่อหน่วยงานบนบัตรสมาชิก | หน้า admin → ตั้งค่าระบบ (`admin/settings.php`) | แก้ผ่านหน้าเว็บได้เลย ไม่ต้องแก้โค้ด |

**ตัวอย่าง:** เปลี่ยนชื่อเว็บ
```
# ไฟล์ .env
APP_NAME="ห้องสมุดโรงเรียน ABC"
```

### 📝 เปลี่ยนข้อความ / คำอธิบาย

| อยากแก้อะไร | แก้ไฟล์ไหน |
|-------------|-----------|
| ข้อความบนหน้าแรก (Hero section, สถิติ) | `index.php` |
| ข้อความหน้ารายละเอียดหนังสือ | `book.php` |
| ข้อความหน้า login | `login.php` |
| ข้อความหน้าสมัครสมาชิก | `register.php` |
| ข้อความหน้าประวัติยืม (สมาชิก) | `my_borrows.php` |
| ข้อความหน้าจองหนังสือ (สมาชิก) | `my_reservations.php` |
| ข้อความหน้าโปรไฟล์ | `profile.php` |
| ข้อความหน้า admin ต่าง ๆ | ไฟล์ใน `admin/` ชื่อตรงกับหน้านั้น ๆ |

> 💡 **เคล็ดลับ:** ข้อความส่วนใหญ่เขียนตรง ๆ ในไฟล์ PHP (ส่วนล่างของไฟล์ หลัง `?>`)
> ค้นหาคำที่อยากแก้ด้วย Ctrl+F แล้วเปลี่ยนได้เลย

### 🎨 เปลี่ยนสี / ธีม / ฟอนต์

| อยากแก้อะไร | แก้ไฟล์ไหน | หมายเหตุ |
|-------------|-----------|----------|
| สีหลัก (primary) หน้า public | `includes/header.php` | ค้นหา `tailwind.config` → แก้สี `primary` |
| สีหลัก (primary) หน้า admin | `admin/header.php` | ค้นหา `tailwind.config` → แก้สี `primary` |
| CSS เพิ่มเติม / animation | `css/style.css` | ตัวแปรสีอยู่ใน `:root { }` บรรทัดแรก ๆ |
| ฟอนต์ | `includes/header.php` + `admin/header.php` | ค้นหา `Google Fonts` → เปลี่ยน URL + ชื่อฟอนต์ |
| สีบัตรสมาชิก | หน้า admin → ตั้งค่าระบบ | แก้ผ่านหน้าเว็บได้เลย |

**ตัวอย่าง:** เปลี่ยนสีหลักเป็นสีเขียว (ใน `includes/header.php`)
```javascript
// ค้นหา tailwind.config แล้วเปลี่ยนค่าสี primary
colors: {
    primary: {
        500: '#22c55e',  // เขียว แทน น้ำเงิน
        600: '#16a34a',
        // ... เปลี่ยนทุกเฉด
    }
}
```

### 🖼️ เปลี่ยน layout / ซ่อนปุ่ม / เพิ่มเมนู

| อยากแก้อะไร | แก้ไฟล์ไหน |
|-------------|-----------|
| เมนู navbar (หน้า public) | `includes/header.php` |
| เมนู sidebar (หน้า admin) | `admin/header.php` |
| การ์ดหนังสือบนหน้าแรก | `includes/book_grid.php` |
| Footer (หน้า public) | `includes/footer.php` |
| Footer (หน้า admin) | `admin/footer.php` |
| ซ่อนปุ่มบนหน้าใดหน้าหนึ่ง | ไปที่ไฟล์หน้านั้น ค้นหาปุ่ม แล้วลบหรือครอบด้วย `<!-- ... -->` |

> 💡 **ซ่อนปุ่ม:** ค้นหาข้อความบนปุ่มด้วย Ctrl+F แล้วครอบ HTML ของปุ่มด้วย comment
> ```html
> <!-- ซ่อนปุ่มนี้ไว้
> <button>ปุ่มที่ไม่ต้องการ</button>
> -->
> ```

---

## 👤 3. แก้เรื่องผู้ใช้ (User / Login / Role)

### 📌 ไฟล์ที่เกี่ยวข้องกับผู้ใช้

| ระดับ | ไฟล์ | ทำหน้าที่ |
|-------|------|-----------|
| 🖥️ หน้าเว็บ | `register.php` | ฟอร์มสมัครสมาชิก |
| 🖥️ หน้าเว็บ | `login.php` | ฟอร์ม login |
| 🖥️ หน้าเว็บ | `profile.php` | หน้าโปรไฟล์ + แก้ไขข้อมูล |
| 🖥️ หน้าเว็บ | `forgot_password.php` | ลืมรหัสผ่าน |
| 🖥️ หน้าเว็บ | `reset_password.php` | ตั้งรหัสผ่านใหม่ |
| 🖥️ หน้าเว็บ | `admin/members.php` | จัดการสมาชิก (admin) |
| 🖥️ หน้าเว็บ | `admin/member_form.php` | ฟอร์มเพิ่ม/แก้สมาชิก (admin) |
| ⚙️ Logic | `app/Services/AuthService.php` | Login / Register / เปลี่ยนรหัสผ่าน |
| ⚙️ Logic | `app/Services/MemberService.php` | CRUD สมาชิก + validation |
| 🗄️ Database | `app/Repositories/UserRepository.php` | SQL query เกี่ยวกับ users |
| ✅ Validation | `includes/functions.php` | `validateMemberData()` ตรวจข้อมูลสมาชิก |

### 🔧 สถานการณ์ที่พบบ่อย

#### อยากเปลี่ยนเงื่อนไขสมัครสมาชิก
- **เช่น:** ต้องกรอกเบอร์โทร, ชื่อต้องยาวขึ้น
- **แก้ที่:** `includes/functions.php` → ฟังก์ชัน `validateMemberData()`
- **ระดับ:** validation (ตรวจสอบข้อมูล)

#### อยากเพิ่ม field ใหม่ (เช่น ที่อยู่, LINE ID)
- ⚠️ **ต้องแก้หลายจุด** — ถ้าไม่มั่นใจ แนะนำติดต่อ support
- แก้ตามลำดับ:
  1. **ฐานข้อมูล:** เพิ่ม column ใน table `users` (ดูหัวข้อ 5)
  2. **ฟอร์ม:** เพิ่ม input ใน `register.php`, `profile.php`, `admin/member_form.php`
  3. **Validation:** เพิ่มเงื่อนไขใน `includes/functions.php` → `validateMemberData()`
  4. **Service:** เพิ่ม field ใน `app/Services/MemberService.php` → `createMember()` / `updateMember()`
  5. **Repository:** เพิ่ม field ใน `app/Repositories/UserRepository.php` → `create()` / `update()`

#### อยากเปลี่ยนเงื่อนไขรหัสผ่าน (เช่น ต้อง 8 ตัวขึ้นไป)
- **แก้ที่:** `.env` → `MIN_PASSWORD_LENGTH=8`
- **ไม่ต้องแก้โค้ด** ระบบอ่านค่าจาก `.env` อัตโนมัติ

#### อยากเปลี่ยน role (เพิ่ม role ใหม่)
- ⚠️ **ยากมาก** — ไม่แนะนำสำหรับมือใหม่
- ระบบมี 3 role คือ `member`, `staff`, `admin`
- ถ้าต้องการเพิ่ม role ใหม่ ต้องแก้:
  - ฐานข้อมูล (ENUM ในตาราง `users`)
  - `includes/functions.php` (เพิ่มฟังก์ชันตรวจสิทธิ์)
  - ทุกหน้าที่ตรวจสิทธิ์ (`requireStaff()`, `requireAdmin()`)
- 💡 **ทางเลือก:** ใช้ `staff` เป็น role กลาง แล้วจำกัดเมนูใน `admin/header.php` แทน

#### อยากปิดการสมัครสมาชิก (ไม่ให้คนสมัครเอง)
- **แก้ที่:** `register.php` — เพิ่มโค้ดนี้ต่อจากบรรทัด `require_once __DIR__ . '/bootstrap.php';`
  ```php
  // ปิดการสมัครสมาชิก
  setFlash('info', 'ขณะนี้ปิดรับสมัครสมาชิก');
  redirect(APP_URL . '/login.php');
  ```
- **เสริม:** ซ่อนลิงก์ "สมัครสมาชิก" ใน `includes/header.php` + `login.php`

---

## 📚 4. แก้ logic หลักของระบบ

### 📌 ไฟล์ที่เกี่ยวข้องกับ logic หลัก

| ระบบ | Service (logic) | Repository (SQL) | หน้าเว็บ |
|------|-----------------|-------------------|----------|
| 📖 ยืม-คืน | `app/Services/BorrowService.php` | `app/Repositories/BorrowRepository.php` | `admin/borrows.php`, `admin/borrow_form.php` |
| 📕 หนังสือ | `app/Services/BookService.php` | `app/Repositories/BookRepository.php` | `admin/books.php`, `admin/book_form.php` |
| 👤 สมาชิก | `app/Services/MemberService.php` | `app/Repositories/UserRepository.php` | `admin/members.php`, `admin/member_form.php` |
| 📊 Dashboard | `app/Services/DashboardService.php` | (ใช้ร่วมกับ repo อื่น) | `admin/index.php` |
| 📈 รายงาน | `app/Services/ReportService.php` | `app/Repositories/ReportRepository.php` | `admin/reports.php` |
| 🏠 หน้าแรก | `app/Services/HomeService.php` | (ใช้ร่วมกับ repo อื่น) | `index.php` |
| 🔐 Login | `app/Services/AuthService.php` | `app/Repositories/UserRepository.php` | `login.php`, `register.php` |
| 📋 การจอง | `app/Services/ReservationService.php` | `app/Repositories/ReservationRepository.php` | `admin/reservations.php`, `my_reservations.php` |
| 💰 ค่าปรับ | (อยู่ใน BorrowService) | `app/Repositories/PaymentRepository.php` | `admin/payments.php` |
| 🏷️ หมวดหมู่ | (ไม่มี Service แยก) | `app/Repositories/CategoryRepository.php` | `admin/categories.php` |
| ⚙️ ตั้งค่า | (ไม่มี Service แยก) | `app/Repositories/SettingsRepository.php` | `admin/settings.php` |

### 🔧 สถานการณ์ที่พบบ่อย

#### อยากเปลี่ยนจำนวนวันยืม / จำนวนเล่มที่ยืมได้ / ค่าปรับ
- **แก้ที่:** `.env` — **ไม่ต้องแก้โค้ด**

| ค่า | ตัวแปรใน `.env` | ค่าเริ่มต้น |
|-----|----------------|-------------|
| จำนวนวันยืม | `DEFAULT_BORROW_DAYS` | 7 วัน |
| ยืมได้สูงสุดกี่เล่ม | `MAX_BORROW_BOOKS` | 3 เล่ม |
| ค่าปรับต่อวัน (บาท) | `FINE_PER_DAY` | 10 บาท |

#### อยากเปลี่ยนเงื่อนไขการยืม (เช่น ต้องไม่มีค่าปรับค้าง)
- **แก้ที่:** `app/Services/BorrowService.php` → ฟังก์ชัน `createBorrow()`
- **ระดับ:** business logic
- ค้นหาส่วนที่ตรวจเงื่อนไข (เช่น เช็คโควต้า, เช็คยืมซ้ำ) แล้วเพิ่ม/ลดเงื่อนไข

#### อยากเปลี่ยนวิธีคำนวณค่าปรับ
- **แก้ที่:** `app/Services/BorrowService.php` → ฟังก์ชัน `returnBook()`
- **ระดับ:** business logic
- ค้นหาส่วนที่คำนวณ `fine_amount` แล้วเปลี่ยนสูตร

#### อยากเพิ่มหมวดหมู่หนังสือ
- **ไม่ต้องแก้โค้ด** — เพิ่มผ่านหน้า admin → จัดการหมวดหมู่ (`admin/categories.php`)

#### อยากเพิ่ม field ให้หนังสือ (เช่น สำนักพิมพ์, ปีพิมพ์)
- ⚠️ **ต้องแก้หลายจุด** — คล้ายกับเพิ่ม field ผู้ใช้
  1. **ฐานข้อมูล:** เพิ่ม column ใน table `books`
  2. **ฟอร์ม:** `admin/book_form.php`
  3. **Service:** `app/Services/BookService.php` → `createBook()` / `updateBook()`
  4. **Repository:** `app/Repositories/BookRepository.php` → `create()` / `update()`
  5. **แสดงผล:** `book.php`, `includes/book_grid.php`, `admin/books.php`

#### อยากเปลี่ยนการ import หนังสือจาก CSV
- **แก้ที่:** `admin/import_books.php`
- ถ้าเปลี่ยนลำดับ column ใน CSV → ค้นหาส่วนที่อ่าน `$row[0]`, `$row[1]` แล้วปรับลำดับ

#### อยากเปลี่ยนการ import สมาชิกจาก CSV
- **แก้ที่:** `admin/import_members.php`
- เหมือนกับ import หนังสือ

#### อยากเพิ่มรายงานใหม่
- **แก้ที่:**
  1. `app/Repositories/ReportRepository.php` → เพิ่มฟังก์ชัน query ใหม่
  2. `app/Services/ReportService.php` → เรียกฟังก์ชันใหม่
  3. `admin/reports.php` → เพิ่ม UI แสดงผล

#### อยากแก้หน้า Dashboard (admin)
- **แก้ที่:** `admin/index.php` (UI) + `app/Services/DashboardService.php` (ข้อมูล)

---

## 🗄️ 5. แก้ฐานข้อมูล (Database)

### 📌 ไฟล์ที่เกี่ยวข้อง

| ไฟล์ | ทำหน้าที่ |
|------|-----------|
| `database/schema.sql` | โครงสร้างตารางทั้งหมด (สร้างครั้งแรก) |
| `database/sample_data.sql` | ข้อมูลตัวอย่าง |
| `database/migrations/` | ไฟล์ปรับโครงสร้างเพิ่มเติม |
| `includes/db.php` | การเชื่อมต่อฐานข้อมูล (PDO) |
| `.env` | ชื่อ DB, username, password |

### 📋 ตารางในระบบ

| ตาราง | เก็บอะไร | Repository |
|-------|---------|------------|
| `users` | ผู้ใช้ (สมาชิก + staff + admin) | `UserRepository.php` |
| `books` | หนังสือ | `BookRepository.php` |
| `categories` | หมวดหมู่ | `CategoryRepository.php` |
| `borrows` | รายการยืม-คืน | `BorrowRepository.php` |
| `reservations` | การจองหนังสือ | `ReservationRepository.php` |
| `payments` | การจ่ายค่าปรับ | `PaymentRepository.php` |
| `password_resets` | Token ลืมรหัสผ่าน | `PasswordResetRepository.php` |
| `settings` | ตั้งค่าระบบ (ชื่อหน่วยงาน, สีบัตร) | `SettingsRepository.php` |
| `rate_limits` | จำกัดจำนวนครั้ง login/register | (ใช้ใน `functions.php`) |

### ⚠️ สิ่งที่ต้องระวังเวลาแก้ฐานข้อมูล

1. **🔴 Backup ก่อนเสมอ!** — export ฐานข้อมูลก่อนแก้ column ใด ๆ
2. **🔴 FOREIGN KEY (FK)** — ตาราง `borrows`, `reservations`, `payments` เชื่อมกับ `users` และ `books`
   - ถ้าลบ user → borrows ของ user นั้นจะถูกลบตามด้วย (ON DELETE CASCADE)
   - ถ้าลบ book → borrows ของ book นั้นจะถูกลบตามด้วย
3. **🔴 UNIQUE constraint**
   - `users.email` → อีเมลห้ามซ้ำ
   - `categories.name` → ชื่อหมวดหมู่ห้ามซ้ำ
   - `payments.borrow_id` → จ่ายค่าปรับได้ครั้งเดียวต่อ 1 รายการยืม
4. **🔴 CHECK constraint** (ตาราง books)
   - `available >= 0` → stock ห้ามติดลบ
   - `quantity >= available` → stock ว่างห้ามเกินจำนวนทั้งหมด
5. **🟡 ENUM** — `users.role` รองรับเฉพาะ `member`, `staff`, `admin`
   - ถ้าเพิ่ม role → ต้อง ALTER TABLE เปลี่ยน ENUM ด้วย

### 💡 วิธีเพิ่ม column ใหม่ (ตัวอย่าง)

สมมติอยากเพิ่ม "ที่อยู่" ให้สมาชิก:
```sql
-- รันใน phpMyAdmin
ALTER TABLE users ADD COLUMN address TEXT DEFAULT NULL COMMENT 'ที่อยู่' AFTER phone;
```
จากนั้นต้องแก้โค้ดด้วย (ดูหัวข้อ 3 เรื่องเพิ่ม field ใหม่)

---

## ⚠️ 6. จุดที่ "ไม่แนะนำให้แก้" (Danger Zone)

### 🔴 ห้ามแก้เด็ดขาด (ถ้าไม่เข้าใจจริง ๆ)

| ไฟล์ / ส่วน | ทำหน้าที่ | ถ้าแก้ผิดจะเกิดอะไร |
|-------------|-----------|---------------------|
| `includes/functions.php` → `e()` | ป้องกัน XSS (แสดงข้อมูลปลอดภัย) | แฮกเกอร์ฝังโค้ดอันตรายในเว็บได้ |
| `includes/functions.php` → `generateCSRFToken()` / `validateCSRFToken()` | ป้องกัน CSRF (ปลอมแปลงคำสั่ง) | แฮกเกอร์หลอกให้ admin ลบข้อมูลได้ |
| `includes/functions.php` → `hashPassword()` | เข้ารหัสรหัสผ่าน | รหัสผ่านหลุดได้ |
| `includes/functions.php` → `startSession()` | จัดการ session (login) | session ค้าง / ถูก hijack |
| `includes/functions.php` → `checkRateLimit()` | จำกัดจำนวนครั้ง login | ถูก brute force รหัสผ่านได้ |
| `includes/functions.php` → `requireLogin()` / `requireStaff()` / `requireAdmin()` | ตรวจสิทธิ์เข้าถึง | คนไม่มีสิทธิ์เข้าหน้า admin ได้ |

### 🔴 ห้ามลบ / แก้ไข

| โค้ดส่วนนี้ | อยู่ในไฟล์ | เหตุผล |
|-------------|-----------|--------|
| `FOR UPDATE` ใน SQL query | `BookRepository.php`, `BorrowRepository.php` | ป้องกัน 2 คนยืม/คืนพร้อมกัน (race condition) |
| `WHERE available > 0` | `BookRepository.php` → `decrementAvailable()` | ป้องกัน stock ติดลบ |
| `WHERE available < quantity` | `BookRepository.php` → `incrementAvailable()` | ป้องกัน stock เกินจำนวน |
| `beginTransaction()` / `commit()` / `rollBack()` | Service files ทุกไฟล์ | ป้องกันข้อมูลเสียหายระหว่างทำรายการ |
| `validateCSRFToken()` | ทุกหน้าที่รับ POST | ป้องกันถูกปลอมแปลงคำสั่ง |

### 🟡 แก้ได้แต่ต้องระวัง

| ส่วน | ไฟล์ | ความเสี่ยง |
|------|------|-----------|
| `bootstrap.php` | `bootstrap.php` | ถ้าลำดับ require ผิด → เว็บพังทั้งหมด |
| `includes/db.php` | `includes/db.php` | ถ้า PDO config ผิด → เชื่อมต่อ DB ไม่ได้ |
| Autoloader | `bootstrap.php` | ถ้า namespace mapping ผิด → class โหลดไม่ได้ |
| `.htaccess` | `.htaccess`, `app/.htaccess`, `includes/.htaccess` | ถ้าแก้ผิด → เว็บ 500 error / security หลุด |

---

## ✅ 7. ตัวอย่างคำถามยอดฮิต + คำตอบ

### ❓ "อยากเปลี่ยนชื่อเว็บ"
✅ แก้ที่ `.env` → `APP_NAME="ชื่อใหม่"`

### ❓ "อยากปิดสมัครสมาชิก"
✅ เพิ่มโค้ด redirect ใน `register.php` (ดูหัวข้อ 3)
✅ ซ่อนลิงก์ "สมัครสมาชิก" ใน `includes/header.php` + `login.php`

### ❓ "อยากซ่อนปุ่มบางปุ่ม"
✅ เปิดไฟล์หน้านั้น → Ctrl+F ค้นหาข้อความบนปุ่ม → ครอบด้วย `<!-- ... -->`

### ❓ "อยากเปลี่ยนให้ยืมได้ 5 เล่ม"
✅ แก้ที่ `.env` → `MAX_BORROW_BOOKS=5`

### ❓ "อยากเปลี่ยนค่าปรับเป็น 20 บาท/วัน"
✅ แก้ที่ `.env` → `FINE_PER_DAY=20`

### ❓ "อยากเปลี่ยนจำนวนวันยืมเป็น 14 วัน"
✅ แก้ที่ `.env` → `DEFAULT_BORROW_DAYS=14`

### ❓ "อยากเปลี่ยนสีเว็บ"
✅ แก้ที่ `includes/header.php` + `admin/header.php` → ส่วน `tailwind.config` → เปลี่ยนค่าสี
✅ แก้ที่ `css/style.css` → `:root { }` เปลี่ยน CSS variables

### ❓ "อยากเพิ่มช่อง LINE ID ให้สมาชิก"
⚠️ ต้องแก้หลายจุด (ดูหัวข้อ 3 → เพิ่ม field ใหม่)

### ❓ "อยากเพิ่ม role ใหม่"
⚠️ ยากมาก — แนะนำติดต่อ support (ดูหัวข้อ 3)

### ❓ "อยากให้ระบบส่ง email แจ้งเตือน"
⚠️ ระบบยังไม่มีฟีเจอร์นี้ — ต้องเขียนเพิ่มเอง หรือติดต่อ support

### ❓ "เปลี่ยน password ใน .env ไม่ได้"
✅ `.env` เก็บ password ฐานข้อมูล (DB_PASS) ไม่ใช่ password login
✅ password login → เปลี่ยนผ่านหน้าเว็บ (โปรไฟล์) หรือ admin สร้างใหม่

### ❓ "อยากลบข้อมูลตัวอย่าง"
✅ เข้า phpMyAdmin → เลือก DB `book_borrowing` → ลบข้อมูลในตาราง (TRUNCATE)
⚠️ ลบตาราง `borrows` ก่อน `books` และ `users` (เพราะมี FK)
💡 หรือลบ DB ทิ้งแล้วรัน `install.php` ใหม่ (ไม่ต้อง import sample data)

### ❓ "อยากเปลี่ยนโลโก้"
✅ ระบบใช้ icon แทนโลโก้ → ค้นหา `bi bi-book` ใน `includes/header.php` + `admin/header.php`
✅ เปลี่ยนเป็น icon อื่น (ดูรายการที่ https://icons.getbootstrap.com) หรือเปลี่ยนเป็น `<img>`

### ❓ "อยากเปลี่ยนฟอนต์"
✅ แก้ที่ `includes/header.php` + `admin/header.php`
✅ เปลี่ยน Google Fonts URL + ชื่อฟอนต์ใน `tailwind.config`

---

## 🧭 8. สรุปสำหรับมือใหม่

### ✅ แก้ได้ปลอดภัย (เสี่ยงต่ำ)

| สิ่งที่แก้ | ไฟล์ | ผลกระทบ |
|-----------|------|---------|
| ชื่อเว็บ / ค่ายืม / ค่าปรับ | `.env` | ไม่มีผลกระทบ เปลี่ยนกลับได้ทันที |
| ข้อความ / คำอธิบาย | ไฟล์ `.php` ต่าง ๆ | แก้ได้ เปลี่ยนกลับง่าย |
| สี / ธีม | `includes/header.php`, `admin/header.php`, `css/style.css` | แก้ได้ ไม่กระทบ logic |
| ซ่อนปุ่ม / เมนู | ไฟล์หน้านั้น ๆ | ครอบด้วย comment ได้ |
| ตั้งค่าระบบ | หน้า admin → ตั้งค่าระบบ | แก้ผ่านหน้าเว็บ ไม่ต้องแก้โค้ด |

### 🟡 แก้ได้แต่ต้องระวัง (เสี่ยงปานกลาง)

| สิ่งที่แก้ | ต้องแก้กี่จุด | แนะนำ |
|-----------|-------------|-------|
| เปลี่ยนเงื่อนไข validation | 1 จุด | อ่านโค้ดเดิมให้เข้าใจก่อน |
| เปลี่ยน business rule (เช่น คำนวณค่าปรับ) | 1-2 จุด | ทดสอบหลายกรณี |
| เพิ่ม field ใหม่ | 4-5 จุด | ทำตามขั้นตอนในหัวข้อ 3 หรือ 4 |

### 🔴 ไม่แนะนำให้แก้ (เสี่ยงสูง)

| สิ่งที่แก้ | เหตุผล |
|-----------|--------|
| Security functions | เว็บถูกแฮกได้ |
| Transaction / Lock (FOR UPDATE) | ข้อมูลเสียหาย |
| Bootstrap / Autoloader | เว็บพังทั้งหมด |
| FOREIGN KEY / constraint | ข้อมูลพัง |

### 🛑 ถ้าไม่มั่นใจ → หยุดก่อน!

- **ก่อนแก้:** ถามตัวเองว่า "ถ้าแก้ผิด กลับมาเหมือนเดิมได้ไหม?"
- **ถ้าตอบไม่ได้:** → Backup ก่อน หรือติดต่อ support
- **ถ้าเว็บพัง:** → เอา backup กลับมา แล้วลองใหม่ทีละจุดเล็ก ๆ

### 📞 ติดต่อ support เมื่อ:
- อยากเพิ่มฟีเจอร์ใหม่ที่ไม่มีในระบบ
- แก้แล้วเว็บพัง เอา backup กลับแล้วก็ยังไม่ได้
- อยากเปลี่ยน role / สิทธิ์ / security
- อยากเชื่อมระบบกับบริการภายนอก (email, LINE, API)
- ไม่แน่ใจว่าสิ่งที่อยากแก้จะกระทบส่วนอื่นหรือไม่

---

## 📁 สรุปโครงสร้างไฟล์ทั้งหมด

```
book_borrowing/
├── .env                          ← ⭐ ตั้งค่าหลัก (ชื่อเว็บ, DB, กฎยืม, ค่าปรับ)
├── .env.example                  ← ตัวอย่าง .env (อ้างอิง)
│
├── index.php                     ← หน้าแรก (public)
├── book.php                      ← รายละเอียดหนังสือ
├── login.php                     ← หน้า login
├── logout.php                    ← ออกจากระบบ
├── register.php                  ← สมัครสมาชิก
├── profile.php                   ← โปรไฟล์ + แก้ไขข้อมูล
├── my_borrows.php                ← ประวัติยืม (สมาชิก)
├── my_reservations.php           ← การจอง (สมาชิก)
├── forgot_password.php           ← ลืมรหัสผ่าน
├── reset_password.php            ← ตั้งรหัสผ่านใหม่
├── bootstrap.php                 ← ⚠️ จุดเริ่มต้น (โหลดทุกอย่าง)
│
├── admin/                        ← 🔒 หน้า admin ทั้งหมด
│   ├── index.php                 ← Dashboard
│   ├── books.php                 ← จัดการหนังสือ
│   ├── book_form.php             ← ฟอร์มเพิ่ม/แก้หนังสือ
│   ├── book_labels.php           ← พิมพ์ barcode label
│   ├── borrows.php               ← จัดการยืม-คืน
│   ├── borrow_form.php           ← ฟอร์มยืมหนังสือ
│   ├── members.php               ← จัดการสมาชิก
│   ├── member_form.php           ← ฟอร์มเพิ่ม/แก้สมาชิก
│   ├── member_card.php           ← พิมพ์บัตรสมาชิก
│   ├── categories.php            ← จัดการหมวดหมู่
│   ├── payments.php              ← จัดการค่าปรับ
│   ├── reservations.php          ← จัดการการจอง
│   ├── reports.php               ← รายงาน
│   ├── export_pdf.php            ← ส่งออก PDF
│   ├── import_books.php          ← นำเข้าหนังสือจาก CSV
│   ├── import_members.php        ← นำเข้าสมาชิกจาก CSV
│   ├── settings.php              ← ⚙️ ตั้งค่าระบบ
│   ├── header.php                ← 🎨 Layout admin (navbar + sidebar)
│   └── footer.php                ← ปิด layout admin
│
├── includes/                     ← ไฟล์ shared ใช้ร่วมกัน
│   ├── config.php                ← ⚙️ อ่าน .env → constants
│   ├── db.php                    ← เชื่อมต่อฐานข้อมูล
│   ├── functions.php             ← ⚠️ ฟังก์ชันช่วย + security
│   ├── header.php                ← 🎨 Layout public (navbar)
│   ├── footer.php                ← ปิด layout public
│   ├── book_grid.php             ← 🎨 การ์ดหนังสือ (ใช้ซ้ำ)
│   ├── modal.js                  ← JavaScript modal
│   └── report_helper.php         ← ช่วยสร้างรายงาน
│
├── app/Services/                 ← ⚙️ Business Logic
│   ├── AuthService.php           ← Login / Register / Password
│   ├── BookService.php           ← CRUD หนังสือ + stock
│   ├── BorrowService.php         ← ยืม / คืน / ค่าปรับ
│   ├── MemberService.php         ← CRUD สมาชิก
│   ├── DashboardService.php      ← ข้อมูล dashboard
│   ├── HomeService.php           ← ข้อมูลหน้าแรก
│   ├── ReportService.php         ← สร้างรายงาน
│   └── ReservationService.php    ← จอง / ยกเลิก / หมดอายุ
│
├── app/Repositories/             ← 🗄️ SQL Queries
│   ├── BookRepository.php
│   ├── BorrowRepository.php
│   ├── CategoryRepository.php
│   ├── PaymentRepository.php
│   ├── ReportRepository.php
│   ├── ReservationRepository.php
│   ├── SettingsRepository.php
│   ├── UserRepository.php
│   └── PasswordResetRepository.php
│
├── api/                          ← JSON API (AJAX)
│   ├── search_books.php          ← ค้นหาหนังสือ
│   ├── reserve_book.php          ← จองหนังสือ
│   ├── cancel_reservation.php    ← ยกเลิกการจอง
│   ├── add_member.php            ← เพิ่มสมาชิก (quick add)
│   └── member_history.php        ← ประวัติสมาชิก
│
├── css/
│   └── style.css                 ← 🎨 CSS เพิ่มเติม + animations
│
├── database/
│   ├── schema.sql                ← โครงสร้างตาราง
│   ├── sample_data.sql           ← ข้อมูลตัวอย่าง
│   └── migrations/               ← ปรับโครงสร้างเพิ่มเติม
│
├── uploads/                      ← ไฟล์ที่อัปโหลด (รูปปก)
│   └── covers/                   ← รูปปกหนังสือ
│
├── cron/                         ← งาน scheduled
├── logs/                         ← log files
└── tests/                        ← ไฟล์ทดสอบ
```

---

> 📌 **จำไว้:** Backup ก่อนแก้ทุกครั้ง | ทดสอบหลังแก้เสมอ | ไม่มั่นใจ = ถาม support
